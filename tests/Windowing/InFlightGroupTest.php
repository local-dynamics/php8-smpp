<?php

declare(strict_types=1);

namespace Smpp\Tests\Windowing;

use PHPUnit\Framework\TestCase;
use Smpp\Protocol\CommandStatus;
use Smpp\Windowing\InFlightGroup;
use Smpp\Windowing\InFlightSegment;

class InFlightGroupTest extends TestCase
{
    public function testIncompleteUntilAllSegmentsResponded(): void
    {
        $group = new InFlightGroup(1, 'ctx', [
            new InFlightSegment(10, 100.0),
            new InFlightSegment(11, 100.0),
        ]);

        self::assertFalse($group->isComplete());

        $group->segments()[10]->markResponded(CommandStatus::ESME_ROK, 'MID-A');
        self::assertFalse($group->isComplete());

        $group->segments()[11]->markResponded(CommandStatus::ESME_ROK, 'MID-B');
        self::assertTrue($group->isComplete());
    }

    public function testSuccessReportsLastMessageId(): void
    {
        $group = new InFlightGroup(1, 'ctx', [
            new InFlightSegment(10, 100.0),
            new InFlightSegment(11, 100.0),
        ]);
        $group->segments()[10]->markResponded(CommandStatus::ESME_ROK, 'MID-A');
        $group->segments()[11]->markResponded(CommandStatus::ESME_ROK, 'MID-B');

        self::assertFalse($group->hasError());
        self::assertSame('MID-B', $group->lastMessageId());
    }

    public function testAnyNonOkSegmentIsAnError(): void
    {
        $group = new InFlightGroup(1, 'ctx', [
            new InFlightSegment(10, 100.0),
            new InFlightSegment(11, 100.0),
        ]);
        $group->segments()[10]->markResponded(CommandStatus::ESME_ROK, 'MID-A');
        $group->segments()[11]->markResponded(0x0000000C, ''); // ESME_RTHROTTLED-ish non-OK

        self::assertTrue($group->hasError());
        self::assertSame(0x0000000C, $group->firstErrorStatus());
    }

    public function testEarliestUnrespondedSentAt(): void
    {
        $group = new InFlightGroup(1, 'ctx', [
            new InFlightSegment(10, 100.0),
            new InFlightSegment(11, 105.0),
        ]);
        self::assertSame(100.0, $group->earliestUnrespondedSentAt());

        $group->segments()[10]->markResponded(CommandStatus::ESME_ROK, 'MID-A');
        self::assertSame(105.0, $group->earliestUnrespondedSentAt());

        $group->segments()[11]->markResponded(CommandStatus::ESME_ROK, 'MID-B');
        self::assertNull($group->earliestUnrespondedSentAt());
    }
}
