<?php

declare(strict_types=1);

namespace Smpp\Windowing;

use Smpp\Protocol\CommandStatus;

/**
 * A logical message split into one or more submit_sm segments. All segments
 * must be acknowledged before the group is complete; any non-OK segment (or a
 * timeout) fails the whole group.
 */
class InFlightGroup
{
    /**
     * @var array<int, InFlightSegment> $segments keyed by sequence number
     */
    private array $segments;

    /**
     * @param InFlightSegment[] $segments
     */
    public function __construct(
        public readonly int $id,
        private readonly mixed $context,
        array $segments
    ) {
        // Re-key segments by their sequence number for access via segments()[seqNum]
        $this->segments = [];
        foreach ($segments as $segment) {
            $this->segments[$segment->sequenceNumber] = $segment;
        }
    }

    public function context(): mixed
    {
        return $this->context;
    }

    /**
     * @return array<int, InFlightSegment>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    public function isComplete(): bool
    {
        foreach ($this->segments as $segment) {
            if (!$segment->responded) {
                return false;
            }
        }
        return true;
    }

    public function hasError(): bool
    {
        foreach ($this->segments as $segment) {
            if (!$segment->responded || $segment->status !== CommandStatus::ESME_ROK) {
                return true;
            }
        }
        return false;
    }

    public function firstErrorStatus(): int
    {
        foreach ($this->segments as $segment) {
            if ($segment->responded && $segment->status !== CommandStatus::ESME_ROK) {
                return $segment->status;
            }
        }
        return 0;
    }

    public function lastMessageId(): string
    {
        $messageId = '';
        foreach ($this->segments as $segment) {
            if ($segment->messageId !== '') {
                $messageId = $segment->messageId;
            }
        }
        return $messageId;
    }

    public function earliestUnrespondedSentAt(): ?float
    {
        $earliest = null;
        foreach ($this->segments as $segment) {
            if ($segment->responded) {
                continue;
            }
            if ($earliest === null || $segment->sentAt < $earliest) {
                $earliest = $segment->sentAt;
            }
        }
        return $earliest;
    }
}
