<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Runtime;

use Narya\SDK\Runtime\WorkerRequest;
use PHPUnit\Framework\TestCase;

final class WorkerRequestTest extends TestCase
{
    public function test_from_array_maps_go_protocol_fields(): void
    {
        $data = [
            'id' => 42,
            'method' => 'POST',
            'uri' => '/api/foo?bar=1',
            'path' => '/api/foo',
            'query' => 'bar=1',
            'headers' => ['Content-Type' => ['application/json']],
            'body' => '{"a":1}',
            'remote_addr' => '192.168.1.1',
            'host' => 'example.com',
            'scheme' => 'https',
            'timeout_ms' => 5000,
            'meta' => ['trace' => 'abc'],
            'worker_id' => 2,
            'runtime_version' => '1.0.0',
        ];

        $req = WorkerRequest::fromArray($data);

        $this->assertSame(42, $req->getId());
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('/api/foo?bar=1', $req->getUri());
        $this->assertSame('/api/foo', $req->getPath());
        $this->assertSame('bar=1', $req->getQuery());
        $this->assertSame(['bar' => '1'], $req->getQueryParams());
        $this->assertSame(['Content-Type' => ['application/json']], $req->getHeaders());
        $this->assertSame('{"a":1}', $req->getBody());
        $this->assertSame('192.168.1.1', $req->getRemoteAddr());
        $this->assertSame('example.com', $req->getHost());
        $this->assertSame('https', $req->getScheme());
        $this->assertSame(5000, $req->getTimeoutMs());
        $this->assertSame(['trace' => 'abc'], $req->getMeta());
        $this->assertSame(2, $req->getWorkerId());
        $this->assertSame('1.0.0', $req->getRuntimeVersion());
        $this->assertSame($data, $req->getRaw());
    }

    public function test_from_array_defaults(): void
    {
        $req = WorkerRequest::fromArray([]);

        $this->assertSame(0, $req->getId());
        $this->assertSame('GET', $req->getMethod());
        $this->assertSame('', $req->getUri());
        $this->assertSame('/', $req->getPath());
        $this->assertSame('', $req->getQuery());
        $this->assertSame([], $req->getQueryParams());
        $this->assertSame('http', $req->getScheme());
        $this->assertSame(0, $req->getTimeoutMs());
        $this->assertSame(0, $req->getWorkerId());
    }
}
