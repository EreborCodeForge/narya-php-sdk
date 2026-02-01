<?php

declare(strict_types=1);

namespace Narya\SDK\Runtime;

use Throwable;

/**
 * WorkerBridge - Communication bridge between Go and PHP via UDS + MessagePack
 *
 * Protocol:
 * - Transport: Unix Domain Socket
 * - Serialization: MessagePack
 * - Framing: [4 bytes uint32 BE length][msgpack payload]
 * - Handshake: Go sends "NARYA1", PHP responds "OK"
 */
final class WorkerBridge
{
    private const MAGIC_HANDSHAKE = 'NARYA1';
    private const HANDSHAKE_OK = 'OK';
    private const MAX_PAYLOAD_SIZE = 10 * 1024 * 1024; // 10MB

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
    private int $requestCount = 0;
    private int $maxRequests = 10000;
    
    /**
     * @param callable $handler Function that receives array request and returns array response
     * @param string $sockPath Unix socket path
     * @param int $maxRequests Max requests before recycling
     */
    public function __construct(callable $handler, string $sockPath, int $maxRequests = 10000)
    {
        $this->handler = $handler;
        $this->sockPath = $sockPath;
        $this->maxRequests = $maxRequests;
        
        // Check if msgpack is available
        if (!function_exists('msgpack_pack')) {
            throw new \RuntimeException(
                'msgpack extension not found. Install with: pecl install msgpack'
            );
        }
    }

    /**
     * Start the worker from $argv (for integration in any application).
     * Expects --sock /path/to.sock and optionally --max-requests N.
     * If --sock is not passed, prints usage to stderr and exits with code 1.
     *
     * @param callable $handler Function (array $request): array with status, headers, body, error
     * @param int $maxRequests Max requests before recycling (default 10000)
     */
    public static function runFromArgv(callable $handler, int $maxRequests = 10000): void
    {
        $argv = $GLOBALS['argv'] ?? [];
        $sockPath = null;

        for ($i = 1; $i < count($argv); $i++) {
            if ($argv[$i] === '--sock' && isset($argv[$i + 1])) {
                $sockPath = $argv[++$i];
            } elseif (str_starts_with($argv[$i], '--sock=')) {
                $sockPath = substr($argv[$i], 7);
            } elseif ($argv[$i] === '--max-requests' && isset($argv[$i + 1])) {
                $maxRequests = (int) $argv[$i + 1];
            }
        }

        if ($sockPath === null || $sockPath === '') {
            fwrite(STDERR, "Usage: php worker.php --sock /path/to/socket.sock [--max-requests N]\n");
            exit(1);
        }

        $bridge = new self($handler, $sockPath, $maxRequests);
        $bridge->run();
    }

    /**
     * Connect to the Unix socket and start the main loop.
     */
    public function run(): void
    {
        $this->connect();
        $this->handshake();
        $this->loop();
    }

    /**
     * Connect to the Unix socket with retry (avoids log spam for orphan / not-ready socket).
     */
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
            // Suppress connection warnings during retry to avoid [PHP Error] spam
            if ($errno === E_WARNING) {
                $lower = strtolower($errstr);
                if (str_contains($lower, 'unable to connect') || str_contains($lower, 'no such file or directory')) {
                    return true; // swallow
                }
            }
            return false; // let other handlers run
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
                    stream_set_timeout($socket, 30);
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

    /**
     * Perform security handshake.
     */
    private function handshake(): void
    {
        // Read magic from Go
        $magic = fread($this->socket, strlen(self::MAGIC_HANDSHAKE));
        
        if ($magic !== self::MAGIC_HANDSHAKE) {
            throw new \RuntimeException(
                "Invalid handshake: expected " . self::MAGIC_HANDSHAKE . ", got {$magic}"
            );
        }

        // Respond OK
        fwrite($this->socket, self::HANDSHAKE_OK);
        fflush($this->socket);
    }

