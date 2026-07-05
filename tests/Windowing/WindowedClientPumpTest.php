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
        self::assertSame('MID-1', $result->completed[0]->messageId);
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
            $byContext[$r->context] = $r->messageId;
        }
        self::assertSame(['row-1' => 'MID-1', 'row-2' => 'MID-2'], $byContext);
    }

    public function testMultiSegmentGroupCompletesOnlyWhenAllSegmentsAcked(): void
    {
        // str_repeat('a', 400) with DATA_CODING_DEFAULT and the default CSMS_16BIT_TAGS
        // method (csmsSplit=152) yields 3 segments: [152, 152, 96] chars → sequences 1, 2, 3.
        $transport = new ScriptedTransport();
        $client = new WindowedClient($transport, 'sysid', 'secret');
        $client->config->setWindowSize(10);

        $client->submitAsync('row-csms', $this->addr('111'), $this->addr('222'), str_repeat('a', 400));

        // Partial ack: only sequences 1 and 2 answered — group must NOT surface yet.
        $transport->queue($this->pdu(Command::SUBMIT_SM_RESP, CommandStatus::ESME_ROK, 1, "MID-1\0"));
        $transport->queue($this->pdu(Command::SUBMIT_SM_RESP, CommandStatus::ESME_ROK, 2, "MID-2\0"));

        $result = $client->pump();

        self::assertSame([], $result->completed, 'Group must not surface until all 3 segments are acked');
        self::assertGreaterThan(0, $client->pendingCount());

        // Final ack: sequence 3 answered — group must now complete.
        $transport->queue($this->pdu(Command::SUBMIT_SM_RESP, CommandStatus::ESME_ROK, 3, "MID-3\0"));

        $result = $client->pump();

        self::assertCount(1, $result->completed);
        self::assertTrue($result->completed[0]->success);
        self::assertSame('row-csms', $result->completed[0]->context);
        // lastMessageId() returns the last non-empty messageId across all segments (MID-3).
        self::assertSame('MID-3', $result->completed[0]->messageId);
        self::assertSame(0, $client->pendingCount());
    }

    /**
     * A deliver_sm whose esm_class has ESM_DELIVER_SMSC_RECEIPT set (0x04)
     * but whose message text does not match the delivery receipt regex causes
     * parseSMS() → parseSms() → DeliveryReceipt::parseDeliveryReceipt() to
     * throw SmppInvalidArgumentException.
     * pump() must catch this, log a warning, and continue the loop — the bad
     * PDU must be absent from `incoming`, and any submit_sm_resp queued after
     * it must still resolve its in-flight group.
     */
    public function testMalformedDeliverSmIsSkippedAndLoopContinues(): void
    {
        $transport = new ScriptedTransport();
        $now = 1000.0;
        $client = new WindowedClient($transport, 'sysid', 'secret', function () use (&$now): float {
            return $now;
        });
        $client->config->setWindowSize(5);

        // Submit one message; it occupies sequence number 1 in the window.
        $client->submitAsync('ctx-1', $this->addr('111'), $this->addr('222'), 'hello');

        // A deliver_sm whose esm_class=0x04 (delivery-receipt bit) but whose
        // body "bad receipt" does not satisfy the delivery-receipt regex.
        $transport->queue($this->pdu(Command::DELIVER_SM, CommandStatus::ESME_ROK, 99, $this->malformedDeliveryReceiptBody()));

        // A valid submit_sm_resp for the in-flight segment (sequence 1).
        $transport->queue($this->pdu(Command::SUBMIT_SM_RESP, CommandStatus::ESME_ROK, 1, "MID-1\0"));

        // Must not throw; bad PDU skipped; valid resp still completes the group.
        $result = $client->pump();

        self::assertSame([], $result->incoming, 'Malformed deliver_sm must not appear in incoming');
        self::assertCount(1, $result->completed, 'submit_sm_resp after the bad PDU must still complete its group');
        self::assertTrue($result->completed[0]->success);
        self::assertSame('ctx-1', $result->completed[0]->context);
        self::assertSame('MID-1', $result->completed[0]->messageId);
        self::assertSame(0, $client->pendingCount());
    }

    private function addr(string $value): Address
    {
        return new Address($value, Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);
    }

    /**
     * A deliver_sm body with esm_class=0x04 (ESM_DELIVER_SMSC_RECEIPT) and a
     * message that does NOT match the delivery-receipt regex, triggering a
     * SmppInvalidArgumentException in DeliveryReceipt::parseDeliveryReceipt().
     */
    private function malformedDeliveryReceiptBody(): string
    {
        $sm = 'bad receipt'; // no match for the id:/sub:/dlvrd:/... receipt regex
        return pack(
            'a1cca4cca4ccca1a1ccccca' . strlen($sm),
            '',
            0, 0, '111',
            0, 0, '222',
            0x04,       // esm_class = ESM_DELIVER_SMSC_RECEIPT
            0,
            0,
            '',
            '',
            0,
            0,
            0,
            0,
            strlen($sm),
            $sm
        );
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
