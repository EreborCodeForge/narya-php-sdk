<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Narya\SDK\Runtime\WorkerOptions;
use Narya\SDK\Tests\Support\BridgeTestHarness;

$maxRequests = (int) ($argv[1] ?? 3);
$memoryLimitMb = (int) ($argv[2] ?? 0);

$handler = static fn (array $request): array => [
    'status' => 200,
    'headers' => [],
    'body' => json_encode(['id' => $request['id'] ?? 0]),
    'error' => '',
];

$requests = [];
for ($i = 1; $i <= $maxRequests; $i++) {
    $requests[] = ['id' => $i];
}

$responses = BridgeTestHarness::processRequests(
    $handler,
    $requests,
    new WorkerOptions(maxRequests: $maxRequests, memoryLimitMb: $memoryLimitMb),
    $maxRequests,
);

foreach ($responses as $response) {
    $meta = $response['_meta'];
    echo implode("\t", [
        'req_count=' . $meta['req_count'],
        'mem_usage=' . $meta['mem_usage'],
        'mem_peak=' . $meta['mem_peak'],
        'recycle=' . ($meta['recycle'] ? 'true' : 'false'),
    ]) . PHP_EOL;
}
