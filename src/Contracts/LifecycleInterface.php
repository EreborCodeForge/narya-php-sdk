<?php

declare(strict_types=1);

namespace Narya\SDK\Contracts;

/**
 * Worker lifecycle contract (boot, shutdown).
 *
 * boot() runs after the UDS handshake with Go; shutdown() runs when the request loop exits.
 */
interface LifecycleInterface
{
    public function boot(): void;

    public function shutdown(): void;

    public function isBooted(): bool;
}
