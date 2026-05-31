<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Unit;

use Narya\SDK\Contracts\ApplicationWorker;
use Narya\SDK\Contracts\NaryaRequest;
use Narya\SDK\Contracts\NaryaResponse;
use Narya\SDK\Runtime\WorkerOptions;
use Narya\SDK\Runtime\WorkerResetter;
use PHPUnit\Framework\TestCase;

final class WorkerResetterTest extends TestCase
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

    public function testResetClearsRequestStateAndCallsApplicationReset(): void
    {
        $_GET = ['a' => '1'];
        $_POST = ['b' => '2'];
        $_REQUEST = ['c' => '3'];
        $_COOKIE = ['d' => '4'];
        $_FILES = ['f' => []];
        $_SERVER = [
            'PHP_SELF' => '/worker.php',
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'example.com',
        ];

        ob_start();
        echo 'buffered';

        $resetCalled = false;
        $app = new class () implements ApplicationWorker {
            public bool $wasReset = false;

            public function handle(NaryaRequest $request): array|NaryaResponse
            {
                return [];
            }

            public function reset(): void
            {
                $this->wasReset = true;
            }
        };

        $resetter = new WorkerResetter(WorkerOptions::defaults());
        $resetter->reset($app);

        $this->assertSame([], $_GET);
        $this->assertSame([], $_POST);
        $this->assertSame([], $_REQUEST);
        $this->assertSame([], $_COOKIE);
        $this->assertSame([], $_FILES);
        $this->assertSame(['PHP_SELF' => '/worker.php'], $_SERVER);
        $this->assertTrue($app->wasReset);
        $this->assertSame(0, ob_get_level());
    }

    public function testResetSkipsOutputBuffersWhenDisabled(): void
    {
        $initialLevel = ob_get_level();
        ob_start();
        echo 'keep';

        $options = new WorkerOptions(resetOutputBuffersAfterRequest: false);
        (new WorkerResetter($options))->reset(null);

        $this->assertSame($initialLevel + 1, ob_get_level());
        ob_end_clean();
    }

    public function testResetUsesGcMemCachesWhenAvailable(): void
    {
        if (!function_exists('gc_mem_caches')) {
            $this->markTestSkipped('gc_mem_caches not available');
        }

        (new WorkerResetter(WorkerOptions::defaults()))->reset(null);
        $this->assertTrue(true);
    }
}
