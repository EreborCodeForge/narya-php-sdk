<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Lifecycle;

use Narya\SDK\Lifecycle\LifecycleManager;
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
}
