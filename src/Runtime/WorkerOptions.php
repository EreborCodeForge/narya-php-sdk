<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

final readonly class WorkerOptions
{
    public function __construct(
        public int $maxRequests = 10000,
        public int $socketTimeoutSeconds = 30,
        public int $memoryLimitMb = 0,
        public int $gcInterval = 1,
        public bool $enableGcMemCaches = true,
        public bool $resetOutputBuffersAfterRequest = true,
        public bool $clearHeadersAfterRequest = true,
        public int $maxReusableBodyBytes = 262144,
        public int $maxReusablePayloadBytes = 262144,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function shouldCheckMemory(): bool
    {
        return $this->memoryLimitMb > 0;
    }

    public function memoryLimitBytes(): int
    {
        return $this->memoryLimitMb * 1024 * 1024;
    }

    public function withMaxRequests(int $maxRequests): self
    {
        return new self(
            maxRequests: $maxRequests,
            socketTimeoutSeconds: $this->socketTimeoutSeconds,
            memoryLimitMb: $this->memoryLimitMb,
            gcInterval: $this->gcInterval,
            enableGcMemCaches: $this->enableGcMemCaches,
            resetOutputBuffersAfterRequest: $this->resetOutputBuffersAfterRequest,
            clearHeadersAfterRequest: $this->clearHeadersAfterRequest,
            maxReusableBodyBytes: $this->maxReusableBodyBytes,
            maxReusablePayloadBytes: $this->maxReusablePayloadBytes,
        );
    }

    public function withMemoryLimitMb(int $memoryLimitMb): self
    {
        return new self(
            maxRequests: $this->maxRequests,
            socketTimeoutSeconds: $this->socketTimeoutSeconds,
            memoryLimitMb: $memoryLimitMb,
            gcInterval: $this->gcInterval,
            enableGcMemCaches: $this->enableGcMemCaches,
            resetOutputBuffersAfterRequest: $this->resetOutputBuffersAfterRequest,
            clearHeadersAfterRequest: $this->clearHeadersAfterRequest,
            maxReusableBodyBytes: $this->maxReusableBodyBytes,
            maxReusablePayloadBytes: $this->maxReusablePayloadBytes,
        );
    }

    public function withSocketTimeoutSeconds(int $socketTimeoutSeconds): self
    {
        return new self(
            maxRequests: $this->maxRequests,
            socketTimeoutSeconds: $socketTimeoutSeconds,
            memoryLimitMb: $this->memoryLimitMb,
            gcInterval: $this->gcInterval,
            enableGcMemCaches: $this->enableGcMemCaches,
            resetOutputBuffersAfterRequest: $this->resetOutputBuffersAfterRequest,
            clearHeadersAfterRequest: $this->clearHeadersAfterRequest,
            maxReusableBodyBytes: $this->maxReusableBodyBytes,
            maxReusablePayloadBytes: $this->maxReusablePayloadBytes,
        );
    }

    public function withoutMemoryCacheGc(): self
    {
        return new self(
            maxRequests: $this->maxRequests,
            socketTimeoutSeconds: $this->socketTimeoutSeconds,
            memoryLimitMb: $this->memoryLimitMb,
            gcInterval: $this->gcInterval,
            enableGcMemCaches: false,
            resetOutputBuffersAfterRequest: $this->resetOutputBuffersAfterRequest,
            clearHeadersAfterRequest: $this->clearHeadersAfterRequest,
            maxReusableBodyBytes: $this->maxReusableBodyBytes,
            maxReusablePayloadBytes: $this->maxReusablePayloadBytes,
        );
    }
}
