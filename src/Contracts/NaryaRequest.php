<?php

declare(strict_types=1);

namespace Narya\SDK\Contracts;

/**
 * Narya request contract (Go → PHP protocol).
 * Aligned with Narya Runtime Engine: id, method, uri, path, query, headers, body,
 * remote_addr, host, scheme, timeout_ms, meta, worker_id, runtime_version.
 *
 * @see https://github.com/EreborCodeForge/NaryaRuntimeEngine
 */
interface NaryaRequest
{
    /** Request ID (correlates with response). */
    public function getId(): int;

    public function getMethod(): string;

    /** Full URI (e.g. /foo?bar=1). */
    public function getUri(): string;

    public function getPath(): string;

    /** Raw query string (e.g. bar=1&baz=2). */
    public function getQuery(): string;

    /** Parsed query (e.g. ['bar' => '1', 'baz' => '2']). */
    public function getQueryParams(): array;

    /** @return array<string, list<string>> */
    public function getHeaders(): array;

    /** Request body (string). */
    public function getBody(): string;

    public function getRemoteAddr(): string;

    public function getHost(): string;

    /** http or https. */
    public function getScheme(): string;

    /** Timeout in milliseconds (0 = no limit). */
    public function getTimeoutMs(): int;

    /** Optional runtime metadata. @return array<string, string> */
    public function getMeta(): array;

    /** Worker ID in the runtime (traceability). */
    public function getWorkerId(): int;

    /** Narya binary version (e.g. 1.0.0). */
    public function getRuntimeVersion(): string;

    /** Raw payload received from the runtime. @return array<string, mixed> */
    public function getRaw(): array;
}
