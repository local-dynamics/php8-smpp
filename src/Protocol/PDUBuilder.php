<?php

declare(strict_types=1);

namespace Smpp\Protocol;

use Psr\Log\LoggerInterface;
use Smpp\Pdu\BinaryPDU;
use Smpp\Pdu\Pdu;
use Smpp\Pdu\PDUHeader;

class PDUBuilder
{
    public function __construct(
        private LoggerInterface $logger
    )
    {

    }

    public function getEnquireLinkResponse(int $sequence): BinaryPDU
    {
        // enquire_link_resp has no body (SMPP v3.4 §4.11.2); the PDU must be
        // exactly 16 bytes (header only). A "\x00" body would make it 17 bytes
        // and strict SMSCs reject the session.
        return $this->packPdu(new Pdu(Command::ENQUIRE_LINK_RESP, CommandStatus::ESME_ROK, $sequence, ""));
    }

    /**
     * @param Pdu $pdu
     * @return BinaryPDU
     */
    public function packPdu(Pdu $pdu): BinaryPDU
    {
        $length = strlen($pdu->getBody()) + PDUHeader::PDU_HEADER_LENGTH;

        $datagram = $this->packHeader($pdu, $length) . $pdu->getBody();

        $this->logger->debug("Send PDU         : $length bytes");
        $this->logger->debug(' ' . chunk_split(bin2hex($datagram), 2, " "));
        $this->logger->debug(' command_id      : 0x' . dechex($pdu->getId()));
        $this->logger->debug(' sequence number : ' . $pdu->getSequence());

        return new BinaryPDU(
            data: $datagram,
            length: $length
        );
    }

    /**
     * @param Pdu $pdu
     * @param int $length
     * @return string
     */
    public function packHeader(Pdu $pdu, int $length): string
    {
        return pack("NNNN", $length, $pdu->getId(), $pdu->getStatus(), $pdu->getSequence());
    }
}