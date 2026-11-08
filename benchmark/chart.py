"""Animated benchmark GIFs: the thread driver against Laravel's sync and process drivers, one GIF per flow.

    python chart.py                  # all three: results/<flow>.jsonl -> ../art/benchmark-<flow>.gif
    python chart.py cli --cpus 5     # one flow (cli, web-classic, web-worker)

One scene per task count. The CLI GIF shows the time of one run() call and the peak memory; the web GIFs show the p50
latency with 1 client and the requests per second with 10 and 50 clients (p95 beside each value). Bars show the median of
the successful runs, thin lines the fastest-slowest run; the headline is the guaranteed minimum (the other driver's best
run against the thread driver's worst), with the typical ratio (medians) beside it. A driver that failed in any round of a
cell is drawn as a crash, never as a clean bar. Warm-up rounds are left out. Also writes results/benchmark-<flow>-last.png
(the last frame) and, when something crashed, results/benchmark-<flow>-crash.png, for review."""
import argparse, json, os, statistics
from collections import Counter

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import numpy as np
from matplotlib.patches import FancyBboxPatch, Polygon, Rectangle
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))

# Card colors from the reference chart; thread and process from its gradient, darkened into the dark-mode lightness band;
# sync is the reference palette's dark-mode blue. Validated together: dataviz validate_palette.js --mode dark
# --surface #0c0d12 --pairs all -> all checks pass (worst CVD ΔE 12.2, normal-vision ΔE 25.3).
SURFACE, TRACK, DIVIDER = "#0c0d12", "#16181f", "#1f2937"
WHITE, INK, MUTED = "#ffffff", "#e5e7eb", "#6b7280"
COLOR = {"thread": "#d6781f", "process": "#a8279a", "sync": "#3987e5"}
DRIVERS = ("thread", "process", "sync")

W, DPI, PAD = 960, 100, 28
FPS = 20
TRACK_X0, TRACK_X1 = 112, W - PAD
BAR_H, ROW_GAP = 22, 8
PX = 72 / DPI   # font sizes are given in px
MONO = "DejaVu Sans Mono"
ADVANCE = 0.602  # DejaVu Sans Mono's advance width, in em


# ---------------------------------------------------------------- formatting

def fmt_time(s):
    return f"{s * 1000:,.0f} ms" if s < 1 else f"{s:,.2f} s" if s < 10 else f"{s:,.1f} s"


def fmt_ms(v):
    return fmt_time(v / 1000)


def fmt_mem(mb):
    return f"{mb:,.0f} MB"


def fmt_rps(v):
    return f"{v:,.1f} req/s" if v < 10 else f"{v:,.0f} req/s"


def fmt_range(lo, hi, fmt):
    """"1.0–1.2 s": one unit when both ends share it."""
    a, b = fmt(lo).rsplit(" ", 1), fmt(hi).rsplit(" ", 1)
    if a == b:
        return fmt(lo)
    return f"{a[0]}–{b[0]} {b[1]}" if a[1] == b[1] else f"{fmt(lo)}–{fmt(hi)}"


def fmt_ratio(r):
    return f"{r:,.0f}×" if r >= 10 else f"{r:.1f}×"


def short_reason(reason):
    reason = reason or "failed"
    for prefix, short in (("out of memory", "out of memory"), ("child process timed out", "child timed out (60 s)"),
                          ("stopped after", "stopped after 15 min"), ("wrong results", "wrong results"),
                          ("server", "server crashed"), ("load client failed", "load client failed")):
        if reason.startswith(prefix):
            return short
    if "requests failed" in reason:
        return "requests failed"
    return reason.split(":")[0][:28]


# ---------------------------------------------------------------- data

def median(values):
    values = [v for v in values if v is not None]
    return statistics.median(values) if values else None


