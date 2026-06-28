<?php

declare(strict_types=1);

namespace Configs;

use PHPUnit\Framework\TestCase;
use Smpp\Configs\SmppConfig;

class SmppConfigSystemTypeTest extends TestCase
{
    /**
     * SMPP v3.4 §5.2.3: system_type should default to empty for default SMSC
     * settings. The previous "WWW" default is non-standard.
     */
    public function testDefaultSystemTypeIsEmpty(): void
    {
        self::assertSame('', (new SmppConfig())->getSystemType());
    }

    public function testSystemTypeRemainsConfigurable(): void
    {
        $config = (new SmppConfig())->setSystemType('SMPP');

        self::assertSame('SMPP', $config->getSystemType());
    }
}
