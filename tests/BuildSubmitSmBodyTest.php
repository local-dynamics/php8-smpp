<?php

declare(strict_types=1);

namespace Smpp\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Smpp\Client;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Pdu\Address;
use Smpp\Smpp;

class BuildSubmitSmBodyTest extends TestCase
{
    public function testBodyContainsSourceAndDestinationAndMessage(): void
    {
        $client = new Client($this->transport(), 'sysid', 'secret');

        $method = new ReflectionMethod(Client::class, 'buildSubmitSmBody');
        $method->setAccessible(true);

        /** @var string $body */
        $body = $method->invoke(
            $client,
            new Address('12345', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164),
            new Address('67890', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164),
            'hello',
            null,
            Smpp::DATA_CODING_DEFAULT,
            0x00,
            null,
            null,
            null
        );

        self::assertIsString($body);
        self::assertStringContainsString('12345', $body);
        self::assertStringContainsString('67890', $body);
        self::assertStringContainsString('hello', $body);
        // sm_length byte equals strlen('hello')
        self::assertStringContainsString(chr(5) . 'hello', $body);
    }

    private function transport(): TransportInterface
    {
        return new class implements TransportInterface {
            public function open(): void {}
            public function isOpen(): bool { return true; }
            public function close(): void {}
            public function read(int $length): string { return ''; }
            public function write(string $data, int $length): void {}
            public function hasData(): bool { return false; }
        };
    }
}
