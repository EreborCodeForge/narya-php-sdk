<?php

declare(strict_types=1);

namespace Narya\SDK\Contracts;

/**
 * Contract for the application (framework) injected into the Worker.
 * Receives NaryaRequest and returns array or NaryaResponse (status, headers, body, error).
 * The Bridge adds id (from request) and _meta (req_count, mem_usage, mem_peak, recycle).
 *
 * @see https://github.com/EreborCodeForge/NaryaRuntimeEngine
 */
interface ApplicationWorker
{
    /**
     * Process a request and return the response (array or NaryaResponse).
     * Array must contain: status, headers, body, error.
     *
     * @return array{status: int, headers: array<string, list<string>>, body: string, error: string}|NaryaResponse
     */
    public function handle(NaryaRequest $request): array|NaryaResponse;

    /**
     * Reset state between requests (required in Narya: superglobals, connections, per-request state).
     */
    public function reset(): void;
}
