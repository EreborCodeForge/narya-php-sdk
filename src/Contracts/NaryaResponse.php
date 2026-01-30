<?php

declare(strict_types=1);

namespace Narya\SDK\Contracts;

/**
 * Narya response contract (PHP → Go protocol).
 * Aligned with Narya Runtime Engine: id, status, headers, body, error, _meta (optional).
 * Worker/Bridge fills id and _meta; the application returns status, headers, body, error.
 *
 * @see https://github.com/EreborCodeForge/NaryaRuntimeEngine
 */
interface NaryaResponse
{
    public function getStatus(): int;

    /** @return array<string, list<string>> */
    public function getHeaders(): array;

    public function getBody(): string;

    public function getError(): string;

    /**
     * Convert to the array sent to the runtime (Go).
     * The Bridge adds id (from request) and _meta (req_count, mem_usage, mem_peak, recycle).
     *
     * @return array{status: int, headers: array<string, list<string>>, body: string, error: string}
     */
    public function toArray(): array;
}
