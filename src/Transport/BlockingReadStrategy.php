<?php

declare(strict_types=1);


namespace Smpp\Transport;


use Smpp\Contracts\Transport\ReadStrategyInterface;
use Smpp\Exceptions\ClosedTransportException;
use Smpp\Exceptions\SocketTransportException;
use Socket;

class BlockingReadStrategy implements ReadStrategyInterface
{
    public function __construct(
        private int $timeoutMs
    )
    {

    }

    /**
     * @inheritDoc
     */
    public function read(Socket $socket, int $length): string
    {
        // Preserve the socket's current receive timeout: this strategy is used
        // as the fallback of HybridReadStrategy, whose primary
        // (NonBlockingReadStrategy) derives its socket_select() timeout from
        // SO_RCVTIMEO. Mutating it globally would silently change that timeout
        // for every later read.
        $originalTimeout = socket_get_option($socket, SOL_SOCKET, SO_RCVTIMEO);

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => intdiv($this->timeoutMs, 1000),
            'usec' => ($this->timeoutMs % 1000) * 1000,
        ]);

        try {
            // socket_recv() on a TCP stream may return fewer bytes than
            // requested, so loop until the exact number of bytes is read (the
            // interface contract). Returning a short read here desyncs the SMPP
            // PDU stream.
            $datagram  = '';
            $remaining = $length;
            while ($remaining > 0) {
                $buf      = '';
                $received = socket_recv($socket, $buf, $remaining, 0);

                if ($received === false) {
                    throw new SocketTransportException(
                        'Could not read ' . $length . ' bytes from socket; '
                        . socket_strerror(socket_last_error($socket)),
                        socket_last_error($socket)
                    );
                }

                if ($received === 0) {
                    throw new ClosedTransportException('Connection closed by peer while reading from socket');
                }

                $datagram  .= $buf;
                $remaining -= $received;
            }

            return $datagram;
        } finally {
            if (is_array($originalTimeout)) {
                socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, $originalTimeout);
            }
        }
    }
}
