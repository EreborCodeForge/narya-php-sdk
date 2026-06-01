<?php

declare(strict_types=1);

namespace Narya\SDK\Lifecycle;

use Narya\SDK\Contracts\LifecycleInterface;
use Narya\SDK\Contracts\NaryaRequest;
use Narya\SDK\Contracts\NaryaResponse;
use Narya\SDK\Contracts\RequestLifecycleInterface;

/**
 * Manages worker lifecycle (boot after handshake, shutdown on exit) and optional per-request hooks.
 */
final class LifecycleManager implements RequestLifecycleInterface
{
    /** @var list<callable(): void> */
    private array $bootCallbacks = [];

    /** @var list<callable(): void> */
    private array $shutdownCallbacks = [];

    /** @var list<callable(NaryaRequest): void> */
    private array $beforeRequestCallbacks = [];

    /** @var list<callable(NaryaRequest, array|NaryaResponse|null, ?\Throwable): void> */
    private array $afterRequestCallbacks = [];

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

    public function beforeRequest(NaryaRequest $request): void
    {
        foreach ($this->beforeRequestCallbacks as $cb) {
            $cb($request);
        }
    }

    public function afterRequest(NaryaRequest $request, array|NaryaResponse|null $response, ?\Throwable $error): void
    {
        foreach ($this->afterRequestCallbacks as $cb) {
            $cb($request, $response, $error);
        }
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

    public function onBeforeRequest(callable $callback): self
    {
        $this->beforeRequestCallbacks[] = $callback;
        return $this;
    }

    public function onAfterRequest(callable $callback): self
    {
        $this->afterRequestCallbacks[] = $callback;
        return $this;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }
}
