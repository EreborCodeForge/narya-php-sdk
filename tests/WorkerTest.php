<?php

declare(strict_types=1);

namespace Narya\SDK\Tests;

use Narya\SDK\Contracts\ApplicationWorker;
use Narya\SDK\Contracts\NaryaRequest;
use Narya\SDK\Contracts\NaryaResponse;
use Narya\SDK\Lifecycle\LifecycleManager;
use Narya\SDK\Runtime\Worker;
use Narya\SDK\Runtime\WorkerOptions;
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
        $request = $this->sampleRequest(1);

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
        $response = $worker->handleRequest($this->sampleRequest(2));

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

    public function test_worker_accepts_worker_options(): void
    {
        $options = new WorkerOptions(maxRequests: 300, memoryLimitMb: 256, gcInterval: 10);
        $worker = new Worker(null, null, 10000, null, $options);

        $this->assertSame(200, $worker->handleRequest($this->sampleRequest(4))['status']);
    }

    public function test_worker_fires_request_lifecycle_hooks(): void
    {
        $before = false;
        $after = false;

        $lifecycle = new LifecycleManager();
        $lifecycle->onBeforeRequest(function () use (&$before) {
            $before = true;
        });
        $lifecycle->onAfterRequest(function () use (&$after) {
            $after = true;
        });

        $app = new class () implements ApplicationWorker {
            public function handle(NaryaRequest $request): array|NaryaResponse
            {
                return WorkerResponse::create();
            }

            public function reset(): void
            {
            }
        };

        $worker = new Worker($app, null, 10000, $lifecycle);
        $worker->handleRequest($this->sampleRequest(5));

        $this->assertTrue($before);
        $this->assertTrue($after);
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleRequest(int $id): array
    {
        return [
            'id' => $id,
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
    }
}
