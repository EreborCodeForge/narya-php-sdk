<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Support;

/**
 * Stream wrapper where fwrite fails with EOF set (simulates closed socket on write).
 */
final class EofWriteStreamWrapper
{
    private static bool $registered = false;

    /** @var resource */
    public $context;

    public static function register(): void
    {
        if (!self::$registered) {
            stream_wrapper_register('eofwrite', self::class);
            self::$registered = true;
        }
    }

    public static function open(): mixed
    {
        self::register();
        return fopen('eofwrite://closed', 'w');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return true;
    }

    public function stream_write(string $data): int|false
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function stream_get_meta_data(): array
    {
        return [
            'timed_out' => false,
            'blocked' => true,
            'eof' => true,
            'wrapper_type' => 'eofwrite',
            'stream_type' => 'memory',
            'mode' => 'w',
            'seekable' => false,
        ];
    }
}
