<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Integration;

use Narya\SDK\Runtime\WorkerOptions;
use Narya\SDK\Tests\Support\BridgeTestHarness;
use PHPUnit\Framework\TestCase;

final class WorkerRecycleTest extends TestCase
{
    public function testRecyclesOnMaxRequests(): void
    {
        $handler = static fn (array $request): array => [
            'status' => 200,
            'headers' => [],
            'body' => '',
            'error' => '',
        ];

        $requests = [
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ];

        $responses = BridgeTestHarness::processRequests(
            $handler,
            $requests,
            WorkerOptions::defaults(),
            3,
        );

        $this->assertCount(3, $responses);
        $this->assertFalse($responses[0]['_meta']['recycle']);
        $this->assertFalse($responses[1]['_meta']['recycle']);
        $this->assertTrue($responses[2]['_meta']['recycle']);
        $this->assertSame(3, $responses[2]['_meta']['req_count']);
    }

    public function testAppRecycleMetaIsPreserved(): void
    {
        $handler = static fn (array $request): array => [
            'status' => 200,
            'headers' => [],
            'body' => '',
            'error' => '',
            '_meta' => ['recycle' => true, 'reason' => 'manual'],
        ];

        $responses = BridgeTestHarness::processRequests(
            $handler,
            [['id' => 1]],
            WorkerOptions::defaults(),
            100,
        );

        $this->assertTrue($responses[0]['_meta']['recycle']);
        $this->assertSame('manual', $responses[0]['_meta']['reason']);
    }
}
