<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

use Narya\SDK\Contracts\ApplicationWorker;
use Narya\SDK\Contracts\LifecycleInterface;
use Narya\SDK\Contracts\NaryaResponse;
use Narya\SDK\Contracts\RequestLifecycleInterface;
use Narya\SDK\Runtime\WorkerRequest;

final class Worker
{
    private ?WorkerBridge $bridge = null;
    private ?ApplicationWorker $application = null;
    /** @var callable(array):array|null Custom handler (used when application is null) */
    private $handler;
    private ?LifecycleInterface $lifecycle = null;
    private bool $initialized = false;
    private int $maxRequests = 10000;
    private WorkerOptions $options;
    private WorkerResetter $resetter;
    private int $handledRequests = 0;

    /**
     * @param ApplicationWorker|null $application Application (framework) injected into the worker (optional)
     * @param callable(array):array|null $handler Callable handler (optional; used when application is null)
     * @param int $maxRequests Max requests before recycling (used when run() is called without --max-requests in argv)
     * @param LifecycleInterface|null $lifecycle Lifecycle: boot() after handshake, shutdown() on exit (optional)
     * @param WorkerOptions|null $options Worker tuning options (optional)
     */
    public function __construct(
        ?ApplicationWorker $application = null,
        ?callable $handler = null,
        int $maxRequests = 10000,
        ?LifecycleInterface $lifecycle = null,
        ?WorkerOptions $options = null,
    ) {
        $this->application = $application;
        $this->handler = $handler;
        $this->options = $options ?? new WorkerOptions(maxRequests: $maxRequests);
        $this->maxRequests = $this->options->maxRequests;
        $this->lifecycle = $lifecycle;
        $this->resetter = new WorkerResetter($this->options);
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);

        $this->initialized = true;
    }

    public function run(): void
    {
        $this->initialize();

        $args = WorkerRunArgs::fromArgv($GLOBALS['argv'] ?? [], $this->maxRequests);
        if ($args->sockPath === null || $args->sockPath === '') {
            $args->exitWithUsage();
        }

        $runtimeOptions = $this->options->withMaxRequests($args->maxRequests);
        if ($args->memoryLimitMb > 0) {
            $runtimeOptions = $runtimeOptions->withMemoryLimitMb($args->memoryLimitMb);
        }
        if ($args->socketTimeoutSeconds > 0) {
            $runtimeOptions = $runtimeOptions->withSocketTimeoutSeconds($args->socketTimeoutSeconds);
        }

        $this->bridge = new WorkerBridge(
            [$this, 'handleRequest'],
            $args->sockPath,
            $runtimeOptions->maxRequests,
            $runtimeOptions,
        );

        try {
            $this->bridge->connectAndHandshake();
            $this->lifecycle?->boot();
            $this->bridge->serve();
        } finally {
            $this->lifecycle?->shutdown();
        }
    }

    /**
     * @param array $request MessagePack request from Go
     * @return array Response to Go (Bridge adds id and _meta)
     */
    public function handleRequest(array $request): array
    {
        $naryaRequest = WorkerRequest::fromArray($request);
        $response = null;
        $error = null;

        try {
            if ($this->lifecycle instanceof RequestLifecycleInterface) {
                $this->lifecycle->beforeRequest($naryaRequest);
            }

            if ($this->application !== null) {
                $response = $this->application->handle($naryaRequest);
                return $response instanceof NaryaResponse ? $response->toArray() : $response;
            }

            if ($this->handler !== null) {
                return ($this->handler)($request);
            }

            return $this->handleSimple($request);
        } catch (\Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            if ($this->lifecycle instanceof RequestLifecycleInterface) {
                $this->lifecycle->afterRequest($naryaRequest, $response, $error);
            }

            $this->reset();
        }
    }

    private function handleSimple(array $request): array
    {
        $method = $request['method'] ?? 'GET';
        $path = $request['path'] ?? '/';

        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => ['application/json'],
                'X-Powered-By' => ['Narya/1.0'],
            ],
            'body' => json_encode([
                'message' => 'Narya Worker Running',
                'method' => $method,
                'path' => $path,
                'timestamp' => date('c'),
            ]),
            'error' => '',
        ];
    }

    private function reset(): void
    {
        $this->handledRequests++;

        if ($this->options->gcInterval > 1 && ($this->handledRequests % $this->options->gcInterval) !== 0) {
            (new WorkerResetter($this->options->withoutMemoryCacheGc()))->reset($this->application);
            return;
        }

        $this->resetter->reset($this->application);
    }

    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        fwrite(STDERR, "[PHP Error] {$errstr} in {$errfile}:{$errline}\n");

        if ($errno & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        }

        return true;
    }

    public function handleException(\Throwable $e): void
    {
        fwrite(STDERR, "[PHP Exception] {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n");
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }

    public function setApplication(ApplicationWorker $application): void
    {
        $this->application = $application;
    }

    public function getApplication(): ?ApplicationWorker
    {
        return $this->application;
    }

    public function setLifecycle(LifecycleInterface $lifecycle): void
    {
        $this->lifecycle = $lifecycle;
    }

    public function getLifecycle(): ?LifecycleInterface
    {
        return $this->lifecycle;
    }

    public function getRequestCount(): int
    {
        return $this->bridge?->getRequestCount() ?? 0;
    }
}
