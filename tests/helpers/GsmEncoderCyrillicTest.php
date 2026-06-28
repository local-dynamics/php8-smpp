<?php

declare(strict_types=1);

namespace Helpers;

use PHPUnit\Framework\TestCase;
use Smpp\helpers\GsmEncoderHelper;

class GsmEncoderCyrillicTest extends TestCase
{
    public function testAsciiAndGsmCharactersStillEncode(): void
    {
        self::assertSame('Hello', GsmEncoderHelper::utf8ToGsm0338('Hello'));
        self::assertSame("\x00", GsmEncoderHelper::utf8ToGsm0338('@'));
        self::assertSame("\x1B\x65", GsmEncoderHelper::utf8ToGsm0338('€'));
    }

    /**
     * Cyrillic has no valid GSM 03.38 representation. It must collapse to '?'
     * rather than emit the bogus two-byte Unicode-code-point sequences the old
     * mappings produced (e.g. "\x04\x10"), which a SMSC would misread.
     */
    public function testCyrillicCollapsesToQuestionMarks(): void
    {
        self::assertSame('?', GsmEncoderHelper::utf8ToGsm0338('А'));
        self::assertSame('??????', GsmEncoderHelper::utf8ToGsm0338('Привет'));
    }

    /**
     * No raw multi-byte UTF-8 may leak into the output.
     */
    public function testNoHighBytesLeakIntoOutput(): void
    {
        $out = GsmEncoderHelper::utf8ToGsm0338('Hi Привет 你好');
        self::assertSame(0, preg_match('/[\x80-\xFF]/', $out));
    }

    public function testCountMatchesEncodedSeptetLength(): void
    {
        self::assertSame(5, GsmEncoderHelper::countGsm0338Length('Hello'));
        self::assertSame(2, GsmEncoderHelper::countGsm0338Length('€'));   // escaped → 2 septets
        self::assertSame(1, GsmEncoderHelper::countGsm0338Length('@'));   // 0x00 → 1 septet
        self::assertSame(6, GsmEncoderHelper::countGsm0338Length('Привет')); // 6 × '?'
    }
}
