<?php

declare(strict_types=1);

namespace Smpp\Windowing;

/**
 * One unacknowledged submit_sm segment within a logical message.
 */
class InFlightSegment
{
    public bool $responded = false;
    public int $status = 0;
    public string $messageId = '';

    public function __construct(
        public readonly int $sequenceNumber,
        public readonly float $sentAt
    ) {
    }

    public function markResponded(int $status, string $messageId): void
    {
        $this->responded = true;
        $this->status = $status;
        $this->messageId = $messageId;
    }
}
