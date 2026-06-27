<?php

declare(strict_types=1);

namespace Transport;

use PHPUnit\Framework\TestCase;
use Smpp\Exceptions\SocketTransportException;
use Smpp\Transport\NonBlockingReadStrategy;
use Socket;

class NonBlockingReadStrategyTimeoutTest extends TestCase
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

    public function testReadReturnsRequestedBytes(): void
    {
        $local = $this->localSocket(['sec' => 1, 'usec' => 0]);
        socket_write($this->pair[1], 'ABCD');

        self::assertSame('ABCD', (new NonBlockingReadStrategy())->read($local, 4));
    }

    /**
     * When the requested bytes never fully arrive, the read must time out
     * roughly once (deadline-based), not once per partial fragment. With a
     * 250ms timeout the call must fail well under, say, 2 seconds.
     */
    public function testReadTimesOutCloseToConfiguredTimeout(): void
    {
        $local = $this->localSocket(['sec' => 0, 'usec' => 250000]);
        socket_write($this->pair[1], 'AB'); // 2 of 4 bytes, remainder never sent

        $start = microtime(true);
        try {
            (new NonBlockingReadStrategy())->read($local, 4);
            self::fail('Expected a timeout exception');
        } catch (SocketTransportException $e) {
            $elapsed = microtime(true) - $start;
            self::assertLessThan(2.0, $elapsed, 'read should honor a single deadline, not stack timeouts');
        }
    }

    /**
     * @param array{sec: int, usec: int} $timeout
     */
    private function localSocket(array $timeout): Socket
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->pair = $pair;

        socket_set_option($pair[0], SOL_SOCKET, SO_RCVTIMEO, $timeout);

        return $pair[0];
    }
}
