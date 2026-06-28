<?php

declare(strict_types=1);


namespace Smpp\Validators;

use Smpp\Contracts\ValidatorInterface;
use Smpp\Exceptions\SmppException;
use Smpp\Exceptions\SmppInvalidArgumentException;

class AddressRangeValidator implements ValidatorInterface
{
    public function __construct(
        // SMPP v3.4 §5.2.7: address_range is a C-Octet String of up to 41
        // octets. The previous default of 20 rejected spec-compliant ranges
        // (21–41 chars). Callers needing a stricter operator limit can still
        // pass a smaller value.
        private int $addressRangeMaxLength = 41
    )
    {
    }

    /**
     * @param string $value
     * @return SmppException|null
     */
    public function isValid($value): ?SmppException
    {
        // An empty value means "no filter" (match all addresses) and is always
        // valid. The character regex below uses "+" (one or more), so without
        // this guard an empty string would be wrongly rejected.
        if ($value === '') {
            return null;
        }

        //Maximum length (depending on operator)
        if ($this->maxLengthValidation($value)) {
            return new SmppInvalidArgumentException("addrRange too long (max $this->addressRangeMaxLength chars)");
        }

        // Check for valid characters: numbers, +, *, ?, #
        if (!preg_match('/^[\d+*?#]+$/', $value)) {
            return new SmppInvalidArgumentException("addrRange contains invalid characters. Only digits, +, *, ?, # allowed");
        }

        // Check wildcard * (if not allowed in the middle)
        if (str_contains($value, '*') && !str_ends_with($value, '*')) {
            return new SmppInvalidArgumentException("Wildcard * is only allowed at the end of addrRange");
        }

        return null;
    }


    /**
     * @param string $addressRange The address range string to validate.
     * @return bool True if the length exceeds the maximum; otherwise, false.
     */

    private function maxLengthValidation(string $addressRange): bool
    {
        // Validates whether the provided address range exceeds the maximum allowed length.
        return strlen($addressRange) > $this->addressRangeMaxLength;
    }
}