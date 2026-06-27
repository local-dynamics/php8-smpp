<?php

declare(strict_types=1);

namespace Exceptions;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Smpp\Exceptions\ClosedTransportException;
use Smpp\Exceptions\PDUParseException;
use Smpp\Exceptions\SmppException;
use Smpp\Exceptions\SmppInvalidArgumentException;
use Smpp\Exceptions\SocketTemporaryFailureException;
use Smpp\Exceptions\SocketTimeoutException;
use Smpp\Exceptions\SocketTransportException;

class ExceptionHierarchyTest extends TestCase
{
    /**
     * Every library exception must be catchable via the single SmppException
     * base type. SocketTransportException and ClosedTransportException
     * previously extended RuntimeException directly and slipped through
     * catch (SmppException).
     *
     * @return array<string, array{class-string<\Throwable>}>
     */
    public static function smppExceptionProvider(): array
    {
        return [
            SocketTransportException::class       => [SocketTransportException::class],
            ClosedTransportException::class       => [ClosedTransportException::class],
            PDUParseException::class              => [PDUParseException::class],
            SmppInvalidArgumentException::class   => [SmppInvalidArgumentException::class],
            SocketTimeoutException::class         => [SocketTimeoutException::class],
            SocketTemporaryFailureException::class => [SocketTemporaryFailureException::class],
        ];
    }

    /**
     * @dataProvider smppExceptionProvider
     * @param class-string<\Throwable> $exceptionClass
     */
    public function testIsCaughtAsSmppException(string $exceptionClass): void
    {
        self::assertInstanceOf(SmppException::class, new $exceptionClass('boom'));
    }

    /**
     * SmppException keeps runtime-exception semantics, so catch (RuntimeException)
     * still works for the whole hierarchy.
     */
    public function testSmppExceptionIsRuntimeException(): void
    {
        self::assertInstanceOf(RuntimeException::class, new SmppException('boom'));
        self::assertInstanceOf(RuntimeException::class, new SocketTransportException('boom'));
    }
}
