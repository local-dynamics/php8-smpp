<?php

declare(strict_types=1);

namespace Transport;

use PHPUnit\Framework\TestCase;
use Smpp\Exceptions\SocketTransportException;
use Smpp\Transport\BlockingReadStrategy;
use Socket;

class BlockingReadStrategyTest extends TestCase
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

    public function testReadReturnsAllRequestedBytes(): void
    {
        [$local, $remote] = $this->pair();
        socket_write($remote, 'ABCD');

        self::assertSame('ABCD', (new BlockingReadStrategy(500))->read($local, 4));
    }

    /**
     * The read must loop until the full length is available. If only part of
     * the data arrives and no more follows, it must time out (and throw) rather
     * than return a short read — the old single-recv code returned the partial
     * bytes, desyncing the PDU stream.
     */
    public function testReadDoesNotReturnShortRead(): void
    {
        [$local, $remote] = $this->pair();
        socket_write($remote, 'AB'); // only 2 of the 4 requested bytes

        $this->expectException(SocketTransportException::class);
        (new BlockingReadStrategy(150))->read($local, 4);
    }

    /**
     * SO_RCVTIMEO must be restored after the read, so the strategy does not
     * leak its timeout onto subsequent reads on the same socket.
     */
    public function testReadRestoresReceiveTimeout(): void
    {
        [$local, $remote] = $this->pair();
        socket_set_option($local, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 750000]);
        socket_write($remote, 'XY');

        (new BlockingReadStrategy(500))->read($local, 2);

        $after = socket_get_option($local, SOL_SOCKET, SO_RCVTIMEO);
        /** @var array{sec: int, usec: int} $after */
        self::assertSame(0, $after['sec']);
        self::assertSame(750000, $after['usec']);
    }

    /**
     * @return array{0: Socket, 1: Socket}
     */
    private function pair(): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->pair = $pair;

        return [$pair[0], $pair[1]];
    }
}
