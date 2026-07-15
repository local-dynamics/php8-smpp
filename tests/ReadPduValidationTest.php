<?php

declare(strict_types=1);

namespace Smpp\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Smpp\Client;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Exceptions\PDUParseException;
use Smpp\Pdu\Pdu;
use Smpp\Protocol\Command;

class ReadPduValidationTest extends TestCase
{
    /**
     * A command_length below the 16-byte header size (corrupt/desynced stream)
     * would otherwise make $bodyLength negative, silently skip the body read,
     * and let a bogus PDU through.
     */
    public function testReadPduRejectsCommandLengthBelowHeader(): void
    {
        $this->expectException(PDUParseException::class);
        $this->invokeReadPdu($this->header(5));
    }

    /**
     * An absurd command_length (typical of a stream desync) must be rejected
     * up front instead of being passed to read() as a huge blocking size,
     * which would freeze the caller.
     */
    public function testReadPduRejectsAbsurdCommandLength(): void
    {
        $this->expectException(PDUParseException::class);
        $this->invokeReadPdu($this->header(0xFFFFFFFF));
    }

    /**
     * Regression guard: a well-formed body-less PDU (command_length == 16) is
     * still parsed normally.
     */
    public function testReadPduAcceptsValidBodylessPdu(): void
    {
        $pdu = $this->invokeReadPdu($this->header(16));

        self::assertInstanceOf(Pdu::class, $pdu);
        /** @var Pdu $pdu */
        self::assertSame(Command::ENQUIRE_LINK, $pdu->getId());
    }

    /**
     * A transport that violates the read() contract with a short read (fewer
     * bytes than requested, but not zero) must not hand a truncated body to
     * the parser — that desyncs the stream and surfaces later as confusing
     * "Unexpected end of PDU body" bursts.
     */
    public function testReadPduRejectsShortBodyRead(): void
    {
        $this->expectException(PDUParseException::class);
        $this->expectExceptionMessage('Incomplete PDU body: expected 498 bytes but got 107');

        // command_length 514 => body of 498 bytes, but transport delivers only 107
        $this->invokeReadPdu($this->header(514), str_repeat("\x00", 107));
    }

    /**
     * Regression guard: a PDU whose body arrives complete is parsed normally.
     */
    public function testReadPduAcceptsCompleteBody(): void
    {
        $body = str_repeat("\x2A", 20);
        $pdu  = $this->invokeReadPdu($this->header(36), $body);

        self::assertInstanceOf(Pdu::class, $pdu);
        /** @var Pdu $pdu */
        self::assertSame($body, $pdu->getBody());
    }

    /**
     * 16-byte header with the given command_length; other fields are fixed.
     */
    private function header(int $commandLength): string
    {
        return pack('NNNN', $commandLength, Command::ENQUIRE_LINK, 0, 1);
    }

    private function invokeReadPdu(string $header, string $body = ''): mixed
    {
        $transport = new class($header, $body) implements TransportInterface {
            public function __construct(private string $header, private string $body)
            {
            }

            public function read(int $length): string
            {
                // First (header) read returns the header bytes; any body read
                // returns the configured body verbatim — possibly shorter than
                // requested, to simulate a contract-violating short read.
                return $length === 16 ? $this->header : $this->body;
            }

            public function open(): void {}
            public function isOpen(): bool { return true; }
            public function close(): void {}
            public function write(string $data, int $length): void {}
            public function hasData(): bool { return false; }
        };

        $client = new Client($transport, 'sysid', 'secret');
        $method = new ReflectionMethod(Client::class, 'readPDU');
        $method->setAccessible(true);

        return $method->invoke($client);
    }
}
