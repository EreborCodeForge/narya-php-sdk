<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Support;

/**
 * Stream wrapper that simulates read timeout (empty read + timed_out meta).
 */
final class TimeoutReadStreamWrapper
{
    private static bool $registered = false;
    private static string $pendingSeed = '';

    /** @var resource */
    public $context;

    /** @var resource */
    private $handle;

    private bool $timedOut = false;

    public static function register(): void
    {
        if (!self::$registered) {
            stream_wrapper_register('timeoutread', self::class);
            self::$registered = true;
        }
    }

    public static function openTimedOut(): mixed
    {
        self::register();

        return fopen('timeoutread://timedout', 'r+');
    }

    public static function openWithData(string $data): mixed
    {
        self::register();
        self::$pendingSeed = $data;

        return fopen('timeoutread://data', 'r+');
    }

    public static function openCleanEof(): mixed
    {
        self::register();

        return fopen('timeoutread://clean', 'r+');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->handle = fopen('php://memory', $mode);
        if ($this->handle === false) {
            return false;
        }

        $this->timedOut = str_contains($path, 'timedout');

        if (str_contains($path, 'data') && self::$pendingSeed !== '') {
            fwrite($this->handle, self::$pendingSeed);
            self::$pendingSeed = '';
            rewind($this->handle);
        }

        return true;
    }

    public function stream_read(int $count): string|false
    {
        if ($this->timedOut) {
            return '';
        }

        if ($this->handle === null) {
            return false;
        }

        if (feof($this->handle)) {
            return '';
        }

        return fread($this->handle, $count);
    }

    public function stream_write(string $data): int
    {
        if ($this->handle === null) {
            return 0;
        }

        $written = fwrite($this->handle, $data);
        return $written === false ? 0 : $written;
    }

    public function stream_eof(): bool
    {
        if ($this->timedOut) {
            return false;
        }

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
            'timed_out' => $this->timedOut,
            'blocked' => true,
            'eof' => !$this->timedOut && $this->handle !== null && feof($this->handle),
            'wrapper_type' => 'timeoutread',
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
}
