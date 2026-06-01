<?php

declare(strict_types=1);

namespace Narya\SDK\Protocol;

final class FrameCodec
{
    public const MAX_PAYLOAD_SIZE = 10 * 1024 * 1024; // 10MB

    /**
     * @param resource $stream
     */
    public function writeFrame($stream, string $payload): void
    {
        $size = strlen($payload);

        if ($size > self::MAX_PAYLOAD_SIZE) {
            throw new \RuntimeException(
                "Payload exceeds limit: {$size} > " . self::MAX_PAYLOAD_SIZE
            );
        }

        $this->writeExact($stream, pack('N', $size) . $payload);
    }

    /**
     * @param resource $stream
     */
    public function readFrame($stream): ?string
    {
        $header = $this->readExact($stream, 4);
        if ($header === null) {
            return null;
        }

        $size = unpack('N', $header)[1];

        if ($size > self::MAX_PAYLOAD_SIZE) {
            throw new \RuntimeException(
                "Payload exceeds limit: {$size} > " . self::MAX_PAYLOAD_SIZE
            );
        }

        if ($size === 0) {
            throw new \RuntimeException('Empty payload');
        }

        $payload = $this->readExact($stream, $size);
        if ($payload === null) {
            throw new \RuntimeException('Unexpected EOF while reading payload');
        }

        return $payload;
    }

    /**
     * @param resource $stream
     */
    public function writeExact($stream, string $data): void
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $chunk = fwrite($stream, substr($data, $written));

            if ($chunk === false) {
                $meta = stream_get_meta_data($stream);

                if (!empty($meta['eof'])) {
                    throw new \RuntimeException('Socket closed while writing');
                }

                throw new \RuntimeException('Failed to write to socket');
            }

            if ($chunk === 0) {
                $meta = stream_get_meta_data($stream);

                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException('Socket write timeout');
                }

                throw new \RuntimeException('Socket write returned zero bytes');
            }

            $written += $chunk;
        }

        fflush($stream);
    }

    /**
     * @param resource $stream
     */
    public function readExact($stream, int $length): ?string
    {
        $data = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = fread($stream, $remaining);

            if ($chunk === false) {
                $meta = stream_get_meta_data($stream);

                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException(
                        'Socket read timeout after reading ' . strlen($data) . ' bytes'
                    );
                }

                throw new \RuntimeException('Failed to read from socket');
            }

            if ($chunk === '') {
                $meta = stream_get_meta_data($stream);

                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException(
                        'Socket read timeout after reading ' . strlen($data) . ' bytes'
                    );
                }

                if ($data === '') {
                    return null;
                }

                throw new \RuntimeException(
                    'Unexpected EOF after reading ' . strlen($data) . ' bytes'
                );
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $data;
    }
}
