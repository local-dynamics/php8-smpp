<?php

declare(strict_types=1);

namespace Protocol;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Smpp\Pdu\PDUHeader;
use Smpp\Protocol\Command;
use Smpp\Protocol\CommandStatus;
use Smpp\Protocol\PDUBuilder;

class PDUBuilderTest extends TestCase
{
    /**
     * enquire_link_resp carries no body (SMPP v3.4 §4.11.2). The encoded PDU
     * must be exactly 16 bytes (header only) and its command_length field must
     * read 16 — a "\x00" body previously produced a 17-byte PDU that strict
     * SMSCs reject.
     */
    public function testEnquireLinkResponseHasNoBody(): void
    {
        $logger  = new NullLogger();
        $builder = new PDUBuilder($logger);

        $pdu = $builder->getEnquireLinkResponse(42);

        self::assertSame(PDUHeader::PDU_HEADER_LENGTH, $pdu->getLength());
        self::assertSame(PDUHeader::PDU_HEADER_LENGTH, strlen($pdu->getData()));

        /** @var array{command_length: int, command_id: int, command_status: int, sequence: int} $header */
        $header = unpack('Ncommand_length/Ncommand_id/Ncommand_status/Nsequence', $pdu->getData());

        self::assertSame(16, $header['command_length']);
        self::assertSame(Command::ENQUIRE_LINK_RESP, $header['command_id']);
        self::assertSame(CommandStatus::ESME_ROK, $header['command_status']);
        self::assertSame(42, $header['sequence']);
    }
}
