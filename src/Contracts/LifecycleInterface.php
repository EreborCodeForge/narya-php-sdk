<?php

declare(strict_types=1);

namespace Narya\SDK\Contracts;

/**
 * Worker lifecycle contract (boot, shutdown).
 */
interface LifecycleInterface
{
    public function boot(): void;

    public function shutdown(): void;

    public function isBooted(): bool;
}
