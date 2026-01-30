<?php

declare(strict_types=1);

namespace Narya\SDK\Tests;

use Narya\SDK\Contracts\ApplicationWorker;
use Narya\SDK\Contracts\NaryaRequest;
use Narya\SDK\Contracts\NaryaResponse;
use Narya\SDK\Runtime\Worker;
use Narya\SDK\Runtime\WorkerResponse;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    public function test_worker_with_application_returns_array_from_handle(): void
    {
        $app = new class () implements ApplicationWorker {
            public function handle(NaryaRequest $request): array|NaryaResponse
            {
                return [
                    'status' => 200,
                    'headers' => ['Content-Type' => ['application/json']],
                    'body' => '{"ok":true}',
                    'error' => '',
                ];
            }

            public function reset(): void
            {
            }
        };

        $worker = new Worker($app);
        $request = [
            'id' => 1,
            'method' => 'GET',
            'path' => '/',
            'uri' => '/',
            'query' => '',
            'headers' => [],
            'body' => '',
            'remote_addr' => '127.0.0.1',
            'host' => 'localhost',
            'scheme' => 'http',
            'timeout_ms' => 30000,
            'worker_id' => 0,
            'runtime_version' => '1.0.0',
        ];

        $response = $worker->handleRequest($request);

        $this->assertIsArray($response);
        $this->assertSame(200, $response['status']);
        $this->assertSame('{"ok":true}', $response['body']);
    }

    public function test_worker_with_application_accepts_narya_response(): void
    {
        $app = new class () implements ApplicationWorker {
            public function handle(NaryaRequest $request): array|NaryaResponse
            {
                return WorkerResponse::create(201, ['X-Custom' => ['value']], 'created', '');
            }

            public function reset(): void
            {
            }
        };

        $worker = new Worker($app);
        $request = [
            'id' => 2,
            'method' => 'POST',
            'path' => '/',
            'uri' => '/',
            'query' => '',
            'headers' => [],
            'body' => '',
            'remote_addr' => '',
            'host' => '',
            'scheme' => 'http',
            'timeout_ms' => 0,
            'worker_id' => 1,
            'runtime_version' => '',
        ];

        $response = $worker->handleRequest($request);

        $this->assertSame(201, $response['status']);
        $this->assertSame('created', $response['body']);
        $this->assertSame(['X-Custom' => ['value']], $response['headers']);
    }

    public function test_worker_without_application_uses_simple_handler(): void
    {
        $worker = new Worker(null);
        $request = [
            'id' => 3,
            'method' => 'GET',
            'path' => '/health',
        ];

        $response = $worker->handleRequest($request);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Narya Worker Running', $response['body']);
    }
}
