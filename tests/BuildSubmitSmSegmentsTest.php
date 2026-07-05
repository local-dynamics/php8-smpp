<?php

declare(strict_types=1);

namespace Smpp\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Smpp\Client;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Pdu\Address;
use Smpp\Smpp;

class BuildSubmitSmSegmentsTest extends TestCase
{
    public function testSinglePartMessageYieldsOneSegment(): void
    {
        $segments = $this->segments(str_repeat('a', 100));
        self::assertCount(1, $segments);
    }

    public function testLongDefaultMessageIsSplitIntoMultipleSegments(): void
    {
        // > 160 GSM chars with the default CSMS method (16-bit SAR tags) -> multiple segments
        $segments = $this->segments(str_repeat('a', 400));
        self::assertGreaterThan(1, count($segments));
        foreach ($segments as $segment) {
            self::assertIsString($segment);
            self::assertNotSame('', $segment);
        }
    }

    /**
     * @return string[]
     */
    private function segments(string $message): array
    {
        $client = new Client($this->transport(), 'sysid', 'secret');

        $method = new ReflectionMethod(Client::class, 'buildSubmitSmSegments');
        $method->setAccessible(true);

        /** @var string[] $result */
        $result = $method->invoke(
            $client,
            new Address('12345', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164),
            new Address('67890', Smpp::TON_INTERNATIONAL, Smpp::NPI_E164),
            $message,
            null,
            Smpp::DATA_CODING_DEFAULT,
            0x00,
            null,
            null
        );

        return $result;
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
