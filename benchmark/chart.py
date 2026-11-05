"""Animated benchmark GIFs: process driver vs thread driver, time and peak memory, at each task count.

    python chart.py                       # both sets: results/<set>.jsonl -> ../art/benchmark-<set>.gif
    python chart.py small sqlite --cpus 5 # the same, explicitly

Also writes results/benchmark-<set>-last.png (the last frame) and, when a run crashed, results/benchmark-<set>-crash.png
(a frame mid-crash), for review. Warm-up rounds are left out. Bars show the median of the successful runs, thin
lines the fastest-slowest run; the headline is the guaranteed minimum speed-up (the process driver's fastest run against
the thread driver's slowest), with the typical one (medians) beside it. A driver that failed in any round at a task
count is drawn as a crash, never as a clean bar. Style: dark card, monospace, rounded bars (see README.md)."""
import argparse, json, os, statistics
from collections import Counter

import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import numpy as np
from matplotlib.patches import FancyBboxPatch, Polygon, Rectangle
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
SETS = {
    "small": "SMALL TASKS · SUM OF 1..1000 EACH",
    "sqlite": "SQLITE TASKS · 500 ORDERS EACH",
}

# Card colors from the reference chart; series colors from its gradient, darkened into the dark-mode lightness band
# (validated: dataviz validate_palette.js --mode dark --surface #0c0d12 -> all checks pass).
SURFACE, TRACK, DIVIDER = "#0c0d12", "#16181f", "#1f2937"
WHITE, INK, MUTED = "#ffffff", "#e5e7eb", "#6b7280"
COLOR = {"thread": "#d6781f", "process": "#a8279a"}
DRIVERS = ("thread", "process")

W, H, DPI, PAD = 960, 456, 100, 28
FPS = 20
TRACK_X0, TRACK_X1 = 112, W - PAD
BAR_H, ROW_GAP = 22, 8
PX = 72 / DPI   # font sizes are given in px
MONO = "DejaVu Sans Mono"
ADVANCE = 0.602  # DejaVu Sans Mono's advance width, in em


# ---------------------------------------------------------------- data

def median(values):
    values = [v for v in values if v is not None]
    return statistics.median(values) if values else None


def short_reason(reason):
    reason = reason or "failed"
    if reason.startswith("out of memory"):
        return "out of memory"
    if reason.startswith("child process timed out"):
        return "child timed out (60 s limit)"
    if reason.startswith("stopped after"):
        return "stopped after 15 min"
    if reason.startswith("wrong results"):
        return "wrong results"
    return reason.split(":")[0][:28]


def load(path):
    rows = [json.loads(line) for line in open(path) if line.strip()]
    warmups = len({r.get("round") for r in rows if r.get("warmup")})
    rows = [r for r in rows if not r.get("warmup")]   # warm-ups: kept in the file, left out of every number here
    rounds = max(r.get("round", 1) for r in rows)
    sizes = sorted({r["tasks"] for r in rows})
    cells = {}
    for n in sizes:
        for d in DRIVERS:
            mine = [r for r in rows if r["driver"] == d and r["tasks"] == n]
            ok = [r for r in mine if r.get("ok") is True]
            bad = [r for r in mine if r.get("ok") is not True]
            reasons = Counter(short_reason(r.get("reason")) for r in bad)
            if len(mine) < rounds:
                reasons["missing"] += rounds - len(mine)
            cells[(d, n)] = {
                "ok": len(ok), "rounds": rounds, "crashed": len(ok) < rounds, "reasons": reasons,
                "seconds": median(r["seconds"] for r in ok), "peak_mb": median(r["peak_mb"] for r in ok),
                "seconds_range": (min(r["seconds"] for r in ok), max(r["seconds"] for r in ok)) if ok else None,
                "peak_mb_range": (min(r["peak_mb"] for r in ok), max(r["peak_mb"] for r in ok)) if ok else None,
                "warm": median(r.get("warm_seconds") for r in ok),
                # How far the failed runs got: run() time when bench.php caught the failure, else the whole call.
                "crash_seconds": median(r.get("failed_after_seconds") or r.get("wall_seconds") for r in bad),
                "crash_peak_mb": median(r.get("peak_mb") for r in bad),
            }
    return sizes, rounds, warmups, cells


def fmt_time(s):
    return f"{s * 1000:,.0f} ms" if s < 1 else f"{s:,.1f} s"


def fmt_mem(mb):
    return f"{mb:,.0f} MB"


