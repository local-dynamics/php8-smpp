<?php

declare(strict_types=1);

namespace Helpers;

use PHPUnit\Framework\TestCase;
use Smpp\helpers\GsmEncoderHelper;

class GsmEncoderHelperTest extends TestCase
{
    /**
     * The GSM '@' character is septet 0x00. When it is the last character the
     * final packed byte is 0, but it still belongs to the message. The old
     * guard (`$currentByte > 0`) dropped it; the offset-based guard keeps it.
     */
    public function testPack7bitKeepsTrailingAtSign(): void
    {
        // "A@" -> septets [0x41, 0x00] -> 2 packed octets, the last being 0x00.
        self::assertSame("\x41\x00", GsmEncoderHelper::pack7bit("\x41\x00"));
    }

    /**
     * A message consisting solely of '@' must not vanish entirely.
     */
    public function testPack7bitKeepsLoneAtSign(): void
    {
        self::assertSame("\x00", GsmEncoderHelper::pack7bit("\x00"));
    }

    /**
     * Regression guards: inputs that do not end on a zero byte are unaffected.
     */
    public function testPack7bitPacksPlainText(): void
    {
        // "AB" -> septets [0x41, 0x42] -> [0x41, 0x21]
        self::assertSame("\x41\x21", GsmEncoderHelper::pack7bit("\x41\x42"));
    }

    public function testPack7bitEmptyStringStaysEmpty(): void
    {
        self::assertSame('', GsmEncoderHelper::pack7bit(''));
    }

    /**
     * Eight septets fill exactly seven octets with no leftover bits, so no
     * trailing byte is appended regardless of the guard.
     */
    public function testPack7bitFullByteBoundary(): void
    {
        self::assertSame(7, strlen(GsmEncoderHelper::pack7bit(str_repeat("\x41", 8))));
    }
}
