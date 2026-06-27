<?php

declare(strict_types=1);

namespace Validators;

use PHPUnit\Framework\TestCase;
use Smpp\Exceptions\SmppInvalidArgumentException;
use Smpp\Validators\AddressRangeValidator;

class AddressRangeValidatorTest extends TestCase
{
    /**
     * An empty address range means "no filter" (match all addresses) and must
     * be accepted. The character regex uses "+" (one or more), so before the
     * fix an empty string was wrongly rejected — making setAddressRange("")
     * (resetting a previously set filter) throw.
     */
    public function testEmptyStringIsValid(): void
    {
        self::assertNull((new AddressRangeValidator())->isValid(''));
    }

    public function testPlainNumericRangeIsValid(): void
    {
        self::assertNull((new AddressRangeValidator())->isValid('1234567'));
    }

    public function testQuestionMarkWildcardIsValid(): void
    {
        // '?' (single-char wildcard) is an allowed character and carries no
        // extra syntactic restriction.
        self::assertNull((new AddressRangeValidator())->isValid('12?4#'));
    }

    public function testTrailingStarWildcardIsValid(): void
    {
        self::assertNull((new AddressRangeValidator())->isValid('123*'));
    }

    public function testStarInTheMiddleIsRejected(): void
    {
        self::assertInstanceOf(
            SmppInvalidArgumentException::class,
            (new AddressRangeValidator())->isValid('1*23')
        );
    }

    public function testInvalidCharactersAreRejected(): void
    {
        self::assertInstanceOf(
            SmppInvalidArgumentException::class,
            (new AddressRangeValidator())->isValid('12ab')
        );
    }

    public function testTooLongValueIsRejected(): void
    {
        self::assertInstanceOf(
            SmppInvalidArgumentException::class,
            (new AddressRangeValidator(5))->isValid('123456')
        );
    }
}
