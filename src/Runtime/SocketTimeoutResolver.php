<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

final readonly class SocketTimeoutResolver
{
    public function __construct(
        private WorkerOptions $options
    ) {
    }

    public function defaultSeconds(): int
    {
        return $this->options->socketTimeoutSeconds;
    }

    public function resolve(array $request): int
    {
        $timeoutSec = $this->options->socketTimeoutSeconds;

        if (isset($request['timeout_ms']) && (int) $request['timeout_ms'] > 0) {
            $timeoutSec = max(1, (int) ceil(((int) $request['timeout_ms']) / 1000));
        }

        return $timeoutSec;
    }
}
