<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Support;

use Narya\SDK\Runtime\WorkerBridge;
use Narya\SDK\Runtime\WorkerOptions;

/**
 * Exercises WorkerBridge request processing without a live UDS connection.
 */
final class BridgeTestHarness
{
    /**
     * @param callable(array): array $handler
     * @param list<array<string, mixed>> $requests
     * @return list<array<string, mixed>>
     */
    public static function processRequests(
        callable $handler,
        array $requests,
        WorkerOptions $options,
        int $maxRequests,
    ): array {
        $bridge = new WorkerBridge($handler, '/tmp/narya-test.sock', $maxRequests, $options);
        $responses = [];

        $process = new \ReflectionMethod($bridge, 'processRequest');
        $process->setAccessible(true);

        $countProp = new \ReflectionProperty($bridge, 'requestCount');
        $countProp->setAccessible(true);

        foreach ($requests as $request) {
            $countProp->setValue($bridge, (int) $countProp->getValue($bridge) + 1);
            $responses[] = $process->invoke($bridge, $request);
        }

        return $responses;
    }
}
