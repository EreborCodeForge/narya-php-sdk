<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Support;

/**
 * Stream wrapper that limits fwrite to fixed chunk sizes (simulates partial socket writes).
 */
final class PartialWriteStreamWrapper
{
    private static int $writeChunkSize = 1;
    private static bool $registered = false;

    /** @var resource */
    public $context;

    /** @var resource */
    private $handle;

    public static function register(int $chunkSize = 1): void
    {
        self::$writeChunkSize = max(1, $chunkSize);

        if (!self::$registered) {
            stream_wrapper_register('partialwrite', self::class);
            self::$registered = true;
        }
    }

    public static function open(string $mode = 'r+'): mixed
    {
        self::register();
        return fopen('partialwrite://memory', $mode);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->handle = fopen('php://memory', $mode);
        return $this->handle !== false;
    }

    public function stream_write(string $data): int
    {
        if ($this->handle === null) {
            return 0;
        }

        $chunk = substr($data, 0, self::$writeChunkSize);
        $written = fwrite($this->handle, $chunk);
        return $written === false ? 0 : $written;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->handle === null) {
            return false;
        }

        return fread($this->handle, $count);
    }

    public function stream_eof(): bool
    {
        if ($this->handle === null) {
            return true;
        }

        return feof($this->handle);
    }

    /**
     * @return array<string, mixed>
     */
    public function stream_get_meta_data(): array
    {
        return [
            'timed_out' => false,
            'blocked' => true,
            'eof' => $this->handle !== null && feof($this->handle),
            'wrapper_type' => 'partialwrite',
            'stream_type' => 'memory',
            'mode' => 'r+',
            'seekable' => true,
        ];
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($this->handle === null) {
            return false;
        }

        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_tell(): int
    {
        if ($this->handle === null) {
            return 0;
        }

        $pos = ftell($this->handle);
        return $pos === false ? 0 : $pos;
    }

    public static function contents(mixed $stream): string
    {
        if (!is_resource($stream)) {
            return '';
        }

        $meta = stream_get_meta_data($stream);
        if (($meta['wrapper_type'] ?? '') !== 'partialwrite') {
            rewind($stream);
            $data = stream_get_contents($stream);
            return $data === false ? '' : $data;
        }

        $pos = ftell($stream);
        rewind($stream);
        $data = stream_get_contents($stream);
        fseek($stream, $pos);

        return $data === false ? '' : $data;
    }
}
