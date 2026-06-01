<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Unit;

use Narya\SDK\Runtime\SocketTimeoutResolver;
use Narya\SDK\Runtime\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class WorkerBridgeTimeoutTest extends TestCase
{
    public function testDefaultSecondsReturnsWorkerOptionsValue(): void
    {
        $resolver = new SocketTimeoutResolver(new WorkerOptions(socketTimeoutSeconds: 30));

        $this->assertSame(30, $resolver->defaultSeconds());
    }

    public function testTimeoutFallsBackToDefaultWhenRequestDoesNotDefineTimeout(): void
    {
        $resolver = new SocketTimeoutResolver(new WorkerOptions(socketTimeoutSeconds: 30));

        $this->assertSame(30, $resolver->resolve([]));
        $this->assertSame(30, $resolver->resolve(['timeout_ms' => 0]));
    }

    public function testTimeoutUsesRequestValueWhenDefined(): void
    {
        $resolver = new SocketTimeoutResolver(new WorkerOptions(socketTimeoutSeconds: 30));

        $this->assertSame(1, $resolver->resolve(['timeout_ms' => 1000]));
        $this->assertSame(2, $resolver->resolve(['timeout_ms' => 1500]));
    }

    public function testTimeoutDoesNotStickBetweenRequests(): void
    {
        $resolver = new SocketTimeoutResolver(new WorkerOptions(socketTimeoutSeconds: 30));

        $this->assertSame(1, $resolver->resolve(['timeout_ms' => 1000]));
        $this->assertSame(30, $resolver->resolve([]));
    }
}
