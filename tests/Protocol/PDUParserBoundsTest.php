<?php

declare(strict_types=1);

namespace Protocol;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Smpp\Exceptions\PDUParseException;
use Smpp\Pdu\Tag;
use Smpp\Protocol\PDUParser;

class PDUParserBoundsTest extends TestCase
{
    /**
     * A trailing fragment shorter than a 4-byte TLV header must yield "no tag"
     * (false), not a corrupt Tag built from false-padded-to-zero bytes.
     * The first element is a dummy because parseTag()/getOctets() advance with
     * next() before reading.
     */
    public function testParseTagReturnsFalseOnShortHeader(): void
    {
        $ar = unpack('C*', "X\x12\x34\x00"); // dummy + 3 bytes (header needs 4)

        self::assertFalse($this->parseTag($ar));
    }

    public function testParseTagParsesCompleteTag(): void
    {
        $ar = unpack('C*', "X\x00\x05\x00\x02\xAA\xBB"); // dummy + id=5, len=2, value AABB

        $tag = $this->parseTag($ar);

        self::assertInstanceOf(Tag::class, $tag);
        /** @var Tag $tag */
        self::assertSame(5, $tag->getId());
        self::assertSame(2, $tag->getLength());
    }

    /**
     * getOctets() must fail on a buffer shorter than the requested length
     * instead of silently returning a truncated value.
     */
    public function testGetOctetsThrowsOnTruncatedBuffer(): void
    {
        $ar = unpack('C*', 'ABC'); // 3 bytes; first is skipped by next()

        $this->expectException(PDUParseException::class);
        $this->getOctets($ar, 5);
    }

    /**
     * @param array<int, int> $ar
     */
    private function parseTag(array &$ar): mixed
    {
        $method = new ReflectionMethod(PDUParser::class, 'parseTag');
        $method->setAccessible(true);

        return $method->invokeArgs($this->parser(), [&$ar]);
    }

    /**
     * @param array<int, int> $ar
     */
    private function getOctets(array &$ar, int $length): mixed
    {
        $method = new ReflectionMethod(PDUParser::class, 'getOctets');
        $method->setAccessible(true);

        return $method->invokeArgs($this->parser(), [&$ar, $length]);
    }

    private function parser(): PDUParser
    {
        $logger = new NullLogger();

        return new PDUParser($logger);
    }
}
