<?php

declare(strict_types=1);

namespace Smpp\Transport;


use Exception;
use Smpp\Contracts\Transport\ReadStrategyInterface;
use Smpp\Contracts\Transport\RetryableExceptionInterface;
use Smpp\Exceptions\SocketTransportException;
use Socket;

class RetryableReadDecorator implements ReadStrategyInterface
{
    public function __construct(
        private ReadStrategyInterface $strategy,
        private int $maxRetries = 3,
        private int $delayMs = 100,
    )
    {
        // maxRetries < 1 would skip the loop entirely and always hit the final
        // throw without ever attempting a read, which is never useful.
        if ($maxRetries < 1) {
            throw new \InvalidArgumentException('maxRetries must be at least 1');
        }
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function read(Socket $socket, int $length): string
    {
        for ($attempt = 0; $attempt < $this->maxRetries; $attempt++) {
            try {
                return $this->strategy->read($socket, $length);
            } catch (RetryableExceptionInterface $e) {
                if ($attempt === $this->maxRetries - 1) {
                    throw $e;
                }
                usleep($this->delayMs * 1000);
            }
        }

        // Unreachable at runtime (maxRetries >= 1 guarantees the loop runs and
        // either returns or rethrows), but required so every path returns/throws.
        throw new SocketTransportException('Read operation failed'); // @codeCoverageIgnore
    }
}