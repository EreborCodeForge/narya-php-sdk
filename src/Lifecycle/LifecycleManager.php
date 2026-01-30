<?php

declare(strict_types=1);

namespace Narya\SDK\Lifecycle;

use Narya\SDK\Contracts\LifecycleInterface;

/**
 * Manages worker lifecycle (boot, shutdown).
 * Useful for frameworks to register callbacks before/after the loop.
 */
final class LifecycleManager implements LifecycleInterface
{
    /** @var list<callable(): void> */
    private array $bootCallbacks = [];

    /** @var list<callable(): void> */
    private array $shutdownCallbacks = [];

    private bool $booted = false;

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        foreach ($this->bootCallbacks as $cb) {
            $cb();
        }
        $this->booted = true;
    }

    public function shutdown(): void
    {
        foreach ($this->shutdownCallbacks as $cb) {
            $cb();
        }
        $this->booted = false;
    }

    public function onBoot(callable $callback): self
    {
        $this->bootCallbacks[] = $callback;
        return $this;
    }

    public function onShutdown(callable $callback): self
    {
        $this->shutdownCallbacks[] = $callback;
        return $this;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }
}
