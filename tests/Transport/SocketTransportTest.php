<?php

declare(strict_types=1);

namespace Transport;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Smpp\Transport\SocketTransport;
use Socket;

class SocketTransportTest extends TestCase
{
    /** @var Socket[] */
    private array $pair = [];

    protected function tearDown(): void
    {
        foreach ($this->pair as $socket) {
            if ($socket instanceof Socket) {
                @socket_close($socket);
            }
        }
        $this->pair = [];
    }

    public function testIsOpenReturnsFalseWhenSocketNeverConstructed(): void
    {
        $transport = $this->makeTransport(); // no socket injected

        self::assertFalse($transport->isOpen());
    }

    public function testIsOpenReturnsTrueWhilePeerIsConnected(): void
    {
        [$local] = $this->connectedPair();
        $transport = $this->makeTransport($local);

        self::assertTrue($transport->isOpen());
    }

    /**
     * A TCP/stream FIN shows up as readability with 0 bytes, not as a socket
     * exception. isOpen() must detect the closed peer rather than reporting the
     * dead connection as open.
     */
    public function testIsOpenReturnsFalseAfterPeerClosed(): void
    {
        [$local, $remote] = $this->connectedPair();
        $transport = $this->makeTransport($local);

        socket_close($remote);
        $this->pair = [$local]; // remote already closed; avoid double close

        self::assertFalse($transport->isOpen());
    }

    /**
     * Pending inbound data must not be mistaken for a closed connection.
     */
    public function testIsOpenReturnsTrueWhenDataIsPending(): void
    {
        [$local, $remote] = $this->connectedPair();
        $transport = $this->makeTransport($local);

        socket_write($remote, 'x');

        self::assertTrue($transport->isOpen());
    }

    /**
     * @return array{0: Socket, 1: Socket}
     */
    private function connectedPair(): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->pair = $pair;

        return [$pair[0], $pair[1]];
    }

    private function makeTransport(?Socket $socket = null): SocketTransport
    {
        $reflection = new ReflectionClass(SocketTransport::class);
        /** @var SocketTransport $transport */
        $transport = $reflection->newInstanceWithoutConstructor();

        if ($socket !== null) {
            $property = $reflection->getProperty('socket');
            $property->setAccessible(true);
            $property->setValue($transport, $socket);
        }

        return $transport;
    }
}
