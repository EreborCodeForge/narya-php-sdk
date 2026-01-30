<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

use Narya\SDK\Contracts\NaryaRequest;

/**
 * Narya request built from the MessagePack payload (Go → PHP).
 * Aligned with protocol.go of the Narya Runtime Engine.
 */
readonly final class WorkerRequest implements NaryaRequest
{
    public function __construct(
        private int $id,
        private string $method,
        private string $uri,
        private string $path,
        private string $query,
        private array $headers,
        private string $body,
        private string $remoteAddr,
        private string $host,
        private string $scheme,
        private int $timeoutMs,
        private array $meta,
        private int $workerId,
        private string $runtimeVersion,
        private array $raw,
    ) {
    }

    /**
     * Create from the MessagePack array received from the runtime (Go).
     *
     * @param array{id?: int, method?: string, uri?: string, path?: string, query?: string, headers?: array, body?: string|bytes, remote_addr?: string, host?: string, scheme?: string, timeout_ms?: int, meta?: array, worker_id?: int, runtime_version?: string, ...} $data
     */
    public static function fromArray(array $data): self
    {
        $body = $data['body'] ?? '';
        if (is_string($body)) {
            // already string
        } elseif (is_array($body)) {
            $body = ''; // MessagePack may deserialize bytes as array of ints
            foreach ($data['body'] as $byte) {
                $body .= chr($byte);
            }
        } else {
            $body = (string) $body;
        }

        return new self(
            id: (int) ($data['id'] ?? 0),
            method: (string) ($data['method'] ?? 'GET'),
            uri: (string) ($data['uri'] ?? ''),
            path: (string) ($data['path'] ?? '/'),
            query: (string) ($data['query'] ?? ''),
            headers: (array) ($data['headers'] ?? []),
            body: $body,
            remoteAddr: (string) ($data['remote_addr'] ?? ''),
            host: (string) ($data['host'] ?? ''),
            scheme: (string) ($data['scheme'] ?? 'http'),
            timeoutMs: (int) ($data['timeout_ms'] ?? 0),
            meta: (array) ($data['meta'] ?? []),
            workerId: (int) ($data['worker_id'] ?? 0),
            runtimeVersion: (string) ($data['runtime_version'] ?? ''),
            raw: $data,
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getQueryParams(): array
    {
        $params = [];
        if ($this->query !== '') {
            parse_str($this->query, $params);
        }
        return $params;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getRemoteAddr(): string
    {
        return $this->remoteAddr;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getTimeoutMs(): int
    {
        return $this->timeoutMs;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getWorkerId(): int
    {
        return $this->workerId;
    }

    public function getRuntimeVersion(): string
    {
        return $this->runtimeVersion;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }
}
