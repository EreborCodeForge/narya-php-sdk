<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

use Narya\SDK\Contracts\ApplicationWorker;
use Narya\SDK\Contracts\LifecycleInterface;
use Narya\SDK\Contracts\NaryaResponse;
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

    /**
     * Create a new worker.
     *
     * @param ApplicationWorker|null $application Application (framework) injected into the worker (optional)
     * @param callable(array):array|null $handler Callable handler (optional; used when application is null)
     * @param int $maxRequests Max requests before recycling (used when run() is called without --max-requests in argv)
     * @param LifecycleInterface|null $lifecycle Lifecycle: boot() before loop, shutdown() on exit (optional)
     */
    public function __construct(
        ?ApplicationWorker $application = null,
        ?callable $handler = null,
        int $maxRequests = 10000,
        ?LifecycleInterface $lifecycle = null,
    ) {
        $this->application = $application;
        $this->handler = $handler;
        $this->maxRequests = $maxRequests;
        $this->lifecycle = $lifecycle;
    }

    /**
     * Initialize the worker.
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // Disable output buffering
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Configure error handling
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);

        $this->initialized = true;
    }

    /**
     * Start the worker loop.
     * Reads --sock and --max-requests from $argv (Narya Runtime contract: php worker.php --sock /path/to.sock).
     */
    public function run(): void
    {
        $this->initialize();

        $args = WorkerRunArgs::fromArgv($GLOBALS['argv'] ?? [], $this->maxRequests);
        if ($args->sockPath === null || $args->sockPath === '') {
            $args->exitWithUsage();
        }

        $this->lifecycle?->boot();

        try {
            $this->bridge = new WorkerBridge([$this, 'handleRequest'], $args->sockPath, $args->maxRequests);
            $this->bridge->run();
        } finally {
            $this->lifecycle?->shutdown();
        }
    }

    /**
     * Main request handler (Narya protocol: array in → array out with status, headers, body, error).
     *
     * @param array $request MessagePack request from Go
     * @return array Response to Go (Bridge adds id and _meta)
     */
    public function handleRequest(array $request): array
    {
        try {
            if ($this->application !== null) {
                $result = $this->application->handle(WorkerRequest::fromArray($request));
                return $result instanceof NaryaResponse ? $result->toArray() : $result;
            }

            if ($this->handler !== null) {
                return ($this->handler)($request);
            }

            return $this->handleSimple($request);
        } finally {
            $this->reset();
        }
    }

    /**
     * Simple handler without framework (default Narya response).
     */
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

    /**
     * Reset state between requests.
     */
    private function reset(): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_SERVER = array_filter($_SERVER, fn ($k) => str_starts_with($k, 'PHP_'), ARRAY_FILTER_USE_KEY);

        if (function_exists('header_remove')) {
            header_remove();
        }

        if ($this->application !== null) {
            $this->application->reset();
        }

        gc_collect_cycles();
    }

    /**
     * PHP error handler.
     */
    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // Log error to stderr
        fwrite(STDERR, "[PHP Error] {$errstr} in {$errfile}:{$errline}\n");
        
        // Convert to exception if fatal error
        if ($errno & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        }

        return true;
    }

    /**
     * Uncaught exception handler.
     */
    public function handleException(\Throwable $e): void
    {
        fwrite(STDERR, "[PHP Exception] {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n");
        fwrite(STDERR, $e->getTraceAsString() . "\n");
    }

    /**
     * Set the application (framework) injected into the worker.
     */
    public function setApplication(ApplicationWorker $application): void
    {
        $this->application = $application;
    }

    /**
     * Get the injected application (null if none).
     */
    public function getApplication(): ?ApplicationWorker
    {
        return $this->application;
    }

    /**
     * Set the lifecycle (boot before loop, shutdown on exit).
     */
    public function setLifecycle(LifecycleInterface $lifecycle): void
    {
        $this->lifecycle = $lifecycle;
    }

    /**
     * Get the injected lifecycle (null if none).
     */
    public function getLifecycle(): ?LifecycleInterface
    {
        return $this->lifecycle;
    }

    /**
     * Get the number of requests processed.
     */
    public function getRequestCount(): int
    {
        return $this->bridge?->getRequestCount() ?? 0;
    }
}
