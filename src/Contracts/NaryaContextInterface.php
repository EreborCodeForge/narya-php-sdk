<?php

declare(strict_types=1);

namespace Narya\SDK\Contracts;

/**
 * Narya runtime context (request id, worker id, runtime version, meta).
 * Built from the request array or from a dedicated ctx block when the runner sends one.
 */
interface NaryaContextInterface
{
    /** Request ID (correlates with response). */
    public function getRequestId(): int;

    /** Worker ID in the runtime (traceability). */
    public function getWorkerId(): int;

    /** Narya binary version (e.g. 1.0.0). */
    public function getRuntimeVersion(): string;

    /**
     * Optional runtime metadata.
     *
     * @return array<string, string>
     */
    public function getMeta(): array;

    /**
     * Raw context data (e.g. full ctx block or request subset).
     *
     * @return array<string, mixed>
     */
    public function getRaw(): array;
}
