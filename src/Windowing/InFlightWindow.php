<?php

declare(strict_types=1);

namespace Smpp\Windowing;

use Smpp\Exceptions\SmppException;

/**
 * Holds the set of in-flight submit_sm groups. Pure state, no I/O. Capacity is
 * measured in segments; a group is accepted atomically (all-or-nothing).
 */
class InFlightWindow
{
    /** @var array<int, InFlightGroup> keyed by group id */
    private array $groups = [];

    /** @var array<int, int> sequence number => group id */
    private array $sequenceIndex = [];

    private int $groupCounter = 0;

    public function __construct(
        private readonly int $capacity,
        private readonly int $timeoutMs
    ) {
    }

    public function usedSegments(): int
    {
        return count($this->sequenceIndex);
    }

    public function canAccept(int $segmentCount): bool
    {
        return $this->usedSegments() + $segmentCount <= $this->capacity;
    }

    public function nextGroupId(): int
    {
        return ++$this->groupCounter;
    }

    /**
     * @param int[] $sequenceNumbers
     * @throws SmppException
     */
    public function add(int $groupId, mixed $context, array $sequenceNumbers, float $now): void
    {
        if (!$this->canAccept(count($sequenceNumbers))) {
            throw new SmppException(
                'Window overflow: cannot accept ' . count($sequenceNumbers)
                . ' more segments (capacity ' . $this->capacity . ', used ' . $this->usedSegments() . ')'
            );
        }

        $segments = [];
        foreach ($sequenceNumbers as $sequenceNumber) {
            $segments[$sequenceNumber] = new InFlightSegment($sequenceNumber, $now);
            $this->sequenceIndex[$sequenceNumber] = $groupId;
        }

        $this->groups[$groupId] = new InFlightGroup($groupId, $context, $segments);
    }

    public function matchResponse(int $sequenceNumber, int $status, string $messageId): bool
    {
        if (!isset($this->sequenceIndex[$sequenceNumber])) {
            return false;
        }

        $group = $this->groups[$this->sequenceIndex[$sequenceNumber]];
        $group->segments()[$sequenceNumber]->markResponded($status, $messageId);
        return true;
    }

    /**
     * @return list<InFlightGroup>
     */
    public function takeCompleted(): array
    {
        $completed = [];
        foreach ($this->groups as $groupId => $group) {
            if ($group->isComplete()) {
                $completed[] = $group;
                $this->removeGroup($groupId);
            }
        }
        return $completed;
    }

    /**
     * @return list<InFlightGroup>
     */
    public function takeTimedOut(float $now): array
    {
        $deadlineAge = $this->timeoutMs / 1000.0;
        $timedOut = [];
        foreach ($this->groups as $groupId => $group) {
            $earliest = $group->earliestUnrespondedSentAt();
            if ($earliest !== null && ($now - $earliest) > $deadlineAge) {
                $timedOut[] = $group;
                $this->removeGroup($groupId);
            }
        }
        return $timedOut;
    }

    private function removeGroup(int $groupId): void
    {
        $group = $this->groups[$groupId];
        foreach ($group->segments() as $segment) {
            unset($this->sequenceIndex[$segment->sequenceNumber]);
        }
        unset($this->groups[$groupId]);
    }
}
