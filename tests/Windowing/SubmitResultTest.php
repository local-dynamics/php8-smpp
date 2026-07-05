<?php

declare(strict_types=1);

namespace Smpp\Tests\Windowing;

use PHPUnit\Framework\TestCase;
use Smpp\Windowing\SubmitResult;

class SubmitResultTest extends TestCase
{
    public function testOk(): void
    {
        $result = SubmitResult::ok('ctx', 'MID-1');
        self::assertTrue($result->success);
        self::assertSame('ctx', $result->context);
        self::assertSame('MID-1', $result->messageId);
        self::assertSame('', $result->errorReason);
    }

    public function testSmscError(): void
    {
        $result = SubmitResult::smscError('ctx', 0x0000000C);
        self::assertFalse($result->success);
        self::assertSame(0x0000000C, $result->status);
        self::assertSame('smsc_error', $result->errorReason);
        self::assertSame('', $result->messageId);
    }

    public function testTimeout(): void
    {
        $result = SubmitResult::timeout('ctx');
        self::assertFalse($result->success);
        self::assertSame('timeout', $result->errorReason);
    }
}
