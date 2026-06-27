<?php

declare(strict_types=1);

namespace Transport;

use PHPUnit\Framework\TestCase;
use Smpp\Exceptions\ClosedTransportException;
use Smpp\Transport\NonBlockingReadStrategy;
use Socket;

class NonBlockingReadStrategyTest extends TestCase
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

    /**
     * When the peer closes the connection, recv() returns 0 bytes and the
     * socket stays permanently readable in socket_select(). Before the fix the
     * read loop spun forever (silent 100% CPU hang); it must now fail fast with
     * a ClosedTransportException.
     *
     * NOTE: without the fix this test would hang. The suite is run under an
     * external `timeout` in CI/locally to surface that as a failure.
     */
    public function testReadThrowsWhenPeerClosesConnection(): void
    {
        $local = $this->connectedLocalSocket();
        socket_close($this->pair[1]); // peer sends FIN
        $this->pair = [$local];       // remote already closed

        $this->expectException(ClosedTransportException::class);

        (new NonBlockingReadStrategy())->read($local, 16);
    }

    /**
     * Regression guard: a complete read still returns the requested bytes.
     */
    public function testReadReturnsRequestedBytes(): void
    {
        $local = $this->connectedLocalSocket();
        socket_write($this->pair[1], 'ABCD');

        self::assertSame('ABCD', (new NonBlockingReadStrategy())->read($local, 4));
    }

    /**
     * Creates a connected socket pair, sets the receive timeout the strategy
     * reads from SO_RCVTIMEO, and returns the local end. $this->pair holds both.
     */
    private function connectedLocalSocket(): Socket
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->pair = $pair;

        socket_set_option($pair[0], SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);

        return $pair[0];
    }
}
