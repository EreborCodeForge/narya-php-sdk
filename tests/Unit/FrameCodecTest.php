<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Unit;

use Narya\SDK\Protocol\FrameCodec;
use Narya\SDK\Tests\Support\PartialWriteStreamWrapper;
use Narya\SDK\Tests\Support\TimeoutReadStreamWrapper;
use PHPUnit\Framework\TestCase;

final class FrameCodecTest extends TestCase
{
    private FrameCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new FrameCodec();
    }

    public function testWriteFrameHandlesPartialWrites(): void
    {
        $stream = PartialWriteStreamWrapper::open();
        $this->assertIsResource($stream);

        $payload = 'hello-world';
        $this->codec->writeFrame($stream, $payload);

        $contents = PartialWriteStreamWrapper::contents($stream);
        $size = unpack('N', substr($contents, 0, 4))[1];
        $this->assertSame(strlen($payload), $size);
        $this->assertSame($payload, substr($contents, 4));
    }

    public function testReadFrameReturnsNullOnCleanEof(): void
    {
        $stream = TimeoutReadStreamWrapper::openCleanEof();
        $this->assertIsResource($stream);

        $this->assertNull($this->codec->readFrame($stream));
    }

    public function testReadFrameThrowsOnEmptyPayload(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, pack('N', 0));
        rewind($stream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Empty payload');

        $this->codec->readFrame($stream);
    }

    public function testReadFrameThrowsWhenPayloadExceedsLimit(): void
    {
        $oversized = FrameCodec::MAX_PAYLOAD_SIZE + 1;
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, pack('N', $oversized));
        rewind($stream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Payload exceeds limit');

        $this->codec->readFrame($stream);
    }

    public function testReadExactThrowsOnTimeout(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("Could not create TCP server: {$errstr}");
        }

        $address = stream_socket_get_name($server, false);
        $client = stream_socket_client($address, $errno, $errstr, 2.0);
        if ($client === false) {
            fclose($server);
            $this->markTestSkipped("Could not create TCP client: {$errstr}");
        }

        $accepted = stream_socket_accept($server, 2.0);
        if ($accepted === false) {
            fclose($client);
            fclose($server);
            $this->markTestSkipped('Could not accept TCP connection');
        }

        stream_set_blocking($client, true);
        stream_set_timeout($client, 0, 200000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Socket read timeout');

        try {
            $this->codec->readExact($client, 4);
        } finally {
            fclose($accepted);
            fclose($client);
            fclose($server);
        }
    }

    public function testRoundTripFrame(): void
    {
        $stream = fopen('php://memory', 'r+');
        $payload = '{"status":200,"body":"ok"}';

        $this->codec->writeFrame($stream, $payload);
        rewind($stream);

        $read = $this->codec->readFrame($stream);
        $this->assertSame($payload, $read);
    }
}
