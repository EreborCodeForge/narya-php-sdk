<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Integration;

use Narya\SDK\Runtime\WorkerOptions;
use Narya\SDK\Tests\Support\BridgeTestHarness;
use PHPUnit\Framework\TestCase;

final class WorkerMemoryRecycleTest extends TestCase
{
    /** @var list<string> */
    private static array $leak = [];

    protected function tearDown(): void
    {
        self::$leak = [];
    }

    public function testRecyclesWhenMemoryLimitIsReached(): void
    {
        $handler = function (array $request): array {
            self::$leak[] = str_repeat('A', 1024 * 1024 * 10);

            return [
                'status' => 200,
                'headers' => [],
                'body' => '',
                'error' => '',
            ];
        };

        $responses = BridgeTestHarness::processRequests(
            $handler,
            [['id' => 1]],
            new WorkerOptions(memoryLimitMb: 20),
            10000,
        );

        $this->assertTrue($responses[0]['_meta']['recycle']);
    }
}
