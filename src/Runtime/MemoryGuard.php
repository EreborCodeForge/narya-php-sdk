<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

final readonly class MemoryGuard
{
    public function __construct(
        private WorkerOptions $options
    ) {
    }

    public function shouldRecycle(): bool
    {
        if (!$this->options->shouldCheckMemory()) {
            return false;
        }

        return memory_get_usage(true) >= $this->options->memoryLimitBytes();
    }

    public function usage(): int
    {
        return memory_get_usage(true);
    }

    public function peak(): int
    {
        return memory_get_peak_usage(true);
    }
}
