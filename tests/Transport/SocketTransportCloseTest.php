<?php

declare(strict_types=1);

namespace Transport;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Smpp\Transport\SocketTransport;
use Socket;

class SocketTransportCloseTest extends TestCase
{
    /**
     * close() must be safe when open() never assigned the socket (e.g.
     * try { open(); } finally { close(); } where open() threw). Before the fix
     * this raised a fatal Error from accessing the uninitialised typed property.
     */
    public function testCloseIsNoopWhenNeverOpened(): void
    {
        $transport = $this->bareTransport();

        $transport->close();

        self::assertFalse($transport->isOpen());
    }

    /**
     * close() releases the socket and a second call is a no-op (idempotency).
     */
    public function testCloseIsIdempotent(): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertInstanceOf(Socket::class, $socket);
        /** @var Socket $socket */
        $transport = $this->bareTransport();
        $this->setSocket($transport, $socket);

        $transport->close();
        self::assertFalse($transport->isOpen());

        $transport->close(); // must not raise
    }

    /**
     * floor() returned a float for the 'sec' field; socket option arrays expect
     * ints. millisecToSolArray() must return integer seconds.
     */
    public function testMillisecToSolArrayReturnsIntegerSeconds(): void
    {
        $transport = $this->bareTransport();
        $method    = new ReflectionMethod(SocketTransport::class, 'millisecToSolArray');
        $method->setAccessible(true);

        $result = $method->invoke($transport, 1500);
        /** @var array{sec: int, usec: int} $result */
        self::assertSame(['sec' => 1, 'usec' => 500000], $result);
        self::assertIsInt($result['sec']);
    }

    private function bareTransport(): SocketTransport
    {
        /** @var SocketTransport $t */
        $t = (new ReflectionClass(SocketTransport::class))->newInstanceWithoutConstructor();

        return $t;
    }

    private function setSocket(SocketTransport $transport, Socket $socket): void
    {
        $property = (new ReflectionClass(SocketTransport::class))->getProperty('socket');
        $property->setAccessible(true);
        $property->setValue($transport, $socket);
    }
}
