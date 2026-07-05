<?php

declare(strict_types=1);

namespace Smpp\Windowing;

use Smpp\Pdu\DeliveryReceipt;
use Smpp\Pdu\Sms;

/**
 * Everything a single pump() call surfaced: logical messages that completed
 * (success, SMSC error or timeout) and inbound messages received meanwhile.
 */
readonly class PumpResult
{
    /**
     * @param SubmitResult[] $completed
     * @param array<int, DeliveryReceipt|Sms> $incoming
     */
    public function __construct(
        public array $completed,
        public array $incoming
    ) {
    }
}