    /**
     * Main worker loop.
     */
    private function loop(): void
    {
        $this->running = true;

        while ($this->running) {
            try {
                // Read request
                $request = $this->readRequest();
                
                if ($request === null) {
                    // EOF - Go closed the connection
                    break;
                }

                $this->requestCount++;

                // Set timeout if specified
                if (isset($request['timeout_ms']) && $request['timeout_ms'] > 0) {
                    $timeoutSec = (int) ceil($request['timeout_ms'] / 1000);
                    stream_set_timeout($this->socket, $timeoutSec);
                }

                // Process request
                $response = $this->processRequest($request);

                // Add meta
                $response['_meta'] = [
                    'req_count' => $this->requestCount,
                    'mem_usage' => memory_get_usage(true),
                    'mem_peak' => memory_get_peak_usage(true),
                    'recycle' => $this->requestCount >= $this->maxRequests,
                ];

                // Send response
                $this->writeResponse($response);

                // Check recycling
                if ($this->requestCount >= $this->maxRequests) {
                    $this->running = false;
                }

            } catch (Throwable $e) {
                // Try to send error response
                try {
                    $this->writeResponse([
                        'id' => $request['id'] ?? 0,
                        'status' => 500,
                        'headers' => ['Content-Type' => ['text/plain']],
                        'body' => '',
                        'error' => $e->getMessage(),
                        '_meta' => [
                            'req_count' => $this->requestCount,
                            'mem_usage' => memory_get_usage(true),
                            'mem_peak' => memory_get_peak_usage(true),
                        ],
                    ]);
                } catch (Throwable $writeError) {
                    // If write fails, exit loop
                    fwrite(STDERR, "[FATAL] Error writing response: {$writeError->getMessage()}\n");
                    break;
                }
            }
        }

        // Cleanup
        $this->close();
    }

    /**
     * Read a frame from the socket.
     * Format: [4 bytes uint32 BE length][msgpack payload]
     */
    private function readFrame(): ?string
    {
        // Read header (4 bytes)
        $header = $this->readExact(4);
        if ($header === null) {
            return null;
        }

        // Decode size (big-endian uint32)
        $size = unpack('N', $header)[1];

        // Validate size
        if ($size > self::MAX_PAYLOAD_SIZE) {
            throw new \RuntimeException(
                "Payload exceeds limit: {$size} > " . self::MAX_PAYLOAD_SIZE
            );
        }

        if ($size === 0) {
            throw new \RuntimeException("Empty payload");
        }

        // Read payload
        $payload = $this->readExact($size);
        if ($payload === null) {
            throw new \RuntimeException("Unexpected EOF while reading payload");
        }

        return $payload;
    }

    /**
     * Write a frame to the socket.
     */
    private function writeFrame(string $payload): void
    {
        $size = strlen($payload);
        
        if ($size > self::MAX_PAYLOAD_SIZE) {
            throw new \RuntimeException(
                "Payload exceeds limit: {$size} > " . self::MAX_PAYLOAD_SIZE
            );
        }

        // Header: 4 bytes big-endian
        $header = pack('N', $size);

        // Write header + payload
        $written = fwrite($this->socket, $header . $payload);
        
        if ($written !== strlen($header) + $size) {
            throw new \RuntimeException("Failed to write frame");
        }

        fflush($this->socket);
    }

    /**
     * Read exactly N bytes from the socket.
     */
    private function readExact(int $length): ?string
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = fread($this->socket, $remaining);
            
            if ($chunk === false || $chunk === '') {
                if ($data === '') {
                    return null; // clean EOF
                }
                throw new \RuntimeException("Unexpected EOF after reading " . strlen($data) . " bytes");
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }

    /**
     * Read and deserialize a request via MessagePack.
     */
    private function readRequest(): ?array
    {
        $payload = $this->readFrame();
        if ($payload === null) {
            return null;
        }

        $request = msgpack_unpack($payload);
        
        if (!is_array($request)) {
            throw new \RuntimeException("Invalid request: not an array");
        }

        return $request;
    }

    /**
     * Serialize and write a response via MessagePack.
     */
    private function writeResponse(array $response): void
    {
        $payload = msgpack_pack($response);
        $this->writeFrame($payload);
    }

    /**
     * Process the request using the handler.
     */
    private function processRequest(array $request): array
    {
        try {
            $handler = $this->handler;
            $response = $handler($request);

            // Validate response
            if (!is_array($response)) {
                throw new \RuntimeException("Handler must return array");
            }

            // Ensure required fields
            return [
                'id' => $request['id'] ?? 0,
                'status' => $response['status'] ?? 200,
                'headers' => $response['headers'] ?? [],
                'body' => is_string($response['body'] ?? '') 
                    ? $response['body'] 
                    : json_encode($response['body']),
                'error' => $response['error'] ?? '',
            ];

        } catch (Throwable $e) {
            return [
                'id' => $request['id'] ?? 0,
                'status' => 500,
                'headers' => ['Content-Type' => ['text/plain']],
                'body' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Close the connection.
     */
    private function close(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Stop the worker.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Get the number of requests processed.
     */
    public function getRequestCount(): int
    {
        return $this->requestCount;
    }
}
