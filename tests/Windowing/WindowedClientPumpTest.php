<?php

declare(strict_types=1);

namespace Smpp\Tests\Windowing;

use PHPUnit\Framework\TestCase;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Pdu\Address;
use Smpp\Protocol\Command;
use Smpp\Protocol\CommandStatus;
use Smpp\Smpp;
use Smpp\Windowing\WindowedClient;

class WindowedClientPumpTest extends TestCase
{
    public function testCompletesGroupOnSubmitSmResp(): void
    {
        $transport = new ScriptedTransport();
        $now = 1000.0;
        $client = new WindowedClient($transport, 'sysid', 'secret', function () use (&$now): float {
            return $now;
        });
        $client->config->setWindowSize(5);

        $client->submitAsync('row-1', $this->addr('111'), $this->addr('222'), 'hi'); // sequence 1

        // SMSC replies: submit_sm_resp, sequence 1, ESME_ROK, message id "MID-1\0"
        $transport->queue($this->pdu(Command::SUBMIT_SM_RESP, CommandStatus::ESME_ROK, 1, "MID-1\0"));

        $result = $client->pump();

        self::assertCount(1, $result->completed);
        self::assertTrue($result->completed[0]->success);
        self::assertSame('row-1', $result->completed[0]->context);
        self::assertSame('MID-1', rtrim($result->completed[0]->messageId, "\0"));
        self::assertSame(0, $client->pendingCount());
    }

    public function testGenericNackFailsGroup(): void
    {
        $transport = new ScriptedTransport();
        $client = new WindowedClient($transport, 'sysid', 'secret');
        $client->config->setWindowSize(5);
        $client->submitAsync('row-1', $this->addr('111'), $this->addr('222'), 'hi'); // sequence 1

        $transport->queue($this->pdu(Command::GENERIC_NACK, 0x0000000C, 1, ''));

        $result = $client->pump();
        self::assertCount(1, $result->completed);
        self::assertFalse($result->completed[0]->success);
        self::assertSame('smsc_error', $result->completed[0]->errorReason);
    }

    public function testDeliverSmIsReturnedAndAcked(): void
    {
        $transport = new ScriptedTransport();
        $client = new WindowedClient($transport, 'sysid', 'secret');

        // Minimal deliver_sm body the parser accepts. Reuse a real submit-style body:
        $deliverBody = $this->minimalDeliverSmBody();
        $transport->queue($this->pdu(Command::DELIVER_SM, CommandStatus::ESME_ROK, 77, $deliverBody));

        $result = $client->pump();

        self::assertCount(1, $result->incoming);
        // deliver_sm_resp (command_id 0x80000005) was written back with sequence 77
        self::assertNotEmpty($transport->written);
        $header = unpack('Nlen/Nid/Nstatus/Nseq', substr($transport->written[0], 0, 16));
        self::assertNotFalse($header);
        self::assertSame(Command::DELIVER_SM_RESP, $header['id']);
        self::assertSame(77, $header['seq']);
    }

    public function testEnquireLinkIsAnswered(): void
    {
        $transport = new ScriptedTransport();
        $client = new WindowedClient($transport, 'sysid', 'secret');
        $transport->queue($this->pdu(Command::ENQUIRE_LINK, CommandStatus::ESME_ROK, 55, ''));

        $result = $client->pump();

        self::assertSame([], $result->completed);
        self::assertNotEmpty($transport->written);
        $header = unpack('Nlen/Nid/Nstatus/Nseq', substr($transport->written[0], 0, 16));
        self::assertNotFalse($header);
        self::assertSame(Command::ENQUIRE_LINK_RESP, $header['id']);
        self::assertSame(55, $header['seq']);
    }

    public function testTimeoutSurfacesWhenNoResponse(): void
    {
        $transport = new ScriptedTransport();
        $now = 1000.0;
        $client = new WindowedClient($transport, 'sysid', 'secret', function () use (&$now): float {
            return $now;
        });
        $client->config->setWindowSize(5)->setWindowTimeoutMs(30000);
        $client->submitAsync('row-1', $this->addr('111'), $this->addr('222'), 'hi');

        // No response queued; advance clock past the timeout.
        $now = 1031.0;
        $result = $client->pump();

        self::assertCount(1, $result->completed);
        self::assertSame('timeout', $result->completed[0]->errorReason);
        self::assertSame(0, $client->pendingCount());
    }

    public function testReorderedRespMatchesCorrectGroup(): void
    {
        $transport = new ScriptedTransport();
        $client = new WindowedClient($transport, 'sysid', 'secret');
        $client->config->setWindowSize(5);
        $client->submitAsync('row-1', $this->addr('111'), $this->addr('222'), 'a'); // seq 1
        $client->submitAsync('row-2', $this->addr('111'), $this->addr('222'), 'b'); // seq 2

        // Answer seq 2 first, then seq 1.
        $transport->queue($this->pdu(Command::SUBMIT_SM_RESP, CommandStatus::ESME_ROK, 2, "MID-2\0"));
        $transport->queue($this->pdu(Command::SUBMIT_SM_RESP, CommandStatus::ESME_ROK, 1, "MID-1\0"));

        $result = $client->pump();

        $byContext = [];
        foreach ($result->completed as $r) {
            $byContext[$r->context] = rtrim($r->messageId, "\0");
        }
        self::assertSame(['row-1' => 'MID-1', 'row-2' => 'MID-2'], $byContext);
    }

    private function addr(string $value): Address
    {
        return new Address($value, Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);
    }

    private function pdu(int $id, int $status, int $sequence, string $body): string
    {
        $length = 16 + strlen($body);
        return pack('NNNN', $length, $id, $status, $sequence) . $body;
    }

    /**
     * A deliver_sm body with the mandatory fields the PDUParser reads. Built the
     * same way submit_sm bodies are, which the parser accepts for deliver_sm.
     */
    private function minimalDeliverSmBody(): string
    {
        // service_type(1 NUL) + src ton/npi/addr + dst ton/npi/addr + esm/proto/prio
        // + sched(1 NUL) + validity(1 NUL) + regdlvr/replace/coding/defmsg
        // + sm_length + short_message
        $sm = 'ping';
        return pack(
            'a1cca4cca4ccca1a1ccccca' . strlen($sm),
            '',        // service_type
            0, 0, '111',   // source ton, npi, addr (3 chars + NUL via a4)
            0, 0, '222',   // dest ton, npi, addr
            0,         // esm_class
            0,         // protocol_id
            0,         // priority_flag
            '',        // schedule_delivery_time
            '',        // validity_period
            0,         // registered_delivery
            0,         // replace_if_present
            0,         // data_coding
            0,         // sm_default_msg_id
            strlen($sm),
            $sm
        );
    }
}

/**
 * Transport that serves a scripted stream of bytes and records writes.
 */
class ScriptedTransport implements TransportInterface
{
    private string $buffer = '';
    /** @var string[] */
    public array $written = [];

    public function queue(string $pduBytes): void
    {
        $this->buffer .= $pduBytes;
    }

    public function open(): void {}
    public function isOpen(): bool { return true; }
    public function close(): void {}
    public function hasData(): bool { return $this->buffer !== ''; }

    public function read(int $length): string
    {
        if ($length <= 0 || $this->buffer === '') {
            return '';
        }
        $chunk = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        return $chunk;
    }

    public function write(string $data, int $length): void
    {
        $this->written[] = $data;
    }
}
