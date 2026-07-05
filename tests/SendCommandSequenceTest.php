<?php

declare(strict_types=1);

namespace Smpp\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Smpp\Client;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Exceptions\SmppException;
use Smpp\Protocol\Command;
use Smpp\Protocol\CommandStatus;

class SendCommandSequenceTest extends TestCase
{
    /**
     * The sequence number must advance even when the SMSC answers with a
     * non-OK status. Before the fix it was only incremented on success, so the
     * next request reused the same sequence number — which SMPP forbids and
     * some SMSCs reject.
     */
    public function testSequenceNumberAdvancesOnErrorStatus(): void
    {
        $client = $this->clientWithResponseStatus(0x0000000C); // non-OK

        try {
            $this->sendCommand($client);
            self::fail('Expected SmppException on non-OK status');
        } catch (SmppException) {
            // expected
        }

        self::assertSame(2, $this->sequenceNumber($client));
    }

    /**
     * Regression guard: still advances on success.
     */
    public function testSequenceNumberAdvancesOnSuccess(): void
    {
        $client = $this->clientWithResponseStatus(CommandStatus::ESME_ROK);

        $this->sendCommand($client);

        self::assertSame(2, $this->sequenceNumber($client));
    }

    private function sendCommand(Client $client): void
    {
        $method = new ReflectionMethod(Client::class, 'sendCommand');
        $method->setAccessible(true);
        $method->invoke($client, Command::ENQUIRE_LINK, '');
    }

    private function sequenceNumber(Client $client): int
    {
        $property = new ReflectionProperty(Client::class, 'sequenceNumber');
        $property->setAccessible(true);

        /** @var int $value */
        $value = $property->getValue($client);
        return intval($value);
    }

    private function clientWithResponseStatus(int $status): Client
    {
        // 16-byte body-less ENQUIRE_LINK_RESP with sequence 1 and the given status
        $header = pack('NNNN', 16, Command::ENQUIRE_LINK_RESP, $status, 1);

        $transport = new class($header) implements TransportInterface {
            public function __construct(private string $header)
            {
            }

            public function read(int $length): string
            {
                return $length === 16 ? $this->header : '';
            }

            public function open(): void {}
            public function isOpen(): bool { return true; }
            public function close(): void {}
            public function write(string $data, int $length): void {}
            public function hasData(): bool { return false; }
        };

        return new Client($transport, 'sysid', 'secret');
    }
}
