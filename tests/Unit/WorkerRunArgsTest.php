<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Unit;

use Narya\SDK\Runtime\WorkerRunArgs;
use PHPUnit\Framework\TestCase;

final class WorkerRunArgsTest extends TestCase
{
    public function testParsesSockAndMaxRequests(): void
    {
        $args = WorkerRunArgs::fromArgv(['worker.php', '--sock', '/tmp/a.sock', '--max-requests', '300']);

        $this->assertSame('/tmp/a.sock', $args->sockPath);
        $this->assertSame(300, $args->maxRequests);
    }

    public function testParsesEqualsFormatFlags(): void
    {
        $args = WorkerRunArgs::fromArgv([
            'worker.php',
            '--sock=/tmp/a.sock',
            '--max-requests=300',
            '--memory-limit-mb=256',
            '--socket-timeout=30',
        ]);

        $this->assertSame('/tmp/a.sock', $args->sockPath);
        $this->assertSame(300, $args->maxRequests);
        $this->assertSame(256, $args->memoryLimitMb);
        $this->assertSame(30, $args->socketTimeoutSeconds);
    }

    public function testParsesMemoryAndSocketTimeoutSeparately(): void
    {
        $args = WorkerRunArgs::fromArgv([
            'worker.php',
            '--sock', '/tmp/b.sock',
            '--memory-limit-mb', '128',
            '--socket-timeout', '15',
        ]);

        $this->assertSame('/tmp/b.sock', $args->sockPath);
        $this->assertSame(128, $args->memoryLimitMb);
        $this->assertSame(15, $args->socketTimeoutSeconds);
    }

    public function testDefaultsWhenFlagsMissing(): void
    {
        $args = WorkerRunArgs::fromArgv(['worker.php', '--sock', '/tmp/c.sock'], 5000);

        $this->assertSame('/tmp/c.sock', $args->sockPath);
        $this->assertSame(5000, $args->maxRequests);
        $this->assertSame(0, $args->memoryLimitMb);
        $this->assertSame(0, $args->socketTimeoutSeconds);
    }
}
