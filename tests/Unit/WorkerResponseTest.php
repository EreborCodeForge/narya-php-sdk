<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Unit;

use Narya\SDK\Runtime\WorkerResponse;
use PHPUnit\Framework\TestCase;

final class WorkerResponseTest extends TestCase
{
    public function test_to_array_returns_protocol_shape(): void
    {
        $resp = WorkerResponse::create(404, ['X-Error' => ['Not Found']], '', '');

        $arr = $resp->toArray();

        $this->assertSame(404, $arr['status']);
        $this->assertSame(['X-Error' => ['Not Found']], $arr['headers']);
        $this->assertSame('', $arr['body']);
        $this->assertSame('', $arr['error']);
        $this->assertArrayNotHasKey('_meta', $arr);
    }

    public function test_create_defaults(): void
    {
        $resp = WorkerResponse::create();

        $this->assertSame(200, $resp->getStatus());
        $this->assertSame([], $resp->getHeaders());
        $this->assertSame('', $resp->getBody());
        $this->assertSame('', $resp->getError());
    }

    public function test_recycle_includes_meta(): void
    {
        $resp = WorkerResponse::recycle(200, [], '{"ok":true}');
        $arr = $resp->toArray();

        $this->assertSame(['recycle' => true], $arr['_meta']);
    }

    public function test_create_with_meta(): void
    {
        $resp = WorkerResponse::create(200, [], '', '', ['custom' => 'value']);
        $arr = $resp->toArray();

        $this->assertSame(['custom' => 'value'], $arr['_meta']);
    }
}
