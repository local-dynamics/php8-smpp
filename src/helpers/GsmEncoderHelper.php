<?php


namespace Smpp\helpers;

/**
 * Class capable of encoding GSM 03.38 default alphabet and packing octets into septets as described by GSM 03.38.
 * Based on mapping: http://www.unicode.org/Public/MAPPINGS/ETSI/GSM0338.TXT
 *
 * Copyright (C) 2011 OnlineCity
 * Licensed under the MIT license, which can be read at: http://www.opensource.org/licenses/mit-license.php
 * @author hd@onlinecity.dk
 */
class GsmEncoderHelper
{
    /**
     * Encode an UTF-8 string into GSM 03.38
     * Since UTF-8 is largely ASCII compatible, and GSM 03.38 is somewhat compatible, unnecessary conversions are removed.
     * Specials chars such as € can be encoded by using an escape char \x1B in front of a backwards compatible (similar) char.
     * UTF-8 chars which doesn't have a GSM 03.38 equivalent is replaced with a question mark.
     * UTF-8 continuation bytes (\x08-\xBF) are replaced when encountered in their valid places, but
     * any continuation bytes outside of a valid UTF-8 sequence is not processed.
     *
     * @param string $string
     * @return string
     */
    public static function utf8ToGsm0338(string $string): string
    {
        $dict = [
            '@' => "\x00",
            '£' => "\x01",
            '$' => "\x02",
            '¥' => "\x03",
            'è' => "\x04",
            'é' => "\x05",
            'ù' => "\x06",
            'ì' => "\x07",
            'ò' => "\x08",
            'Ç' => "\x09",
            'Ø' => "\x0B",
            'ø' => "\x0C",
            'Å' => "\x0E",
            'å' => "\x0F",
            'Δ' => "\x10",
            '_' => "\x11",
            'Φ' => "\x12",
            'Γ' => "\x13",
            'Λ' => "\x14",
            'Ω' => "\x15",
            'Π' => "\x16",
            'Ψ' => "\x17",
            'Σ' => "\x18",
            'Θ' => "\x19",
            'Ξ' => "\x1A",
            'Æ' => "\x1C",
            'æ' => "\x1D",
            'ß' => "\x1E",
            'É' => "\x1F",
            // Cyrillic is intentionally NOT mapped here. The previous entries
            // encoded each letter as its big-endian Unicode code point
            // (e.g. 'А' => "\x04\x10"), which is NOT valid GSM 03.38 — in the
            // GSM alphabet those bytes are completely different characters
            // (0x04='è', 0x10='Δ'). Cyrillic text must be sent via UCS-2
            // (data_coding 0x08 / UTF-16BE) instead; unmapped characters below
            // fall through to '?'.
            // all \x2? removed
            // all \x3? removed
            '¡' => "\x40",
            'Ä' => "\x5B",
            'Ö' => "\x5C",
            'Ñ' => "\x5D",
            'Ü' => "\x5E",
            '§' => "\x5F",
            '¿' => "\x60",
            'ä' => "\x7B",
            'ö' => "\x7C",
            'ñ' => "\x7D",
            'ü' => "\x7E",
            'à' => "\x7F",
            '^' => "\x1B\x14",
            '{' => "\x1B\x28",
            '}' => "\x1B\x29",
            '\\' => "\x1B\x2F",
            '[' => "\x1B\x3C",
            '~' => "\x1B\x3D",
            ']' => "\x1B\x3E",
            '|' => "\x1B\x40",
            '€' => "\x1B\x65"
        ];

        $converted = strtr($string, $dict);

        // Replace any remaining UTF-8 characters (codepages U+0080-U+07FF,
        // U+0800-U+FFFF and U+010000-U+10FFFF) that have no GSM 03.38 equivalent
        // — e.g. Cyrillic — with a single '?', so raw multi-byte UTF-8 never
        // leaks into the GSM payload. GSM output bytes are all < 0x80 (plus the
        // 0x1B escape), so this never corrupts a converted character.
        return preg_replace('/([\\xC0-\\xDF].)|([\\xE0-\\xEF]..)|([\\xF0-\\xFF]...)/m', '?', $converted) ?? $converted;
    }

    /**
     * Count the number of GSM 03.38 chars a conversion would contain.
     * It's about 3 times faster to count than convert and do strlen() if conversion is not required.
     *
     * @param string $utf8String
     *
     * @return integer
     */
    public static function countGsm0338Length(string $utf8String): int
    {
        // Count the actual encoded septets: each byte of the GSM 03.38 output is
        // one septet (escaped characters such as € or { encode to two bytes / two
        // septets). Counting UTF-8 characters instead miscounts anything that does
        // not map 1:1 to a single GSM byte.
        return strlen(self::utf8ToGsm0338($utf8String));
    }

    /**
     * Pack an 8-bit string into 7-bit GSM format
     * Returns the packed string in binary format
     *
     * @param string $data
     *
     * @return string
     */
    public static function pack7bit(string $data): string
    {
        $l = strlen($data);
        $currentByte = 0;
        $offset = 0;
        $packed = '';
        for ($i = 0; $i < $l; $i++) {
            // cap off any excess bytes
            $septet = ord($data[$i]) & 0x7f;
            // append the septet and then cap off excess bytes
            $currentByte |= ($septet << $offset) & 0xff;
            // update offset
            $offset += 7;

            if ($offset > 7) {
                // the current byte is full, add it to the encoded data.
                $packed .= chr($currentByte);
                // shift left and append the left shifted septet to the current byte
                $currentByte = $septet = $septet >> (7 - ($offset - 8));
                // update offset
                // 7 - (7 - ($offset - 8))
                $offset -= 8;
            }
        }
        // append the last byte
        if ($currentByte > 0) {
            $packed .= chr($currentByte);
        }
        return $packed;
    }
}
