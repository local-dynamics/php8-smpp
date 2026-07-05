<?php

declare(strict_types=1);

namespace Smpp\Tests\Windowing;

use PHPUnit\Framework\TestCase;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Exceptions\SmppException;
use Smpp\Pdu\Address;
use Smpp\Protocol\Command;
use Smpp\Smpp;
use Smpp\Windowing\WindowedClient;

/**
 * A simple in-memory transport that records every write() call so that tests
 * can assert on the raw PDU bytes without any network I/O.
 */
final class RecordingTransport implements TransportInterface
{
    /** @var string[] */
    public array $written = [];

    public function open(): void {}

    public function isOpen(): bool
    {
        return true;
    }

    public function close(): void {}

    public function read(int $length): string
    {
        return '';
    }

    public function write(string $data, int $length): void
    {
        $this->written[] = $data;
    }

    public function hasData(): bool
    {
        return false;
    }
}

class WindowedClientSubmitTest extends TestCase
{
    public function testSubmitAsyncWritesOnePduAndConsumesCapacity(): void
    {
        $transport = new RecordingTransport();
        $client = new WindowedClient($transport, 'sysid', 'secret');
        $client->config->setWindowSize(2);

        self::assertSame(0, $client->pendingCount());
        self::assertTrue($client->canSubmit(1));

        $client->submitAsync(42, $this->addr('111'), $this->addr('222'), 'hi');

        self::assertSame(1, $client->pendingCount());
        self::assertCount(1, $transport->written);
        // command_id of the written PDU is SUBMIT_SM
        $header = unpack('Nlen/Nid/Nstatus/Nseq', substr($transport->written[0], 0, 16));
        self::assertNotFalse($header);
        self::assertSame(Command::SUBMIT_SM, $header['id']);
    }

    public function testSubmitAsyncThrowsWhenWindowFull(): void
    {
        $transport = new RecordingTransport();
        $client = new WindowedClient($transport, 'sysid', 'secret');
        $client->config->setWindowSize(1);

        $client->submitAsync(1, $this->addr('111'), $this->addr('222'), 'a');
        self::assertFalse($client->canSubmit(1));

        $this->expectException(SmppException::class);
        $client->submitAsync(2, $this->addr('111'), $this->addr('222'), 'b');
    }

    public function testMultipartMessageIsAcceptedAtomically(): void
    {
        $transport = new RecordingTransport();
        $client = new WindowedClient($transport, 'sysid', 'secret');
        $client->config->setWindowSize(10);

        $client->submitAsync(7, $this->addr('111'), $this->addr('222'), str_repeat('a', 400));

        self::assertGreaterThan(1, $client->pendingCount());
        self::assertSame($client->pendingCount(), count($transport->written));
    }

    private function addr(string $value): Address
    {
        return new Address($value, Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);
    }
}
