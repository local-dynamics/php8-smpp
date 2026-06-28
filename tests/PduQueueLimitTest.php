<?php

declare(strict_types=1);

namespace Smpp\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Smpp\Client;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Exceptions\SmppException;
use Smpp\Pdu\Pdu;
use Smpp\Protocol\Command;

class PduQueueLimitTest extends TestCase
{
    /**
     * The PDU queue must be bounded: a peer streaming unexpected PDUs must not
     * be able to grow it without limit and exhaust memory.
     */
    public function testEnqueueThrowsWhenQueueIsFull(): void
    {
        $client  = $this->client();
        $enqueue = new ReflectionMethod(Client::class, 'enqueuePdu');
        $enqueue->setAccessible(true);
        $pdu = new Pdu(Command::DELIVER_SM, 0, 1, '');

        $this->expectException(SmppException::class);
        for ($i = 0; $i <= 1000; $i++) { // 1001 pushes; the limit is 1000
            $enqueue->invoke($client, $pdu);
        }
    }

    public function testEnqueueAcceptsPdusBelowLimit(): void
    {
        $client  = $this->client();
        $enqueue = new ReflectionMethod(Client::class, 'enqueuePdu');
        $enqueue->setAccessible(true);
        $pdu = new Pdu(Command::DELIVER_SM, 0, 1, '');

        for ($i = 0; $i < 10; $i++) {
            $enqueue->invoke($client, $pdu);
        }

        self::assertTrue(true); // reached without exception
    }

    private function client(): Client
    {
        $transport = new class implements TransportInterface {
            public function open(): void {}
            public function isOpen(): bool { return true; }
            public function close(): void {}
            public function read(int $length): string { return ''; }
            public function write(string $data, int $length): void {}
            public function hasData(): bool { return false; }
        };

        return new Client($transport, 'sysid', 'secret');
    }
}
