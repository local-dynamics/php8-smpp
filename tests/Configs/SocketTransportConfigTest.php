<?php

declare(strict_types=1);

namespace Configs;

use PHPUnit\Framework\TestCase;
use Smpp\Configs\SocketTransportConfig;
use Smpp\Exceptions\SmppInvalidArgumentException;

class SocketTransportConfigTest extends TestCase
{
    /**
     * Forcing both IPv4 and IPv6 is contradictory and made open() fail every
     * host with a misleading message. The second setter must reject it.
     */
    public function testCannotForceBothIpv4AndIpv6(): void
    {
        $config = (new SocketTransportConfig())->setForceIpv4(true);

        $this->expectException(SmppInvalidArgumentException::class);
        $config->setForceIpv6(true);
    }

    public function testCannotForceBothIpv6AndIpv4(): void
    {
        $config = (new SocketTransportConfig())->setForceIpv6(true);

        $this->expectException(SmppInvalidArgumentException::class);
        $config->setForceIpv4(true);
    }

    public function testForcingASingleFamilyIsFine(): void
    {
        $config = (new SocketTransportConfig())->setForceIpv4(true);

        self::assertTrue($config->isForceIpv4());
        self::assertFalse($config->isForceIpv6());
    }
}
