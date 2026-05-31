<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Unit;

use Narya\SDK\Runtime\MemoryGuard;
use Narya\SDK\Runtime\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class MemoryGuardTest extends TestCase
{
    public function testDoesNotRecycleWhenMemoryLimitIsDisabled(): void
    {
        $guard = new MemoryGuard(new WorkerOptions(memoryLimitMb: 0));

        $this->assertFalse($guard->shouldRecycle());
    }

    public function testRecycleWhenMemoryLimitIsReached(): void
    {
        $guard = new MemoryGuard(new WorkerOptions(memoryLimitMb: 1));

        $leak = str_repeat('A', 1024 * 1024);

        $this->assertTrue($guard->shouldRecycle());

        unset($leak);
    }

    public function testUsageAndPeakReturnPositiveValues(): void
    {
        $guard = new MemoryGuard(WorkerOptions::defaults());

        $this->assertGreaterThan(0, $guard->usage());
        $this->assertGreaterThan(0, $guard->peak());
    }
}
