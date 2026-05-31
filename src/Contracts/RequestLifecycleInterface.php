<?php

declare(strict_types=1);

namespace Narya\SDK\Contracts;

interface RequestLifecycleInterface extends LifecycleInterface
{
    public function beforeRequest(NaryaRequest $request): void;

    public function afterRequest(NaryaRequest $request, array|NaryaResponse|null $response, ?\Throwable $error): void;
}
