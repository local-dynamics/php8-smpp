<?php

declare(strict_types=1);

namespace Protocol;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Smpp\Pdu\Pdu;
use Smpp\Protocol\Command;
use Smpp\Protocol\PDUBuilder;

class SpyLogger extends AbstractLogger
{
    /** @var string[] */
    public array $messages = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }
}

class LoggerBindingTest extends TestCase
{
    /**
     * The constructor used to take the logger by reference (&$logger). That
     * bound the property to the caller's variable, so reassigning that variable
     * after construction silently rerouted the builder's logging to a different
     * object. The logger must be captured by value.
     */
    public function testLoggerIsNotBoundByReferenceToCallerVariable(): void
    {
        $original  = new SpyLogger();
        $other     = new SpyLogger();
        $reference = $original; // keep hold of the original object

        $builder = new PDUBuilder($original);

        // Reassigning the caller variable must NOT affect the builder's logger.
        $original = $other;

        $builder->packPdu(new Pdu(Command::ENQUIRE_LINK, 0, 1, ''));

        self::assertNotEmpty($reference->messages, 'builder must keep logging to the original logger');
        self::assertEmpty($other->messages, 'reassigning the caller variable must not reroute logging');
    }
}
