<?php

declare(strict_types=1);

namespace Utils\Network;

use PHPUnit\Framework\TestCase;
use Smpp\Exceptions\SmppInvalidArgumentException;
use Smpp\Utils\Network\DSNParser;

class DSNParserPortTest extends TestCase
{
    /**
     * A non-numeric port was cast with (int), turning "abc" into 0 and
     * reporting the misleading "Port must be between 1 and 65535, 0 given".
     * The message must name the actual offending value instead.
     */
    public function testNonNumericIpv4PortReportsNumericError(): void
    {
        $this->expectException(SmppInvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/numeric/i');

        DSNParser::parseDSNEntries('127.0.0.1:abc');
    }

    public function testNonNumericIpv6PortReportsNumericError(): void
    {
        $this->expectException(SmppInvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/numeric/i');

        DSNParser::parseDSNEntries('[::1]:xyz');
    }

    public function testValidPortStillParses(): void
    {
        $entries = DSNParser::parseDSNEntries('127.0.0.1:2775');

        self::assertCount(1, $entries);
        self::assertSame(2775, $entries[0]->getPort());
    }
}
