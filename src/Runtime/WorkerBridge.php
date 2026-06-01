<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

use Narya\SDK\Protocol\FrameCodec;
use Throwable;

final class WorkerBridge
{
    private const MAGIC_HANDSHAKE = 'NARYA1';
    private const HANDSHAKE_OK = 'OK';

    /** Number of normal connection retries (socket may not exist yet). */
    private const CONNECT_RETRIES = 10;
    /** Extra retries for orphan worker (No such file or directory). */
    private const CONNECT_ORPHAN_RETRIES = 2;
    /** Delay between normal retries (ms). */
    private const CONNECT_RETRY_DELAY_MS = 500;
    /** Delay between orphan retries (ms). */
    private const CONNECT_ORPHAN_DELAY_MS = 200;
    /** Connection timeout per attempt (seconds). */
    private const CONNECT_TIMEOUT_S = 2;

    /** @var resource|null */
    private $socket;

    /** @var callable */
    private $handler;

    private string $sockPath;
    private bool $running = false;
    private bool $handshakeComplete = false;
    private int $requestCount = 0;
    private int $maxRequests = 10000;
    private WorkerOptions $options;
    private MemoryGuard $memoryGuard;
    private SocketTimeoutResolver $timeoutResolver;
    private FrameCodec $frameCodec;

    /**
     * @param callable $handler Function that receives array request and returns array response
     */
    public function __construct(
        callable $handler,
        string $sockPath,
        int $maxRequests = 10000,
        ?WorkerOptions $options = null,
    ) {
        $this->handler = $handler;
        $this->sockPath = $sockPath;
        $this->maxRequests = $maxRequests;
        $this->options = $options ?? new WorkerOptions(maxRequests: $maxRequests);
        $this->memoryGuard = new MemoryGuard($this->options);
        $this->timeoutResolver = new SocketTimeoutResolver($this->options);
        $this->frameCodec = new FrameCodec();
    }

    private function ensureMsgpackAvailable(): void
    {
        if (!function_exists('msgpack_pack')) {
            throw new \RuntimeException(
                'msgpack extension not found. Install with: pecl install msgpack'
            );
        }
    }

    /**
     * Start the worker from $argv (for integration in any application).
     *
     * @param callable $handler Function (array $request): array with status, headers, body, error
     */
    public static function runFromArgv(callable $handler, int $maxRequests = 10000): void
    {
        $args = WorkerRunArgs::fromArgv($GLOBALS['argv'] ?? [], $maxRequests);

        if ($args->sockPath === null || $args->sockPath === '') {
            $args->exitWithUsage();
        }

        $options = WorkerOptions::defaults()->withMaxRequests($args->maxRequests);
        $bridge = new self($handler, $args->sockPath, $args->maxRequests, $options);
        $bridge->run();
    }

    public function run(): void
    {
        $this->connectAndHandshake();
        $this->serve();
    }

    public function connectAndHandshake(): void
    {
        $this->ensureMsgpackAvailable();
        $this->connect();
        $this->handshake();
    }

    public function serve(): void
    {
        $this->loop();
    }

    private function connect(): void
    {
        $address = 'unix://' . $this->sockPath;
        $attempt = 0;
        $maxAttempts = self::CONNECT_RETRIES;
        $delayMs = self::CONNECT_RETRY_DELAY_MS;
        $orphanMode = false;
        $lastErrno = 0;
        $lastErrstr = '';

        $previousHandler = set_error_handler(function (int $errno, string $errstr, string $file, int $line): bool {
            if ($errno === E_WARNING) {
                $lower = strtolower($errstr);
                if (str_contains($lower, 'unable to connect') || str_contains($lower, 'no such file or directory')) {
                    return true;
                }
            }

            return false;
        });

        try {
            while ($attempt < $maxAttempts) {
                $socket = @stream_socket_client(
                    $address,
                    $errno,
                    $errstr,
                    (float) self::CONNECT_TIMEOUT_S,
                    STREAM_CLIENT_CONNECT
                );

                if ($socket !== false) {
                    stream_set_blocking($socket, true);
                    stream_set_timeout($socket, $this->options->socketTimeoutSeconds);
                    $this->socket = $socket;

                    return;
                }

                $lastErrno = $errno;
                $lastErrstr = $errstr;
                $attempt++;

                if (!$orphanMode && str_contains(strtolower($errstr), 'no such file or directory')) {
                    $orphanMode = true;
                    $maxAttempts = $attempt + self::CONNECT_ORPHAN_RETRIES;
                    $delayMs = self::CONNECT_ORPHAN_DELAY_MS;
                }

                if ($attempt >= $maxAttempts) {
                    break;
                }

                usleep($delayMs * 1000);
            }

            throw new \RuntimeException(
                sprintf(
                    'Failed to connect to socket %s after %d attempts: [%d] %s',
                    $address,
                    $attempt,
                    $lastErrno,
                    $lastErrstr
                )
            );
        } finally {
            if ($previousHandler !== null) {
                set_error_handler($previousHandler);
            } else {
                restore_error_handler();
            }
        }
    }

