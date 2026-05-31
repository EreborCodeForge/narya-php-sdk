<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Integration;

use Narya\SDK\Contracts\ApplicationWorker;
use Narya\SDK\Contracts\NaryaRequest;
use Narya\SDK\Contracts\NaryaResponse;
use Narya\SDK\Runtime\Worker;
use Narya\SDK\Runtime\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class WorkerResetOnExceptionTest extends TestCase
{
    protected function tearDown(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_SERVER = ['PHP_SELF' => '/worker.php'];
    }

    public function testResetRunsAfterException(): void
    {
        $_GET = ['leak' => '1'];
        $_SERVER = ['PHP_SELF' => '/worker.php', 'REQUEST_METHOD' => 'GET'];

        ob_start();
        echo 'leaked';

        $app = new class () implements ApplicationWorker {
            public bool $wasReset = false;

            public function handle(NaryaRequest $request): array|NaryaResponse
            {
                throw new \RuntimeException('boom');
            }

            public function reset(): void
            {
                $this->wasReset = true;
            }
        };

        $worker = new Worker($app, null, 10000, null, WorkerOptions::defaults());

        $request = [
            'id' => 1,
            'method' => 'GET',
            'path' => '/',
            'uri' => '/',
            'query' => '',
            'headers' => [],
            'body' => '',
            'remote_addr' => '',
            'host' => '',
            'scheme' => 'http',
            'timeout_ms' => 0,
            'worker_id' => 0,
            'runtime_version' => '',
        ];

        try {
            $worker->handleRequest($request);
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertTrue($app->wasReset);
        $this->assertSame([], $_GET);
        $this->assertSame(['PHP_SELF' => '/worker.php'], $_SERVER);
        $this->assertSame(0, ob_get_level());
    }
}
