<?php

declare(strict_types=1);

namespace Narya\SDK\Tests\Unit;

use Narya\SDK\Runtime\WorkerBridge;
use Narya\SDK\Runtime\WorkerOptions;
use PHPUnit\Framework\TestCase;

final class WorkerBridgeHandshakeTest extends TestCase
{
    public function testHandshakeThrowsEofBeforeHandshake(): void
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);

        $bridge = new WorkerBridge(
            static fn (array $request): array => ['status' => 200, 'headers' => [], 'body' => '', 'error' => ''],
            '/tmp/narya-test.sock',
            100,
            new WorkerOptions(),
        );

        $socketProp = new \ReflectionProperty($bridge, 'socket');
        $socketProp->setAccessible(true);
        $socketProp->setValue($bridge, $stream);

        $handshake = new \ReflectionMethod($bridge, 'handshake');
        $handshake->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('(EOF before handshake)');

        try {
            $handshake->invoke($bridge);
        } finally {
            fclose($stream);
        }
    }

    public function testHandshakeThrowsOnInvalidMagic(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'WRONG!');
        rewind($stream);
        $this->assertIsResource($stream);

        $bridge = new WorkerBridge(
            static fn (array $request): array => ['status' => 200, 'headers' => [], 'body' => '', 'error' => ''],
            '/tmp/narya-test.sock',
        );

        $socketProp = new \ReflectionProperty($bridge, 'socket');
        $socketProp->setAccessible(true);
        $socketProp->setValue($bridge, $stream);

        $handshake = new \ReflectionMethod($bridge, 'handshake');
        $handshake->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('(6 bytes)');

        try {
            $handshake->invoke($bridge);
        } finally {
            fclose($stream);
        }
    }

    public function testCanWriteToSocketIsFalseWhenSocketAtEof(): void
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

        fclose($accepted);
        stream_set_blocking($client, true);

        $bridge = new WorkerBridge(
            static fn (array $request): array => ['status' => 200, 'headers' => [], 'body' => '', 'error' => ''],
            '/tmp/narya-test.sock',
        );

        $socketProp = new \ReflectionProperty($bridge, 'socket');
        $socketProp->setAccessible(true);
        $socketProp->setValue($bridge, $client);

        $handshakeComplete = new \ReflectionProperty($bridge, 'handshakeComplete');
        $handshakeComplete->setAccessible(true);
        $handshakeComplete->setValue($bridge, true);

        $canWrite = new \ReflectionMethod($bridge, 'canWriteToSocket');
        $canWrite->setAccessible(true);

        $this->assertFalse($canWrite->invoke($bridge));

        fclose($client);
        fclose($server);
    }

    public function testRestoreDefaultSocketTimeoutResetsStreamTimeout(): void
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

        $options = new WorkerOptions(socketTimeoutSeconds: 30);
        $bridge = new WorkerBridge(
            static fn (array $request): array => ['status' => 200, 'headers' => [], 'body' => '', 'error' => ''],
            '/tmp/narya-test.sock',
            100,
            $options,
        );

        $socketProp = new \ReflectionProperty($bridge, 'socket');
        $socketProp->setAccessible(true);
        $socketProp->setValue($bridge, $client);

        stream_set_timeout($client, 1);

        $restore = new \ReflectionMethod($bridge, 'restoreDefaultSocketTimeout');
        $restore->setAccessible(true);
        $restore->invoke($bridge);

        $meta = stream_get_meta_data($client);
        if (!isset($meta['seconds'])) {
            $this->markTestSkipped('Socket timeout metadata not exposed on this platform');
        }
        $this->assertSame(30, $meta['seconds']);

        fclose($accepted);
        fclose($client);
        fclose($server);
    }
}
