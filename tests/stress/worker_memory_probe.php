<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Narya\SDK\Runtime\Worker;
use Narya\SDK\Runtime\WorkerOptions;

$options = new WorkerOptions(
    maxRequests: (int) (getenv('NARYA_MAX_REQUESTS') ?: 100),
    memoryLimitMb: (int) (getenv('NARYA_MEMORY_LIMIT_MB') ?: 256),
    gcInterval: (int) (getenv('NARYA_GC_INTERVAL') ?: 1),
    socketTimeoutSeconds: (int) (getenv('NARYA_SOCKET_TIMEOUT') ?: 30),
);

$worker = new Worker(null, static function (array $request) use (&$leak): array {
    static $count = 0;
    $count++;

    return [
        'status' => 200,
        'headers' => ['Content-Type' => ['application/json']],
        'body' => json_encode(['count' => $count, 'path' => $request['path'] ?? '/']),
        'error' => '',
    ];
}, options: $options);

$iterations = (int) (getenv('NARYA_PROBE_ITERATIONS') ?: 50);

for ($i = 1; $i <= $iterations; $i++) {
    $response = $worker->handleRequest([
        'id' => $i,
        'method' => 'GET',
        'path' => '/probe',
        'uri' => '/probe',
        'query' => '',
        'headers' => [],
        'body' => '',
        'remote_addr' => '127.0.0.1',
        'host' => 'localhost',
        'scheme' => 'http',
        'timeout_ms' => 0,
        'worker_id' => 0,
        'runtime_version' => '1.0.0',
    ]);

    $shouldRecycle = ($i % $options->maxRequests) === 0;

    echo implode("\t", [
        'req_count=' . $i,
        'memory_get_usage=' . memory_get_usage(true),
        'memory_get_peak_usage=' . memory_get_peak_usage(true),
        'recycle=' . ($shouldRecycle ? 'true' : 'false'),
    ]) . PHP_EOL;
}
