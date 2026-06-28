<?php

declare(strict_types=1);

namespace Validators;

use PHPUnit\Framework\TestCase;
use Smpp\Exceptions\SmppInvalidArgumentException;
use Smpp\Validators\AddressRangeValidator;

class AddressRangeMaxLengthTest extends TestCase
{
    /**
     * The default max length must follow SMPP v3.4 §5.2.7 (41 octets). The old
     * default of 20 rejected spec-compliant address ranges of 21–41 chars.
     */
    public function testDefaultAllowsSpecMaximumOf41(): void
    {
        $validator = new AddressRangeValidator();

        self::assertNull($validator->isValid(str_repeat('1', 41)));
    }

    public function testValueAboveSpecMaximumIsRejected(): void
    {
        $validator = new AddressRangeValidator();

        self::assertInstanceOf(
            SmppInvalidArgumentException::class,
            $validator->isValid(str_repeat('1', 42))
        );
    }

    public function testStricterCustomLimitStillHonoured(): void
    {
        $validator = new AddressRangeValidator(10);

        self::assertInstanceOf(
            SmppInvalidArgumentException::class,
            $validator->isValid(str_repeat('1', 11))
        );
    }
}
