<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Runtime;

use Narya\SDK\Runtime\NaryaContext;
use PHPUnit\Framework\TestCase;

final class NaryaContextTest extends TestCase
{
    public function test_from_request_extracts_id_worker_id_runtime_version_meta(): void
    {
        $request = [
            'id' => 42,
            'worker_id' => 2,
            'runtime_version' => '1.0.0',
            'meta' => ['trace' => 'abc'],
        ];

        $ctx = NaryaContext::fromRequest($request);

        $this->assertSame(42, $ctx->getRequestId());
        $this->assertSame(2, $ctx->getWorkerId());
        $this->assertSame('1.0.0', $ctx->getRuntimeVersion());
        $this->assertSame(['trace' => 'abc'], $ctx->getMeta());
        $this->assertSame($request, $ctx->getRaw());
    }

    public function test_from_request_accepts_worker_id_and_runtime_version_camel_case(): void
    {
        $request = [
            'workerId' => 3,
            'runtimeVersion' => '2.0.0',
        ];

        $ctx = NaryaContext::fromRequest($request);

        $this->assertSame(3, $ctx->getWorkerId());
        $this->assertSame('2.0.0', $ctx->getRuntimeVersion());
    }

    public function test_from_array_for_dedicated_ctx_block(): void
    {
        $data = [
            'request_id' => 10,
            'worker_id' => 1,
            'runtime_version' => '1.0.0',
            'meta' => [],
        ];

        $ctx = NaryaContext::fromArray($data);

        $this->assertSame(10, $ctx->getRequestId());
        $this->assertSame(1, $ctx->getWorkerId());
        $this->assertSame('1.0.0', $ctx->getRuntimeVersion());
        $this->assertSame($data, $ctx->getRaw());
    }
}
