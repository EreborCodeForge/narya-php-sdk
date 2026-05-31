<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Unit;

use Narya\SDK\Runtime\RequestGlobalsHydrator;
use Narya\SDK\Runtime\WorkerRequest;
use PHPUnit\Framework\TestCase;

final class RequestGlobalsHydratorTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_SERVER = ['PHP_SELF' => '/worker.php'];
    }

    public function testHydratesGetAndServerFields(): void
    {
        $request = WorkerRequest::fromArray([
            'method' => 'PUT',
            'uri' => '/items?foo=bar&baz=1',
            'query' => 'foo=bar&baz=1',
            'remote_addr' => '10.0.0.1',
            'host' => 'api.example.com',
            'scheme' => 'https',
            'headers' => [
                'Content-Type' => ['application/json'],
                'X-Request-Id' => ['abc-123'],
            ],
            'server' => [
                'SERVER_SOFTWARE' => 'narya/1.0',
            ],
        ]);

        (new RequestGlobalsHydrator())->hydrate($request);

        $this->assertSame(['foo' => 'bar', 'baz' => '1'], $_GET);
        $this->assertSame('PUT', $_SERVER['REQUEST_METHOD']);
        $this->assertSame('/items?foo=bar&baz=1', $_SERVER['REQUEST_URI']);
        $this->assertSame('foo=bar&baz=1', $_SERVER['QUERY_STRING']);
        $this->assertSame('10.0.0.1', $_SERVER['REMOTE_ADDR']);
        $this->assertSame('api.example.com', $_SERVER['HTTP_HOST']);
        $this->assertSame('https', $_SERVER['REQUEST_SCHEME']);
        $this->assertSame('application/json', $_SERVER['CONTENT_TYPE']);
        $this->assertSame('abc-123', $_SERVER['HTTP_X_REQUEST_ID']);
        $this->assertSame('narya/1.0', $_SERVER['SERVER_SOFTWARE']);
    }
}
