<?php

declare(strict_types=1);

namespace Smpp\Tests;

use PHPUnit\Framework\TestCase;
use Smpp\Client;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Exceptions\SmppException;
use Smpp\Pdu\Address;
use Smpp\Protocol\Command;
use Smpp\Protocol\CommandStatus;
use Smpp\Smpp;

class QueryStatusTest extends TestCase
{
    /**
     * A QUERY_SM_RESP body with a message_id terminator but no second
     * (final_date) terminator leaves strpos() returning false. Before the fix
     * the substr()/unpack() calls read from bogus offsets and returned garbage;
     * it must now throw instead.
     */
    public function testQueryStatusThrowsWhenFinalDateTerminatorMissing(): void
    {
        $client = $this->clientReturning("abc\0"); // only one null byte

        $this->expectException(SmppException::class);
        $client->queryStatus('abc', $this->source());
    }

    /**
     * Regression guard: a well-formed body still parses.
     */
    public function testQueryStatusParsesWellFormedResponse(): void
    {
        // message_id "id", empty final_date, message_state=5, error_code=0
        $client = $this->clientReturning("id\0\0" . chr(5) . chr(0));

        $result = $client->queryStatus('id', $this->source());

        self::assertIsArray($result);
        /** @var array<string, mixed> $result */
        self::assertSame('id', $result['message_id']);
        self::assertNull($result['final_date']);
        self::assertSame(5, $result['message_state']);
        self::assertSame(0, $result['error_code']);
    }

    private function source(): Address
    {
        return new Address('1234', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164);
    }

    private function clientReturning(string $body): Client
    {
        $header = pack('NNNN', 16 + strlen($body), Command::QUERY_SM_RESP, CommandStatus::ESME_ROK, 1);

        $transport = new class($header, $body) implements TransportInterface {
            public function __construct(private string $header, private string $body)
            {
            }

            public function read(int $length): string
            {
                return $length === 16 ? $this->header : $this->body;
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
