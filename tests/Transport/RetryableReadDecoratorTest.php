<?php

declare(strict_types=1);

namespace Transport;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Smpp\Contracts\Transport\ReadStrategyInterface;
use Smpp\Exceptions\SocketTemporaryFailureException;
use Smpp\Transport\RetryableReadDecorator;
use Socket;

class RetryableReadDecoratorTest extends TestCase
{
    private ?Socket $socket = null;

    protected function tearDown(): void
    {
        if ($this->socket instanceof Socket) {
            @socket_close($this->socket);
        }
    }

    public function testRejectsNonPositiveMaxRetries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetryableReadDecorator($this->strategy(fn() => ''), 0);
    }

    public function testRetriesThenSucceeds(): void
    {
        $calls    = 0;
        $strategy = $this->strategy(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new SocketTemporaryFailureException('retry me');
            }
            return 'data';
        });

        $result = (new RetryableReadDecorator($strategy, 3, 0))->read($this->socket(), 4);

        self::assertSame('data', $result);
        self::assertSame(2, $calls);
    }

    public function testRethrowsAfterExhaustingRetries(): void
    {
        $strategy = $this->strategy(function () {
            throw new SocketTemporaryFailureException('always fails');
        });

        $this->expectException(SocketTemporaryFailureException::class);
        (new RetryableReadDecorator($strategy, 3, 0))->read($this->socket(), 4);
    }

    private function strategy(callable $behaviour): ReadStrategyInterface
    {
        return new class($behaviour) implements ReadStrategyInterface {
            /** @var callable */
            private $behaviour;

            public function __construct(callable $behaviour)
            {
                $this->behaviour = $behaviour;
            }

            public function read(Socket $socket, int $length): string
            {
                return (string) ($this->behaviour)();
            }
        };
    }

    private function socket(): Socket
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        self::assertInstanceOf(Socket::class, $socket);
        /** @var Socket $socket */
        $this->socket = $socket;

        return $socket;
    }
}
