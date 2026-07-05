<?php

declare(strict_types=1);

namespace Smpp\Tests\Windowing;

use PHPUnit\Framework\TestCase;
use Smpp\Exceptions\SmppException;
use Smpp\Protocol\CommandStatus;
use Smpp\Windowing\InFlightWindow;

class InFlightWindowTest extends TestCase
{
    public function testCapacityCountsSegments(): void
    {
        $window = new InFlightWindow(3, 30000);
        self::assertTrue($window->canAccept(3));
        self::assertFalse($window->canAccept(4));

        $window->add($window->nextGroupId(), 'a', [1, 2], 100.0);
        self::assertSame(2, $window->usedSegments());
        self::assertTrue($window->canAccept(1));
        self::assertFalse($window->canAccept(2));
    }

    public function testAtomicAddRejectsOverflow(): void
    {
        $window = new InFlightWindow(2, 30000);
        $this->expectException(SmppException::class);
        $window->add($window->nextGroupId(), 'a', [1, 2, 3], 100.0);
    }

    public function testMatchResponseUnknownSequenceIsIgnored(): void
    {
        $window = new InFlightWindow(5, 30000);
        self::assertFalse($window->matchResponse(999, CommandStatus::ESME_ROK, 'X'));
    }

    public function testCompletedGroupIsReturnedOnceAndFreesCapacity(): void
    {
        $window = new InFlightWindow(5, 30000);
        $id = $window->nextGroupId();
        $window->add($id, 'ctx', [1, 2], 100.0);

        $window->matchResponse(1, CommandStatus::ESME_ROK, 'MID-A');
        self::assertSame([], $window->takeCompleted(), 'group not complete yet');

        $window->matchResponse(2, CommandStatus::ESME_ROK, 'MID-B');
        $completed = $window->takeCompleted();
        self::assertNotEmpty($completed);
        self::assertCount(1, $completed);
        self::assertSame('ctx', $completed[0]->context());
        self::assertSame('MID-B', $completed[0]->lastMessageId());

        // Removed: capacity freed, and not returned again
        self::assertSame(0, $window->usedSegments());
        self::assertSame([], $window->takeCompleted());
    }

    public function testTimedOutGroupIsReturnedAndRemoved(): void
    {
        $window = new InFlightWindow(5, 30000); // 30s
        $id = $window->nextGroupId();
        $window->add($id, 'ctx', [1, 2], 100.0);

        // 20s later: not timed out
        self::assertSame([], $window->takeTimedOut(120.0));

        // 31s after send: timed out
        $timedOut = $window->takeTimedOut(131.0);
        self::assertCount(1, $timedOut);
        self::assertSame('ctx', $timedOut[0]->context());
        self::assertSame(0, $window->usedSegments());
    }

    public function testPartiallyRespondedGroupStillTimesOut(): void
    {
        $window = new InFlightWindow(5, 30000);
        $id = $window->nextGroupId();
        $window->add($id, 'ctx', [1, 2], 100.0);
        $window->matchResponse(1, CommandStatus::ESME_ROK, 'MID-A');

        $timedOut = $window->takeTimedOut(131.0);
        self::assertCount(1, $timedOut);
    }
}