def fmt_range(lo, hi, fmt):
    """"15.7–53.4 s": both ends in the unit of the upper one."""
    if fmt is fmt_mem:
        return f"{lo:,.0f}–{hi:,.0f} MB"
    return f"{lo * 1000:,.0f}–{hi * 1000:,.0f} ms" if hi < 1 else f"{lo:,.1f}–{hi:,.1f} s"


def fmt_ratio(r):
    return f"{r:,.0f}×" if r >= 10 else f"{r:.1f}×"


# ---------------------------------------------------------------- easing / drawing helpers

def clamp(x):
    return max(0.0, min(1.0, x))


def ease_out(p):   # ease-out cubic, as in the reference
    p = clamp(p)
    return 1 - (1 - p) ** 3


def text(ax, x, y, s, size, color=INK, weight="normal", alpha=1.0, ha="left", va="baseline"):
    if alpha <= 0.001:
        return
    ax.text(x, y, s, fontsize=size * PX, color=color, weight=weight, alpha=clamp(alpha), ha=ha, va=va,
            family=MONO)


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


# ---------------------------------------------------------------- one chart

class Chart:
    def __init__(self, set_name, sizes, rounds, warmups, cells, cpus):
        self.set, self.sizes, self.rounds, self.warmups, self.cells, self.cpus = set_name, sizes, rounds, warmups, cells, cpus
        self.fig = plt.figure(figsize=(W / DPI, H / DPI), dpi=DPI, facecolor=SURFACE)
        self.ax = self.fig.add_axes([0, 0, 1, 1])
        # Scene lengths (s): a scene with a crash holds longer, so the crash can play out and be read.
        self.scenes = []
        t = 0.0
        for n in sizes:
            crash = any(cells[(d, n)]["crashed"] for d in DRIVERS)
            length = 5.0 if crash else 3.8
            self.scenes.append((n, t, length, crash))
            t += length
        self.duration = t
        rng = np.random.default_rng(7)
        self.shards = [(rng.uniform(40, 150), rng.uniform(-70, -10), rng.uniform(-540, 540), rng.uniform(2.5, 5.5),
                        rng.uniform(2, BAR_H - 4)) for _ in range(7)]

    # Panels: (key, heading, value key, crash key, formatter, top y)
    PANELS = (
        ("time", "TIME TO RUN ALL TASKS · LOWER IS BETTER", "seconds", "crash_seconds", fmt_time, 124),
        ("memory", "PEAK MEMORY, ALL PHP PROCESSES · LOWER IS BETTER", "peak_mb", "crash_peak_mb", fmt_mem, 236),
    )

    def scale(self, n, key, crash_key):
        """px per unit for one panel of one scene: the slowest clean run (whisker end) reaches 70% of the track, a crash
        point 34%."""
        track = TRACK_X1 - TRACK_X0
        limits = []
        for d in DRIVERS:
            c = self.cells[(d, n)]
            if c["crashed"]:
                v = c[crash_key] or c[key]
                if v:
                    limits.append(track * 0.34 / v)
            elif c[key]:
                limits.append(track * 0.70 / c[key + "_range"][1])
        return min(limits) if limits else 1.0

    def crash_point(self, n, d, key, crash_key, scale):
        c = self.cells[(d, n)]
        v = c[crash_key] or c[key]
        return (v * scale) if v else (TRACK_X1 - TRACK_X0) * 0.34

    def draw(self, t, crash_probe=None):
        ax = self.ax
        ax.cla()
        ax.set_xlim(0, W)
        ax.set_ylim(H, 0)
        ax.axis("off")
        ax.add_patch(Rectangle((0, 0), W, H, facecolor=SURFACE, edgecolor="none", zorder=0))

        idx = max(i for i, s in enumerate(self.scenes) if s[1] <= t + 1e-9)
        n, start, length, crash = self.scenes[idx]
        st = t - start                                   # time inside the scene
        last = idx == len(self.scenes) - 1
        fade = 1.0 if last else clamp((length - st) / 0.3)   # fade out before the next scene
        fade *= clamp(st / 0.25)                             # and in
        cells = self.cells

        # Label row: what the tasks are, and which task count is showing.
        tracked(ax, PAD, 38, SETS[self.set], 11)
        steps = [str(s) for s in self.sizes]
        right = " · ".join(steps) + " TASKS"
        x = W - PAD - tracked_width(right, 11)
        for i, s in enumerate(steps):
            x = tracked(ax, x, 38, s, 11, color=WHITE if self.sizes[i] == n else MUTED)
            x = tracked(ax, x, 38, " · " if i < len(steps) - 1 else " TASKS", 11)

        # Headline: a big white number counting up (ease-out cubic), with a muted note beside it.
        p = ease_out((st - 0.15) / 1.9)
        pc, tc = cells[("process", n)], cells[("thread", n)]
        if not pc["crashed"] and not tc["crashed"]:
            # The guaranteed minimum: the process driver's fastest run against the thread driver's slowest.
            least = pc["seconds_range"][0] / tc["seconds_range"][1]
            big, final = "≥ " + fmt_ratio(least * p) + " faster", "≥ " + fmt_ratio(least) + " faster"
            mem_least = pc["peak_mb_range"][0] / tc["peak_mb_range"][1]
            note = (f"typically ~{fmt_ratio(pc['seconds'] / tc['seconds'])} · memory: ≥ {fmt_ratio(mem_least)} less,"
                    f" typically ~{fmt_ratio(pc['peak_mb'] / tc['peak_mb'])}")
        elif not tc["crashed"]:
            big, final = fmt_time(tc["seconds"] * p), fmt_time(tc["seconds"])
            note = f"threads, all {n} tasks · the process driver crashed in {pc['rounds'] - pc['ok']}/{pc['rounds']} runs"
        else:
            big, note = f"{n} tasks", f"thread driver failed in {tc['rounds'] - tc['ok']}/{tc['rounds']} runs"
            final = big
        text(ax, PAD, 86, big, 30, WHITE, weight="bold", alpha=fade)
        text(ax, PAD + max(len(big), len(final)) * 30 * ADVANCE + 14, 86, note, 12, MUTED, alpha=fade)

        # Legend: swatch + muted text, right-aligned with the headline.
        lx = W - PAD
        for d in reversed(DRIVERS):
            lx -= len(d) * 11 * ADVANCE
            text(ax, lx, 85, d, 11, MUTED)
            lx -= 16
            rounded(ax, lx, 76, 10, 10, COLOR[d], r=2)
            lx -= 18

        # Panels: time, then memory, each on its own scale, bars growing left to right.
        for pi, (_, heading, key, crash_key, fmt, top) in enumerate(self.PANELS):
            tracked(ax, PAD, top, heading, 11)
            scale = self.scale(n, key, crash_key)
            t0 = 0.15 + pi * 0.25
            for ri, d in enumerate(DRIVERS):
                y = top + 12 + ri * (BAR_H + ROW_GAP)
                self.row(ax, n, d, y, st - t0, key, crash_key, fmt, scale, fade)

        # Footer under a thin divider.
        fy = H - 94
        ax.plot([0, W], [fy, fy], color=DIVIDER, linewidth=1, zorder=1)
        warm = f"{self.warmups} warm-up round{'s' if self.warmups != 1 else ''} discarded · " if self.warmups else ""
        caps = (
            f"Linux, PHP 8.4 ZTS + ext-parallel, Docker ({self.cpus} CPUs, 6 GB cap) · thread: 4 workers, first call"
            " incl. booting them",
            f"process: Laravel's driver, opcache off (PHP's default) · {warm}median of {self.rounds} runs",
            "bars show median, lines show fastest–slowest · ≥ = process driver's fastest run ÷ threads' slowest",
        )
        for i, cap in enumerate(caps):
            text(ax, PAD, fy + 26 + 20 * i, cap, 12, MUTED)
        if crash_probe is not None:
            crash_probe.append((n, crash))

    def row(self, ax, n, d, y, st, key, crash_key, fmt, scale, fade):
        c = self.cells[(d, n)]
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
            sweep = ease_out((st - 1.45) / 0.8)   # starts once the bar has grown
            mid, cy = TRACK_X0 + c[key] * scale, y + BAR_H / 2
            x_lo, x_hi = mid + (TRACK_X0 + lo * scale - mid) * sweep, mid + (TRACK_X0 + hi * scale - mid) * sweep
            spread = fmt_range(lo, hi, fmt) != fmt_range(lo, lo, fmt)   # every run the same: no whisker, no range
            if sweep > 0 and spread:
                wa = fade * clamp(sweep * 3) * 0.9
                ax.plot([x_lo, x_hi], [cy, cy], color=INK, linewidth=1.3, alpha=wa, zorder=3, solid_capstyle="butt")
                for xe in (x_lo, x_hi):
                    ax.plot([xe, xe], [cy - 5, cy + 5], color=INK, linewidth=1.3, alpha=wa, zorder=3, solid_capstyle="butt")
            la = fade * clamp((st - 1.45) / 0.5)
            lx = max(TRACK_X0 + w, TRACK_X0 + hi * scale) + 10
            value = fmt(c[key])
            text(ax, lx, cy + 4, value, 12, INK, alpha=la)
            if spread:
                text(ax, lx + len(value) * 12 * ADVANCE + 8, cy + 4, fmt_range(lo, hi, fmt), 11, MUTED, alpha=la)
            return

        full = self.crash_point(n, d, key, crash_key, scale)
        if hit <= 0:   # still growing, steadily (no ease-out: it doesn't settle, it gets cut off)
            w = full * clamp(st / grow) ** 1.15
            rounded(ax, TRACK_X0, y, w, BAR_H, COLOR[d], alpha=fade, square_left=True)
            return

        x1 = TRACK_X0 + full + shake
        # The broken stub: solid fades into a dim fill with 45° stripes, the end jagged.
        stub = jagged_stub(TRACK_X0 + shake, x1, y, BAR_H)
        solid = 1 - clamp(hit / 0.45)
        ax.add_patch(Polygon(stub, closed=True, facecolor=COLOR[d], edgecolor="none", alpha=fade * (0.30 + 0.70 * solid),
                             zorder=2))
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
        # A spark at the moment of impact.
        if hit < 0.22:
            k = hit / 0.22
            ax.plot([x1 + 2, x1 + 2], [y - 7 * k - 1, y + BAR_H + 7 * k + 1], color=WHITE, linewidth=3 * (1 - k) + 0.5,
                    alpha=0.9 * (1 - k), zorder=5, solid_capstyle="round")
        # Shards fly off the broken end and fall away.
        if hit < 0.7:
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
            what = f"crashed · {next(iter(reasons))} ({failed}/{rounds} runs)"
        else:
            what = "crashed · " + " · ".join(f"{r} {k}/{rounds}" for r, k in reasons.most_common())
        text(ax, lx, y + BAR_H / 2 + 4, what, 12, WHITE, weight="bold", alpha=la)
        note = []
        if c[crash_key] is not None:
            note.append(("failed runs stopped after " if key == "seconds" else "failed runs peaked at ") + fmt(c[crash_key]))
        note.append(f"{c['ok']}/{rounds} runs finished: {fmt(c[key])}" if c["ok"] else f"0/{rounds} runs finished")
        note = " · ".join(note)
        text(ax, lx, y + BAR_H + 13, note, 11, MUTED, alpha=la)

    # ------------------------------------------------------------ output

    def frame(self, t):
        self.draw(t)
        self.fig.canvas.draw()
        return Image.fromarray(np.asarray(self.fig.canvas.buffer_rgba())[:, :, :3].copy())

    def crash_time(self):
        for n, start, length, crash in self.scenes:
            if crash:
                return start + 0.4 + 1.25 + 0.12   # just after impact, shards in the air
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
        probe = Image.new("RGB", (W, H * 2))
        probe.paste(final, (0, 0))
        probe.paste(self.frame(ct if ct is not None else self.duration / 2), (0, H))
        palette = probe.quantize(colors=96, method=Image.Quantize.MEDIANCUT)
        quantized = [f.quantize(palette=palette, dither=Image.Dither.NONE) for f in frames]
        durations = [1000 // FPS] * len(quantized)
        durations[-1] = 2600   # hold the last frame before looping
        quantized[0].save(gif, save_all=True, append_images=quantized[1:], duration=durations, loop=0, optimize=False,
                          disposal=1)
        plt.close(self.fig)
        return os.path.getsize(gif)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("sets", nargs="*", default=list(SETS))
    parser.add_argument("--cpus", type=int, default=5, help="CPUs Docker had during the run (for the caption)")
    parser.add_argument("--results", default=os.path.join(HERE, "results"))
    parser.add_argument("--art", default=os.path.join(HERE, "..", "art"))
    args = parser.parse_args()
    plt.rcParams.update({"font.family": MONO})
    for s in args.sets:
        sizes, rounds, warmups, cells = load(os.path.join(args.results, f"{s}.jsonl"))
        gif = os.path.join(args.art, f"benchmark-{s}.gif")
        size = Chart(s, sizes, rounds, warmups, cells, args.cpus).save(
            gif, os.path.join(args.results, f"benchmark-{s}-last.png"), os.path.join(args.results, f"benchmark-{s}-crash.png"))
        print(f"{gif}: {size / 1024:,.0f} KB")


if __name__ == "__main__":
    main()
