<?php

declare(strict_types=1);

namespace Smpp\Tests;

use PHPUnit\Framework\TestCase;
use Smpp\Client;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Exceptions\SocketTransportException;

/**
 * In-memory transport double that records whether close() was invoked and can
 * be configured to fail on write (simulating a broken connection).
 */
class FakeTransport implements TransportInterface
{
    public bool $open   = true;
    public bool $closed = false;

    public function __construct(private bool $failOnWrite = false)
    {
    }

    public function open(): void
    {
        $this->open = true;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->open   = false;
    }

    public function read(int $length): string
    {
        throw new SocketTransportException('connection reset');
    }

    public function write(string $data, int $length): void
    {
        if ($this->failOnWrite) {
            throw new SocketTransportException('broken pipe');
        }
    }

    public function hasData(): bool
    {
        return false;
    }
}

class ClientTest extends TestCase
{
    /**
     * close() must always release the transport, even when the UNBIND exchange
     * fails (network error, timeout, non-OK status). Otherwise the underlying
     * socket leaks on every failed close()/reconnect().
     */
    public function testCloseClosesTransportEvenWhenUnbindFails(): void
    {
        $transport = new FakeTransport(failOnWrite: true);
        $client    = new Client($transport, 'sysid', 'secret');

        $client->close();

        self::assertTrue($transport->closed, 'transport->close() must be called when UNBIND fails');
    }

    /**
     * Closing an already-closed transport is a no-op and must not touch it.
     */
    public function testCloseIsNoOpWhenTransportNotOpen(): void
    {
        $transport       = new FakeTransport(failOnWrite: true);
        $transport->open = false;
        $client          = new Client($transport, 'sysid', 'secret');

        $client->close();

        self::assertFalse($transport->closed, 'transport->close() must not be called when not open');
    }
}