def load(path):
    rows = [json.loads(line) for line in open(path) if line.strip()]
    warmups = len({r.get("round") for r in rows if r.get("warmup")})
    rows = [r for r in rows if not r.get("warmup")]   # warm-ups: kept in the file, left out of every number here
    rounds = max(r.get("round", 1) for r in rows)
    keys = sorted({(r["tasks"], r.get("clients", 0)) for r in rows})
    cells = {}
    for key in keys:
        for d in DRIVERS:
            mine = [r for r in rows if r["driver"] == d and (r["tasks"], r.get("clients", 0)) == key]
            ok = [r for r in mine if r.get("ok") is True]
            bad = [r for r in mine if r.get("ok") is not True]
            reasons = Counter(short_reason(r.get("reason")) for r in bad)
            if mine and len(mine) < rounds:
                reasons["missing"] += rounds - len(mine)
            cell = {"ok": len(ok), "rounds": rounds, "crashed": len(ok) < rounds, "reasons": reasons,
                    "errors": sum(int(r.get("errors") or 0) for r in mine), "present": bool(mine)}
            for k in ("seconds", "peak_mb", "warm_seconds", "p50_ms", "p95_ms", "rps"):
                vals = [r.get(k) for r in ok if r.get(k) is not None]
                cell[k] = median(vals)
                cell[k + "_range"] = (min(vals), max(vals)) if vals else None
                # How far the failed runs got (run() time when the command caught the failure, else the whole call).
                fail = [r.get("failed_after_seconds") or r.get("wall_seconds") for r in bad] if k == "seconds" else [r.get(k) for r in bad]
                cell["crash_" + k] = median(fail)
            cells[(d,) + key] = cell
    return rows, rounds, warmups, cells


# ---------------------------------------------------------------- easing / drawing helpers

def clamp(x):
    return max(0.0, min(1.0, x))


def ease_out(p):   # ease-out cubic, as in the reference
    p = clamp(p)
    return 1 - (1 - p) ** 3


def text(ax, x, y, s, size, color=INK, weight="normal", alpha=1.0, ha="left", va="baseline"):
    if alpha <= 0.001:
        return
    ax.text(x, y, s, fontsize=size * PX, color=color, weight=weight, alpha=clamp(alpha), ha=ha, va=va, family=MONO)


def tracked_width(s, size, tracking=0.18):
    return len(s) * size * (ADVANCE + tracking)


def tracked(ax, x, y, s, size, color=MUTED, alpha=1.0, tracking=0.18):
    """Uppercase label with wide letter-spacing (CSS tracking-[0.18em]), one character at a time."""
    step = size * (ADVANCE + tracking)
    for i, ch in enumerate(s):
        if ch != " ":
            text(ax, x + i * step, y, ch, size, color, alpha=alpha)
    return x + len(s) * step


def rounded(ax, x, y, w, h, color, alpha=1.0, square_left=False, r=4.0, z=2):
    """A rounded rectangle (rx 4); square_left squares the baseline end of a bar."""
    if w <= 0.2 or alpha <= 0.001:
        return
    rr = min(r, w / 2, h / 2)
    ax.add_patch(FancyBboxPatch((x, y), w, h, boxstyle=f"round,pad=0,rounding_size={rr}", facecolor=color,
                                edgecolor="none", alpha=clamp(alpha), zorder=z))
    if square_left:
        ax.add_patch(Rectangle((x, y), min(rr, w / 2), h, facecolor=color, edgecolor="none", alpha=clamp(alpha), zorder=z))


def jagged_stub(x0, x1, y, h):
    """Polygon of a bar broken at x1: square baseline, jagged right edge."""
    zig = [(x1 - 1, y), (x1 + 4, y + h * 0.22), (x1 - 3, y + h * 0.45), (x1 + 3, y + h * 0.68), (x1 - 2, y + h * 0.86),
           (x1 + 1, y + h)]
    return [(x0, y)] + zig + [(x0, y + h)]


# ---------------------------------------------------------------- flows

class Panel:
    """One group of bars: one metric of one cell (task count, clients) per driver."""

    def __init__(self, heading, key, fmt, clients=0, lower=True, extra=None):
        self.heading, self.key, self.fmt, self.clients, self.lower, self.extra = heading, key, fmt, clients, lower, extra


FLOWS = {
    "cli": {
        "label": "COMMAND LINE · ONE run() CALL · REVENUE REPORT OVER 50,000 ORDERS PER TASK",
        "panels": [
            Panel("TIME TO RUN ALL TASKS · LOWER IS BETTER", "seconds", fmt_time),
            Panel("PEAK MEMORY, ALL PHP PROCESSES · LOWER IS BETTER", "peak_mb", fmt_mem),
        ],
        "headline": ("seconds", 0, True),
    },
    "web-classic": {
        "label": "FRANKENPHP CLASSIC MODE · ONE run() PER REQUEST · 50,000-ORDER REPORT PER TASK",
        "panels": [
            Panel("LATENCY, 1 CLIENT · MEDIAN (p50) · LOWER IS BETTER", "p50_ms", fmt_ms, 1),
            Panel("THROUGHPUT, 10 CLIENTS · HIGHER IS BETTER", "rps", fmt_rps, 10, lower=False, extra="p95_ms"),
            Panel("THROUGHPUT, 50 CLIENTS · HIGHER IS BETTER", "rps", fmt_rps, 50, lower=False, extra="p95_ms"),
        ],
        "headline": ("p50_ms", 1, True),
    },
}
FLOWS["web-worker"] = dict(FLOWS["web-classic"], label="OCTANE WORKER MODE (FRANKENPHP) · ONE run() PER REQUEST · 50,000-ORDER REPORT PER TASK")


