<?php

declare(strict_types=1);

namespace Smpp\Windowing;

use Closure;
use Smpp\Client;
use Smpp\Contracts\Transport\TransportInterface;
use Smpp\Exceptions\SmppException;
use Smpp\Exceptions\SocketTransportException;
use Smpp\Pdu\Address;
use Smpp\Pdu\DeliveryReceipt;
use Smpp\Pdu\Pdu;
use Smpp\Pdu\Sms;
use Smpp\Pdu\Tag;
use Smpp\Protocol\Command;
use Smpp\Protocol\CommandStatus;
use Smpp\Smpp;

/**
 * Adds a non-blocking, windowed submit_sm API on top of the synchronous
 * Client. Call submitAsync() to fire messages (up to the configured window
 * size) and pump() to drain responses, match them by sequence number and
 * surface completed logical messages, timeouts and inbound SMS.
 *
 * ## Sync/Windowed Mixing Hazard
 * Do NOT mix the synchronous API (sendSMS() / sendCommand() / readSMS()) with
 * the windowed API (submitAsync() / pump()) on the SAME instance while
 * segments are in flight. The synchronous readPduResponse() call will enqueue
 * any in-flight submit_sm_resp PDUs it encounters into the internal pduQueue;
 * pump() does NOT read from that queue, so those groups would never resolve
 * via a successful match and would only be surfaced via timeout.
 *
 * ## Lazy-Window Config Timing
 * Configure the window (config->setWindowSize() / config->setWindowTimeoutMs())
 * BEFORE the first submitAsync(), pump(), or canSubmit() call. The InFlightWindow
 * is built lazily and cached on first use; later config changes are ignored.
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
     * @throws SmppException
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
        if (!$this->transport->isOpen()) {
            throw new SocketTransportException('Socket is closed');
        }

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

    /**
     * Drain immediately-available PDUs from the transport, match submit_sm_resp
     * to in-flight segments, auto-answer deliver_sm and enquire_link, and
     * surface completed logical messages (success/SMSC error), timeouts and
     * inbound SMS. Non-blocking beyond the transport's own read timeout.
     *
     * @param int $maxPdus per-call read budget; <= 0 uses 2 * windowSize + 8.
     * @throws \Exception
     */
    public function pump(int $maxPdus = 0): PumpResult
    {
        if ($maxPdus <= 0) {
            $maxPdus = 2 * $this->config->getWindowSize() + 8;
        }

        /** @var array<int, DeliveryReceipt|Sms> $incoming */
        $incoming = [];
        $read = 0;

        while ($read < $maxPdus && $this->transport->hasData()) {
            $pdu = $this->readPDU();
            if ($pdu === false) {
                break;
            }
            $read++;

            switch ($pdu->getId()) {
                case Command::SUBMIT_SM_RESP:
                    $messageId = '';
                    $body = $pdu->getBody();
                    if ($body !== '') {
                        /** @var array{msgid: string}|false $unpacked */
                        $unpacked = unpack('a*msgid', $body);
                        if ($unpacked) {
                            $messageId = rtrim($unpacked['msgid'], "\0");
                        }
                    }
                    $this->window()->matchResponse($pdu->getSequence(), $pdu->getStatus(), $messageId);
                    break;

                case Command::GENERIC_NACK:
                    $this->window()->matchResponse($pdu->getSequence(), $pdu->getStatus(), '');
                    break;

                case Command::DELIVER_SM:
                    // parseSMS() also sends the deliver_sm_resp.
                    // A malformed PDU (e.g. a delivery-receipt whose text fails
                    // the format regex) must not abort the whole pump() cycle
                    // and starve pending response matching or timeout harvesting.
                    try {
                        $incoming[] = $this->parseSMS($pdu);
                    } catch (\Throwable $e) {
                        $this->logger->warning(
                            'Skipped malformed deliver_sm: ' . $e->getMessage(),
                            [
                                'command_id' => $pdu->getId(),
                                'sequence'   => $pdu->getSequence(),
                            ]
                        );
                    }
                    break;

                case Command::ENQUIRE_LINK:
                    $this->sendPDU(new Pdu(
                        Command::ENQUIRE_LINK_RESP,
                        CommandStatus::ESME_ROK,
                        $pdu->getSequence(),
                        ''
                    ));
                    break;

                default:
                    $this->enqueuePdu($pdu);
                    break;
            }
        }

        $completed = [];
        foreach ($this->window()->takeCompleted() as $group) {
            $completed[] = $group->hasError()
                ? SubmitResult::smscError($group->context(), $group->firstErrorStatus())
                : SubmitResult::ok($group->context(), $group->lastMessageId());
        }
        foreach ($this->window()->takeTimedOut(($this->clock)()) as $group) {
            $completed[] = SubmitResult::timeout($group->context());
        }

        return new PumpResult($completed, $incoming);
    }
}
