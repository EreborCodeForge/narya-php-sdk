<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

use Narya\SDK\Contracts\NaryaResponse;

/**
 * Narya response in protocol format (PHP → Go).
 * Immutable implementation for frameworks to return from ApplicationWorker::handle().
 */
readonly final class WorkerResponse implements NaryaResponse
{
    public function __construct(
        private int $status = 200,
        private array $headers = [],
        private string $body = '',
        private string $error = '',
    ) {
    }

    public static function create(
        int $status = 200,
        array $headers = [],
        string $body = '',
        string $error = ''
    ): self {
        return new self($status, $headers, $body, $error);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => $this->body,
            'error' => $this->error,
        ];
    }
}
