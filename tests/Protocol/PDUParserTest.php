<?php

declare(strict_types=1);

namespace Protocol;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Smpp\Pdu\Pdu;
use Smpp\Pdu\Sms;
use Smpp\Protocol\Command;
use Smpp\Protocol\PDUParser;
use Smpp\Smpp;

class PDUParserTest extends TestCase
{
    /**
     * UCS-2 (UTF-16BE) messages encode ASCII characters as 0x00 0xXX, so the
     * short_message field starts with a 0x00 byte. Reading it as a
     * null-terminated C-string truncated the whole message to "" and caused the
     * trailing bytes to be misparsed as TLV parameters. It must be read as a
     * fixed-length octet string instead.
     */
    public function testParseSmsKeepsUcs2MessageWithLeadingNullByte(): void
    {
        $message = "\x00H\x00i"; // "Hi" in UTF-16BE
        $sms     = $this->parse(Smpp::DATA_CODING_UCS2, $message);

        self::assertSame($message, $sms->message);
        self::assertSame('Hi', mb_convert_encoding($sms->message, 'UTF-8', 'UTF-16BE'));
    }

    /**
     * Raw binary payloads may contain 0x00 bytes anywhere; none of them must
     * terminate the read.
     */
    public function testParseSmsKeepsBinaryMessageWithEmbeddedNullBytes(): void
    {
        $message = "\x01\x00\x02\x00\x03"; // 5 octets incl. embedded nulls
        $sms     = $this->parse(Smpp::DATA_CODING_BINARY, $message);

        self::assertSame($message, $sms->message);
    }

    /**
     * Regression guard: default (text) coding keeps the existing C-string
     * behaviour for plain ASCII messages.
     */
    public function testParseSmsReadsDefaultCodingMessage(): void
    {
        $sms = $this->parse(Smpp::DATA_CODING_DEFAULT, 'Hello');

        self::assertSame('Hello', $sms->message);
    }

    private function parse(int $dataCoding, string $message): Sms
    {
        $logger = new NullLogger();
        $parser = new PDUParser($logger);

        $pdu = new Pdu(Command::DELIVER_SM, 0, 1, $this->buildSmsBody($dataCoding, $message));
        $sms = $parser->parseSms($pdu);

        self::assertInstanceOf(Sms::class, $sms);

        return $sms;
    }

    /**
     * Assembles a minimal but spec-shaped deliver_sm body. esm_class is 0x00 so
     * the PDU is parsed as a regular Sms (not a delivery receipt).
     */
    private function buildSmsBody(int $dataCoding, string $message): string
    {
        return "\x00"              // service_type (empty C-string)
            . "\x01\x01"           // source addr_ton / addr_npi
            . "123\x00"            // source_addr (C-string)
            . "\x01\x01"           // dest addr_ton / addr_npi
            . "456\x00"            // destination_addr (C-string)
            . "\x00"               // esm_class
            . "\x00"               // protocol_id
            . "\x00"               // priority_flag
            . "\x00"               // schedule_delivery_time (empty)
            . "\x00"               // validity_period (empty)
            . "\x00"               // registered_delivery
            . "\x00"               // replace_if_present_flag
            . chr($dataCoding)     // data_coding
            . "\x00"               // sm_default_msg_id
            . chr(strlen($message)) // sm_length
            . $message;            // short_message
    }
}
