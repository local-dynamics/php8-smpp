<?php

declare(strict_types=1);

namespace Smpp\Windowing;

use Closure;
use Exception;
use Smpp\Client;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Exceptions\SmppException;
use Smpp\Pdu\Address;
use Smpp\Pdu\Pdu;
use Smpp\Pdu\Tag;
use Smpp\Protocol\Command;
use Smpp\Protocol\CommandStatus;
use Smpp\Smpp;

/**
 * Adds a non-blocking, windowed submit_sm API on top of the synchronous
 * Client. Call submitAsync() to fire messages (up to the configured window
 * size) and pump() to drain responses, match them by sequence number and
 * surface completed logical messages, timeouts and inbound SMS.
 */
class WindowedClient extends Client
{
    /** @var Closure(): float */
    private Closure $clock;

    private ?InFlightWindow $window = null;

    /**
     * @param Closure(): float|null $clock
     */
    public function __construct(
        TransportInterface $transport,
        string $systemId,
        string $password,
        ?Closure $clock = null
    ) {
        parent::__construct($transport, $systemId, $password);
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    protected function window(): InFlightWindow
    {
        if ($this->window === null) {
            $this->window = new InFlightWindow(
                $this->config->getWindowSize(),
                $this->config->getWindowTimeoutMs()
            );
        }
        return $this->window;
    }

    public function canSubmit(int $segmentCount = 1): bool
    {
        return $this->window()->canAccept($segmentCount);
    }

    public function pendingCount(): int
    {
        return $this->window()->usedSegments();
    }

    /**
     * Fire a logical message into the window without waiting for its response.
     * All of its segments are accepted atomically.
     *
     * @param Tag[]|null $tags
     * @throws Exception
     */
    public function submitAsync(
        mixed $context,
        Address $from,
        Address $to,
        string $message,
        ?array $tags = null,
        int $dataCoding = Smpp::DATA_CODING_DEFAULT,
        int $priority = 0x00
    ): void {
        if (!$this->isMessageSendable(strlen($message), $dataCoding)) {
            throw new SmppException('Message not sendable with data_coding 0x' . dechex($dataCoding));
        }

        $segments = $this->buildSubmitSmSegments($from, $to, $message, $tags, $dataCoding, $priority);

        if (!$this->window()->canAccept(count($segments))) {
            throw new SmppException(
                'Window full: cannot submit ' . count($segments) . ' segment(s); '
                . $this->pendingCount() . '/' . $this->config->getWindowSize() . ' in flight'
            );
        }

        $groupId = $this->window()->nextGroupId();
        $sequenceNumbers = [];
        foreach ($segments as $body) {
            $sequence = $this->sequenceNumber;
            $this->sendPDU(new Pdu(Command::SUBMIT_SM, CommandStatus::ESME_ROK, $sequence, $body));
            $this->sequenceNumber++;
            $sequenceNumbers[] = $sequence;
        }

        $this->window()->add($groupId, $context, $sequenceNumbers, ($this->clock)());
    }
}