    private function handshake(): void
    {
        $expectedLen = strlen(self::MAGIC_HANDSHAKE);
        $magic = $this->frameCodec->readExact($this->socket, $expectedLen);

        if ($magic === null) {
            throw new \RuntimeException(
                'Invalid handshake: expected ' . self::MAGIC_HANDSHAKE . ', got (EOF before handshake)'
            );
        }

        if ($magic !== self::MAGIC_HANDSHAKE) {
            $display = $magic === '' ? '' : $magic;
            throw new \RuntimeException(
                'Invalid handshake: expected ' . self::MAGIC_HANDSHAKE
                . ', got "' . $display . '" (' . strlen($magic) . ' bytes)'
            );
        }

        $this->frameCodec->writeExact($this->socket, self::HANDSHAKE_OK);
        $this->handshakeComplete = true;
    }

    private function loop(): void
    {
        $this->running = true;

        while ($this->running) {
            $request = null;

            try {
                $request = $this->readRequest();

                if ($request === null) {
                    break;
                }

                $this->requestCount++;

                try {
                    $this->applySocketTimeout($request);
                    $response = $this->processRequest($request);
                    $this->writeResponse($response);
                } finally {
                    $this->restoreDefaultSocketTimeout();
                }

                $shouldRecycle = $this->requestCount >= $this->maxRequests
                    || $this->memoryGuard->shouldRecycle();

                if ($shouldRecycle) {
                    $this->running = false;
                }
            } catch (Throwable $e) {
                if ($request === null || !$this->canWriteToSocket()) {
                    fwrite(STDERR, "[FATAL] Request loop error: {$e->getMessage()}\n");
                    break;
                }

                try {
                    $shouldRecycle = $this->requestCount >= $this->maxRequests
                        || $this->memoryGuard->shouldRecycle();

                    $this->writeResponse([
                        'id' => $request['id'] ?? 0,
                        'status' => 500,
                        'headers' => ['Content-Type' => ['text/plain']],
                        'body' => '',
                        'error' => $e->getMessage(),
                        '_meta' => [
                            'req_count' => $this->requestCount,
                            'mem_usage' => $this->memoryGuard->usage(),
                            'mem_peak' => $this->memoryGuard->peak(),
                            'recycle' => $shouldRecycle,
                        ],
                    ]);
                } catch (Throwable $writeError) {
                    fwrite(STDERR, "[FATAL] Error writing response: {$writeError->getMessage()}\n");
                    break;
                }
            }
        }

        $this->close();
    }

    private function canWriteToSocket(): bool
    {
        return $this->handshakeComplete && $this->socket !== null && !feof($this->socket);
    }

    private function applySocketTimeout(array $request): void
    {
        $timeoutSec = $this->timeoutResolver->resolve($request);
        stream_set_timeout($this->socket, $timeoutSec);
    }

    private function restoreDefaultSocketTimeout(): void
    {
        if ($this->socket === null) {
            return;
        }

        stream_set_timeout($this->socket, $this->timeoutResolver->defaultSeconds());
    }

    private function readRequest(): ?array
    {
        $this->ensureMsgpackAvailable();
        $payload = $this->frameCodec->readFrame($this->socket);

        if ($payload === null) {
            return null;
        }

        $request = msgpack_unpack($payload);

        if (!is_array($request)) {
            throw new \RuntimeException('Invalid request: not an array');
        }

        return $request;
    }

    private function writeResponse(array $response): void
    {
        $this->ensureMsgpackAvailable();
        $payload = msgpack_pack($response);
        $this->frameCodec->writeFrame($this->socket, $payload);
    }

    private function processRequest(array $request): array
    {
        try {
            $handler = $this->handler;
            $response = $handler($request);

            if (!is_array($response)) {
                throw new \RuntimeException('Handler must return array');
            }

            $appMeta = is_array($response['_meta'] ?? null) ? $response['_meta'] : [];

            $shouldRecycle = $this->requestCount >= $this->maxRequests
                || $this->memoryGuard->shouldRecycle();

            return [
                'id' => $request['id'] ?? 0,
                'status' => $response['status'] ?? 200,
                'headers' => $response['headers'] ?? [],
                'body' => is_string($response['body'] ?? '')
                    ? $response['body']
                    : json_encode($response['body']),
                'error' => $response['error'] ?? '',
                '_meta' => array_merge($appMeta, [
                    'req_count' => $this->requestCount,
                    'mem_usage' => $this->memoryGuard->usage(),
                    'mem_peak' => $this->memoryGuard->peak(),
                    'recycle' => ($appMeta['recycle'] ?? false) || $shouldRecycle,
                ]),
            ];
        } catch (Throwable $e) {
            $shouldRecycle = $this->requestCount >= $this->maxRequests
                || $this->memoryGuard->shouldRecycle();

            return [
                'id' => $request['id'] ?? 0,
                'status' => 500,
                'headers' => ['Content-Type' => ['text/plain']],
                'body' => '',
                'error' => $e->getMessage(),
                '_meta' => [
                    'req_count' => $this->requestCount,
                    'mem_usage' => $this->memoryGuard->usage(),
                    'mem_peak' => $this->memoryGuard->peak(),
                    'recycle' => $shouldRecycle,
                ],
            ];
        }
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function getRequestCount(): int
    {
        return $this->requestCount;
    }
}
