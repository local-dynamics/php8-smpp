<?php

declare(strict_types=1);


namespace Smpp\Transport;


use Smpp\Contracts\Transport\ReadStrategyInterface;
use Smpp\Exceptions\ClosedTransportException;
use Smpp\Exceptions\SocketTemporaryFailureException;
use Smpp\Exceptions\SocketTransportException;
use Socket;

class NonBlockingReadStrategy implements ReadStrategyInterface
{

    /**
     * @inheritDoc
     * @throws SocketTemporaryFailureException
     * @throws SocketTransportException
     */
    public function read(Socket $socket, int $length): string
    {
        $datagram = "";
        $r        = 0;
        /**
         * @var false|array{sec: int, usec: int} $readTimeout
         */
        $readTimeout = socket_get_option($socket, SOL_SOCKET, SO_RCVTIMEO);
        if ($readTimeout === false) {
            throw new SocketTransportException("Read timeout is not set");
        }

        // Single deadline for the whole read. Applying the full timeout per
        // socket_select() iteration let a fragmented read take up to
        // (number of fragments) x timeout instead of one timeout overall.
        $deadline = microtime(true) + $readTimeout['sec'] + $readTimeout['usec'] / 1_000_000;

        while ($r < $length) {
            $buf           = '';
            $receivedBytes = socket_recv($socket, $buf, $length - $r, MSG_DONTWAIT);
            if ($receivedBytes === false) {
                $errorNumber = socket_last_error();
                // SOCKET_EWOULDBLOCK has same value (11)
                if ($errorNumber === SOCKET_EAGAIN) {
                    throw new SocketTemporaryFailureException('Resource temporarily unavailable');
                }
                throw new SocketTransportException(
                    'Could not read ' . $length . ' bytes from socket; ' . socket_strerror($errorNumber),
                    $errorNumber
                );
            }
            // recv() returns exactly 0 bytes when the peer has performed an
            // orderly shutdown (FIN). Such a socket stays permanently
            // "readable" in socket_select(), so without this guard the loop
            // would spin forever at 100% CPU, never advancing $r and never
            // timing out — the connection is dead, so fail fast instead.
            if ($receivedBytes === 0) {
                throw new ClosedTransportException('Connection closed by peer while reading from socket');
            }
            $r        += $receivedBytes;
            $datagram .= $buf;
            if ($r === $length) {
                return $datagram;
            }

            // wait for data to be available, up to the remaining deadline
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new SocketTransportException('Timed out waiting for data on socket');
            }
            $sec  = (int) $remaining;
            $usec = (int) (($remaining - $sec) * 1_000_000);

            $read   = [$socket];
            $write  = null;
            $except = [$socket];

            $selected = socket_select($read, $write, $except, $sec, $usec);
            if ($selected === false) {
                // A signal interrupted the wait (EINTR) — common in daemons that
                // handle SIGTERM/SIGHUP. Retry within the deadline instead of
                // tearing down the SMPP session.
                if (socket_last_error() === SOCKET_EINTR) {
                    continue;
                }
                throw new SocketTransportException(
                    'Could not examine socket; ' . socket_strerror(socket_last_error()),
                    socket_last_error()
                );
            }
            /** @var Socket[] $except */
            if (!empty($except)) {
                throw new SocketTransportException(
                    'Socket exception while waiting for data; ' . socket_strerror(socket_last_error()),
                    socket_last_error()
                );
            }
            /** @var Socket[] $read */
            if (empty($read)) {
                throw new SocketTransportException('Timed out waiting for data on socket');
            }
        }

        // for static analyzers
        return $datagram;
    }
}
