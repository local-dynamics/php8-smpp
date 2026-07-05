<?php

declare(strict_types=1);

namespace Smpp\Tests\Configs;

use PHPUnit\Framework\TestCase;
use Smpp\Configs\SmppConfig;
use Smpp\Exceptions\SmppInvalidArgumentException;

class SmppConfigWindowingTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new SmppConfig();
        self::assertSame(10, $config->getWindowSize());
        self::assertSame(30000, $config->getWindowTimeoutMs());
    }

    public function testSettersAreFluentAndStore(): void
    {
        $config = new SmppConfig();
        self::assertSame($config, $config->setWindowSize(20));
        self::assertSame(20, $config->getWindowSize());
        self::assertSame($config, $config->setWindowTimeoutMs(5000));
        self::assertSame(5000, $config->getWindowTimeoutMs());
    }

    public function testWindowSizeMustBePositive(): void
    {
        $this->expectException(SmppInvalidArgumentException::class);
        (new SmppConfig())->setWindowSize(0);
    }

    public function testWindowTimeoutMustBePositive(): void
    {
        $this->expectException(SmppInvalidArgumentException::class);
        (new SmppConfig())->setWindowTimeoutMs(0);
    }
}
