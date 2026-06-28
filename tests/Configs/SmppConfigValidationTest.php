<?php

declare(strict_types=1);

namespace Configs;

use PHPUnit\Framework\TestCase;
use Smpp\Configs\SmppConfig;
use Smpp\Exceptions\SmppInvalidArgumentException;
use Smpp\Smpp;

class SmppConfigValidationTest extends TestCase
{
    public function testCsmsMethodRejectsUnknownValue(): void
    {
        $this->expectException(SmppInvalidArgumentException::class);
        (new SmppConfig())->setCsmsMethod(5);
    }

    public function testPriorityFlagRejectsOutOfRange(): void
    {
        $this->expectException(SmppInvalidArgumentException::class);
        (new SmppConfig())->setSmsPriorityFlag(4);
    }

    public function testReplaceIfPresentFlagRejectsOutOfRange(): void
    {
        $this->expectException(SmppInvalidArgumentException::class);
        (new SmppConfig())->setSmsReplaceIfPresentFlag(2);
    }

    public function testSmDefaultMessageIdRejectsOutOfRange(): void
    {
        $this->expectException(SmppInvalidArgumentException::class);
        (new SmppConfig())->setSmsSmDefaultMessageID(255);
    }

    public function testValidValuesAreAccepted(): void
    {
        $config = (new SmppConfig())
            ->setCsmsMethod(Smpp::CSMS_8BIT_UDH)
            ->setSmsPriorityFlag(3)
            ->setSmsReplaceIfPresentFlag(1)
            ->setSmsSmDefaultMessageID(254);

        self::assertSame(Smpp::CSMS_8BIT_UDH, $config->getCsmsMethod());
        self::assertSame(3, $config->getSmsPriorityFlag());
        self::assertSame(1, $config->getSmsReplaceIfPresentFlag());
        self::assertSame(254, $config->getSmsSmDefaultMessageID());
    }
}
