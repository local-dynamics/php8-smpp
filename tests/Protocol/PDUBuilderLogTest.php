<?php

declare(strict_types=1);

namespace Protocol;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Smpp\Pdu\Pdu;
use Smpp\Protocol\Command;
use Smpp\Protocol\PDUBuilder;

class PDUBuilderLogTest extends TestCase
{
    /**
     * packPdu() builds an outgoing PDU, so its debug line must say "Send PDU",
     * not "Read PDU" — the mislabel made sent and received PDUs
     * indistinguishable in the logs.
     */
    public function testPackPduLogsAsSend(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var string[] */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $builder = new PDUBuilder($logger);
        $builder->packPdu(new Pdu(Command::ENQUIRE_LINK, 0, 1, ''));

        $joined = implode("\n", $logger->messages);
        self::assertStringContainsString('Send PDU', $joined);
        self::assertStringNotContainsString('Read PDU', $joined);
    }
}
