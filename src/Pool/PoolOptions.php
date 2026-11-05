<?php

namespace Omaresmael\Thread\Pool;

use Illuminate\Contracts\Config\Repository;
use Omaresmael\Thread\Worker\Bootstrap;

final readonly class PoolOptions
{
    public function __construct(
        public int $maxWorkers,
        public ?int $maxTasks,
        public ?int $maxMemoryMb,
        public string $autoload,
        public string $appFile,
    ) {}

    public static function fromConfig(Repository $config, string $appFile): self
    {
        return new self(
            maxWorkers: max(1, (int) $config->get('thread.max_workers', 4)),
            maxTasks: self::nullableInt($config->get('thread.max_tasks', 500)),
            maxMemoryMb: self::nullableInt($config->get('thread.max_memory', 128)),
            autoload: Bootstrap::autoloadPath(),
            appFile: $appFile,
        );
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
