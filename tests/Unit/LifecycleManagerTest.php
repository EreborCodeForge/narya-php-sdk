<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Unit;

use Narya\SDK\Contracts\NaryaRequest;
use Narya\SDK\Contracts\NaryaResponse;
use Narya\SDK\Lifecycle\LifecycleManager;
use Narya\SDK\Runtime\WorkerRequest;
use PHPUnit\Framework\TestCase;

final class LifecycleManagerTest extends TestCase
{
    public function test_boot_fires_callbacks_and_sets_booted(): void
    {
        $called = false;
        $lifecycle = new LifecycleManager();
        $lifecycle->onBoot(function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($lifecycle->isBooted());
        $lifecycle->boot();
        $this->assertTrue($called);
        $this->assertTrue($lifecycle->isBooted());
    }

    public function test_boot_idempotent_does_not_fire_twice(): void
    {
        $count = 0;
        $lifecycle = new LifecycleManager();
        $lifecycle->onBoot(function () use (&$count) {
            $count++;
        });

        $lifecycle->boot();
        $lifecycle->boot();
        $this->assertSame(1, $count);
    }

    public function test_shutdown_fires_callbacks(): void
    {
        $called = false;
        $lifecycle = new LifecycleManager();
        $lifecycle->onShutdown(function () use (&$called) {
            $called = true;
        });
        $lifecycle->boot();
        $lifecycle->shutdown();
        $this->assertTrue($called);
        $this->assertFalse($lifecycle->isBooted());
    }

    public function test_before_and_after_request_callbacks(): void
    {
        $beforeCalled = false;
        $afterCalled = false;
        $request = WorkerRequest::fromArray(['method' => 'GET', 'path' => '/']);

        $lifecycle = new LifecycleManager();
        $lifecycle->onBeforeRequest(function (NaryaRequest $req) use (&$beforeCalled, $request) {
            $beforeCalled = true;
            $this->assertSame($request->getMethod(), $req->getMethod());
        });
        $lifecycle->onAfterRequest(function (
            NaryaRequest $req,
            array|NaryaResponse|null $response,
            ?\Throwable $error
        ) use (&$afterCalled) {
            $afterCalled = true;
            $this->assertNull($error);
            $this->assertIsArray($response);
        });

        $lifecycle->beforeRequest($request);
        $lifecycle->afterRequest($request, ['status' => 200], null);

        $this->assertTrue($beforeCalled);
        $this->assertTrue($afterCalled);
    }
}