def ratio_text(cells, other, key, tasks, clients, lower):
    """("≥ 2.4× faster", "typically 2.5×") for the thread driver against another driver, or None if a side failed."""
    t, o = cells.get(("thread", tasks, clients)), cells.get((other, tasks, clients))
    if not t or not o or t["crashed"] or o["crashed"] or not t[key] or not o[key]:
        return None
    (tlo, thi), (olo, ohi) = t[key + "_range"], o[key + "_range"]
    typical = o[key] / t[key] if lower else t[key] / o[key]
    least = olo / thi if lower else tlo / ohi
    most = ohi / tlo if lower else thi / olo
    if typical >= 1:
        return (least, typical, "faster") if least >= 1 else (None, typical, "faster")
    return (1 / most, 1 / typical, "slower") if most < 1 else (None, 1 / typical, "slower")


class Chart:
    def __init__(self, flow, rows, rounds, warmups, cells, cpus):
        self.flow, self.spec, self.rounds, self.warmups, self.cells, self.cpus = flow, FLOWS[flow], rounds, warmups, cells, cpus
        self.sizes = sorted({k[1] for k in cells})
        self.web = flow != "cli"
        self.panels = self.spec["panels"]
        self.panel_h = 34 + len(DRIVERS) * (BAR_H + ROW_GAP) + 24
        self.top = 124
        self.H = self.top + len(self.panels) * self.panel_h + 94 - 12
        self.fig = plt.figure(figsize=(W / DPI, self.H / DPI), dpi=DPI, facecolor=SURFACE)
        self.ax = self.fig.add_axes([0, 0, 1, 1])
        # Scene lengths (s): a scene with a crash holds longer, so the crash can play out and be read.
        self.scenes, t = [], 0.0
        for n in self.sizes:
            crash = any(self.cell(d, n, p.clients)["crashed"] for d in DRIVERS for p in self.panels)
            length = 5.4 if crash else 4.2
            self.scenes.append((n, t, length, crash))
            t += length
        self.duration = t
        rng = np.random.default_rng(7)
        self.shards = [(rng.uniform(40, 150), rng.uniform(-70, -10), rng.uniform(-540, 540), rng.uniform(2.5, 5.5),
                        rng.uniform(2, BAR_H - 4)) for _ in range(7)]

    def cell(self, d, n, clients):
        return self.cells.get((d, n, clients)) or {"crashed": True, "ok": 0, "rounds": self.rounds, "reasons": Counter({"not run": 1}),
                                                     "errors": 0, "present": False}

    def scale(self, n, panel):
        """px per unit for one panel of one scene: the largest clean value (whisker end) reaches 70% of the track, a crash
        point 34%."""
        track = TRACK_X1 - TRACK_X0
        limits = []
        for d in DRIVERS:
            c = self.cell(d, n, panel.clients)
            if c["crashed"]:
                v = c.get("crash_" + panel.key) or c.get(panel.key)
                if v:
                    limits.append(track * 0.34 / v)
            elif c[panel.key]:
                limits.append(track * 0.70 / c[panel.key + "_range"][1])
        return min(limits) if limits else 1.0

    def draw(self, t):
        ax, H = self.ax, self.H
        ax.cla()
        ax.set_xlim(0, W)
        ax.set_ylim(H, 0)
        ax.axis("off")
        ax.add_patch(Rectangle((0, 0), W, H, facecolor=SURFACE, edgecolor="none", zorder=0))

        idx = max(i for i, s in enumerate(self.scenes) if s[1] <= t + 1e-9)
        n, start, length, crash = self.scenes[idx]
        st = t - start
        last = idx == len(self.scenes) - 1
        fade = (1.0 if last else clamp((length - st) / 0.3)) * clamp(st / 0.25)

        # Label row: what is measured, and which task count is showing.
        tracked(ax, PAD, 38, self.spec["label"], 10)
        steps = [str(s) for s in self.sizes]
        right = " · ".join(steps) + " TASKS"
        x = W - PAD - tracked_width(right, 11)
        for i, s in enumerate(steps):
            x = tracked(ax, x, 60, s, 11, color=WHITE if self.sizes[i] == n else MUTED)
            x = tracked(ax, x, 60, " · " if i < len(steps) - 1 else " TASKS", 11)

        # Headline: the thread driver against the process driver (a big number counting up), then against sync.
        key, clients, lower = self.spec["headline"]
        p = ease_out((st - 0.15) / 1.9)
        where = " with 1 client" if self.web else ""
        vs_p = ratio_text(self.cells, "process", key, n, clients, lower)
        vs_s = ratio_text(self.cells, "sync", key, n, clients, lower)
        notes = []
        if vs_p and vs_p[0] is not None:
            least, typical, word = vs_p
            big, final = f"≥ {fmt_ratio(max(1.0, least * p) if word == 'faster' else least)} {word}", f"≥ {fmt_ratio(least)} {word}"
            notes.append(f"than process{where} (typically {fmt_ratio(typical)})")
        elif vs_p:
            big = final = f"~{fmt_ratio(vs_p[1])} {vs_p[2]}"
            notes.append(f"than process{where}, typically; not in every run")
        else:
            tc = self.cell("thread", n, clients)
            big = final = f"{n} tasks"
            notes.append("a driver failed in this cell" if not tc["crashed"] else "the thread driver failed in this cell")
        if vs_s:
            least, typical, word = vs_s
            notes.append(f"vs sync: {'≥ ' + fmt_ratio(least) if least else '~' + fmt_ratio(typical)} {word}"
                         + (f" (typically {fmt_ratio(typical)})" if least else ", not in every run"))
        text(ax, PAD, 98, big, 28, WHITE, weight="bold", alpha=fade)
        nx = PAD + max(len(big), len(final)) * 28 * ADVANCE + 14
        text(ax, nx, 86, notes[0], 12, MUTED, alpha=fade)
        if len(notes) > 1:
            text(ax, nx, 103, notes[1], 12, MUTED, alpha=fade)

        # Legend: swatch + muted text, top right.
        lx = W - PAD
        for d in reversed(DRIVERS):
            lx -= len(d) * 11 * ADVANCE
            text(ax, lx, 34, d, 11, MUTED)
            lx -= 16
            rounded(ax, lx, 25, 10, 10, COLOR[d], r=2)
            lx -= 18

        for pi, panel in enumerate(self.panels):
            top = self.top + 18 + pi * self.panel_h
            tracked(ax, PAD, top, panel.heading, 11)
            scale = self.scale(n, panel)
            t0 = 0.15 + pi * 0.25
            for ri, d in enumerate(DRIVERS):
                y = top + 12 + ri * (BAR_H + ROW_GAP)
                self.row(ax, n, d, y, st - t0, panel, scale, fade)

        # Footer under a thin divider.
        fy = H - 82
        ax.plot([0, W], [fy, fy], color=DIVIDER, linewidth=1, zorder=1)
        warm = f"{self.warmups} warm-up round{'s' if self.warmups != 1 else ''} discarded · " if self.warmups else ""
        if self.web:
            mode = ("FrankenPHP 1.13 classic mode (php_server), 10 PHP threads" if self.flow == "web-classic"
                    else "Octane on FrankenPHP 1.13, 10 workers, max 500 requests each")
            caps = (f"{mode} · PHP 8.4 ZTS · Docker, {self.cpus} CPUs · thread: 4 workers per pool",
                    f"30 / 100 / 200 requests at 1 / 10 / 50 clients · {warm}median of {self.rounds} runs, lines: fastest–slowest",
                    "≥ = the other driver's best run ÷ the thread driver's worst · process: Laravel's driver, CLI children")
        else:
            caps = (f"Linux, PHP 8.4 ZTS + ext-parallel, Docker ({self.cpus} CPUs, 5 GB cap) · fresh PHP process per run;"
                    " thread: 4 workers, incl. booting them",
                    f"sync: tasks inline, one after another · process: Laravel's driver, opcache off (PHP's default) · {warm}median of {self.rounds}",
                    "bars show median, lines show fastest–slowest · ≥ = the other driver's best run ÷ the thread driver's worst")
        for i, cap in enumerate(caps):
            text(ax, PAD, fy + 22 + 19 * i, cap, 12 if len(cap) <= 128 else 11, MUTED)

    def row(self, ax, n, d, y, st, panel, scale, fade):
        c = self.cell(d, n, panel.clients)
        key, fmt = panel.key, panel.fmt
        text(ax, PAD, y + BAR_H / 2 + 4, d, 12, MUTED)
        shake = 0.0
        if c["crashed"]:
            grow, hit = 1.25, max(0.0, st - 1.25)        # it grows steadily for 1.25 s, then hits the wall
            if 0 < hit < 0.5:
                shake = 5 * np.sin(2 * np.pi * 17 * hit) * (1 - hit / 0.5) ** 2
        rounded(ax, TRACK_X0 + shake, y, TRACK_X1 - TRACK_X0, BAR_H, TRACK, z=1)
        if st <= 0:
            return
        if not c["crashed"]:
            e = ease_out(st / 1.8)
            w = max(3.0, c[key] * scale) * e
            rounded(ax, TRACK_X0, y, w, BAR_H, COLOR[d], alpha=fade, square_left=True)
            # The fastest–slowest whisker sweeps out from the median once the bar has nearly grown.
            lo, hi = c[key + "_range"]
            sweep = ease_out((st - 1.45) / 0.8)
            mid, cy = TRACK_X0 + c[key] * scale, y + BAR_H / 2
            x_lo, x_hi = mid + (TRACK_X0 + lo * scale - mid) * sweep, mid + (TRACK_X0 + hi * scale - mid) * sweep
            spread = fmt_range(lo, hi, fmt) != fmt(lo)
            if sweep > 0 and spread:
                wa = fade * clamp(sweep * 3) * 0.9
                ax.plot([x_lo, x_hi], [cy, cy], color=INK, linewidth=1.3, alpha=wa, zorder=3, solid_capstyle="butt")
                for xe in (x_lo, x_hi):
                    ax.plot([xe, xe], [cy - 5, cy + 5], color=INK, linewidth=1.3, alpha=wa, zorder=3, solid_capstyle="butt")
            la = fade * clamp((st - 1.45) / 0.5)
            lx = max(TRACK_X0 + w, TRACK_X0 + hi * scale) + 10
            value = fmt(c[key])
            text(ax, lx, cy + 4, value, 12, INK, alpha=la)
            note = fmt_range(lo, hi, fmt) if spread else ""
            if panel.extra and c.get(panel.extra) is not None:
                note = (note + " · " if note else "") + f"p95 {fmt_ms(c[panel.extra])}"
            if note:
                text(ax, lx + len(value) * 12 * ADVANCE + 8, cy + 4, note, 11, MUTED, alpha=la)
            return

        v = c.get("crash_" + key) or c.get(key)
        full = v * scale if v else (TRACK_X1 - TRACK_X0) * 0.34
        if hit <= 0:   # still growing, steadily (no ease-out: it doesn't settle, it gets cut off)
            rounded(ax, TRACK_X0, y, full * clamp(st / grow) ** 1.15, BAR_H, COLOR[d], alpha=fade, square_left=True)
            return

        x1 = TRACK_X0 + full + shake
        # The broken stub: solid fades into a dim fill with 45° stripes, the end jagged.
        stub = jagged_stub(TRACK_X0 + shake, x1, y, BAR_H)
        solid = 1 - clamp(hit / 0.45)
        ax.add_patch(Polygon(stub, closed=True, facecolor=COLOR[d], edgecolor="none", alpha=fade * (0.30 + 0.70 * solid), zorder=2))
        clip = Polygon(stub, closed=True, facecolor="none", edgecolor="none")
        ax.add_patch(clip)
        for sx in np.arange(TRACK_X0 - BAR_H, x1 + 8, 7):
            line, = ax.plot([sx, sx + BAR_H], [y + BAR_H, y], color=COLOR[d], linewidth=1.6,
                            alpha=fade * (1 - solid), zorder=3, solid_capstyle="butt")
            line.set_clip_path(clip)
        # A crack in the surface color, near the break.
        cx = x1 - 14
        ax.plot([cx, cx + 5, cx - 1, cx + 4, cx], [y, y + 6, y + 11, y + 16, y + BAR_H], color=SURFACE,
                linewidth=1.8, alpha=fade * clamp(hit / 0.1), zorder=4)
        if hit < 0.22:   # a spark at the moment of impact
            k = hit / 0.22
            ax.plot([x1 + 2, x1 + 2], [y - 7 * k - 1, y + BAR_H + 7 * k + 1], color=WHITE, linewidth=3 * (1 - k) + 0.5,
                    alpha=0.9 * (1 - k), zorder=5, solid_capstyle="round")
        if hit < 0.7:    # shards fly off the broken end and fall away
            for vx, vy, spin, size, oy in self.shards:
                k = hit
                sx, sy = x1 + vx * k, y + oy + vy * k + 200 * k * k
                a = np.deg2rad(spin * k)
                pts = np.array([[-size, -size * 0.6], [size, -size * 0.3], [size * 0.4, size * 0.7]])
                rot = np.array([[np.cos(a), -np.sin(a)], [np.sin(a), np.cos(a)]])
                ax.add_patch(Polygon(pts @ rot.T + [sx, sy], closed=True, facecolor=COLOR[d], edgecolor="none",
                                     alpha=fade * (1 - k / 0.7), zorder=4))
        # The label: what happened and how often; the successful median, if any, only in small muted text.
        la = fade * clamp((hit - 0.3) / 0.35)
        lx = TRACK_X0 + full + 16 - 6 * (1 - clamp((hit - 0.3) / 0.35))
        rounds, failed = c["rounds"], c["rounds"] - c["ok"]
        reasons = c["reasons"]
        if len(reasons) == 1:
            what = f"failed · {next(iter(reasons))} ({failed}/{rounds} runs)"
        else:
            what = "failed · " + " · ".join(f"{r} {k}/{rounds}" for r, k in reasons.most_common())
        text(ax, lx, y + BAR_H / 2 + 4, what, 12, WHITE, weight="bold", alpha=la)
        note = []
        if self.web and c.get("errors"):
            note.append(f"{c['errors']:,} failed requests")
        if c.get("crash_" + key) is not None:
            note.append(("failed runs: " if self.web else ("failed runs stopped after " if key == "seconds" else "failed runs peaked at "))
                        + fmt(c["crash_" + key]))
        note.append(f"{c['ok']}/{rounds} clean runs: {fmt(c[key])}" if c["ok"] and c.get(key) else f"0/{rounds} clean runs")
        text(ax, lx, y + BAR_H + 6, " · ".join(note), 11, MUTED, alpha=la)

    # ------------------------------------------------------------ output

    def frame(self, t):
        self.draw(t)
        self.fig.canvas.draw()
        return Image.fromarray(np.asarray(self.fig.canvas.buffer_rgba())[:, :, :3].copy())

    def crash_time(self):
        for n, start, length, crash in self.scenes:
            if crash:
                return start + 0.4 + 1.25 + 0.12 + 0.5   # just after impact, shards in the air
        return None

    def save(self, gif, last_png, crash_png):
        times = [i / FPS for i in range(int(round(self.duration * FPS)))]
        frames = [self.frame(t) for t in times]
        final = self.frame(self.duration - 1e-6)
        final.save(last_png)
        ct = self.crash_time()
        if ct is not None:
            self.frame(ct).save(crash_png)
        elif os.path.exists(crash_png):
            os.remove(crash_png)
        frames[-1] = final
        # One shared palette (from a mid-crash frame and the last frame), no dithering: flat colors, small deltas.
        probe = Image.new("RGB", (W, self.H * 2))
        probe.paste(final, (0, 0))
        probe.paste(self.frame(ct if ct is not None else self.duration / 2), (0, self.H))
        palette = probe.quantize(colors=96, method=Image.Quantize.MEDIANCUT)
        quantized = [f.quantize(palette=palette, dither=Image.Dither.NONE) for f in frames]
        durations = [1000 // FPS] * len(quantized)
        durations[-1] = 2600   # hold the last frame before looping
        quantized[0].save(gif, save_all=True, append_images=quantized[1:], duration=durations, loop=0, optimize=False, disposal=1)
        plt.close(self.fig)
        return os.path.getsize(gif)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("flows", nargs="*", default=list(FLOWS))
    parser.add_argument("--cpus", type=int, default=5, help="CPUs Docker had during the run (for the caption)")
    parser.add_argument("--results", default=os.path.join(HERE, "results"))
    parser.add_argument("--art", default=os.path.join(HERE, "..", "art"))
    args = parser.parse_args()
    plt.rcParams.update({"font.family": MONO})
    for flow in args.flows:
        rows, rounds, warmups, cells = load(os.path.join(args.results, f"{flow}.jsonl"))
        gif = os.path.join(args.art, f"benchmark-{flow}.gif")
        size = Chart(flow, rows, rounds, warmups, cells, args.cpus).save(
            gif, os.path.join(args.results, f"benchmark-{flow}-last.png"), os.path.join(args.results, f"benchmark-{flow}-crash.png"))
        print(f"{gif}: {size / 1024:,.0f} KB")


if __name__ == "__main__":
    main()
