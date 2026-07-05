<?php

declare(strict_types=1);

namespace Smpp\Windowing;

/**
 * Outcome of one logical windowed message (all its segments).
 */
readonly class SubmitResult
{
    public function __construct(
        public mixed $context,
        public bool $success,
        public string $messageId,
        public int $status,
        public string $errorReason
    ) {
    }

    public static function ok(mixed $context, string $messageId): self
    {
        return new self($context, true, $messageId, 0, '');
    }

    public static function smscError(mixed $context, int $status): self
    {
        return new self($context, false, '', $status, 'smsc_error');
    }

    public static function timeout(mixed $context): self
    {
        return new self($context, false, '', 0, 'timeout');
    }
}
