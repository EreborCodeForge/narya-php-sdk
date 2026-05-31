<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

use Narya\SDK\Contracts\NaryaContextInterface;

/**
 * Narya runtime context. Factory fromRequest() for full request array; fromArray() for dedicated ctx block.
 */
readonly final class NaryaContext implements NaryaContextInterface
{
    public function __construct(
        private int $requestId,
        private int $workerId,
        private string $runtimeVersion,
        private array $meta,
        private array $raw,
    ) {
    }

    /**
     * Build context from the full request array (id, worker_id/workerId, runtime_version/runtimeVersion, meta, raw).
     *
     * @param array{id?: int, worker_id?: int, workerId?: int, runtime_version?: string, runtimeVersion?: string, meta?: array, ...} $request
     */
    public static function fromRequest(array $request): self
    {
        $requestId = (int) ($request['id'] ?? 0);
        $workerId = (int) ($request['worker_id'] ?? $request['workerId'] ?? 0);
        $runtimeVersion = (string) ($request['runtime_version'] ?? $request['runtimeVersion'] ?? '');
        $meta = (array) ($request['meta'] ?? []);

        return new self($requestId, $workerId, $runtimeVersion, $meta, $request);
    }

    /**
     * Build context from a dedicated ctx block (when the runner sends e.g. request_id, worker_id, runtime_version, meta).
     *
     * @param array{request_id?: int, worker_id?: int, workerId?: int, runtime_version?: string, runtimeVersion?: string, meta?: array, ...} $data
     */
    public static function fromArray(array $data): self
    {
        $requestId = (int) ($data['request_id'] ?? $data['requestId'] ?? $data['id'] ?? 0);
        $workerId = (int) ($data['worker_id'] ?? $data['workerId'] ?? 0);
        $runtimeVersion = (string) ($data['runtime_version'] ?? $data['runtimeVersion'] ?? '');
        $meta = (array) ($data['meta'] ?? []);

        return new self($requestId, $workerId, $runtimeVersion, $meta, $data);
    }

    public function getRequestId(): int
    {
        return $this->requestId;
    }

    public function getWorkerId(): int
    {
        return $this->workerId;
    }

    public function getRuntimeVersion(): string
    {
        return $this->runtimeVersion;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }
}
