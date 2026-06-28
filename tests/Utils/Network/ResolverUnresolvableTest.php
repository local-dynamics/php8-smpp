<?php

declare(strict_types=1);

namespace Utils\Network;

use PHPUnit\Framework\TestCase;
use Smpp\Exceptions\SmppInvalidArgumentException;
use Smpp\Utils\Network\Resolver;

class ResolverUnresolvableTest extends TestCase
{
    protected function tearDown(): void
    {
        Resolver::resetDnsResolver();
    }

    /**
     * When neither DNS records nor gethostbyname() resolve the host, the
     * generator previously ended silently with no entries, leaving the caller
     * with an empty host list and a misleading downstream error. It must now
     * throw instead.
     */
    public function testUnresolvableHostThrows(): void
    {
        // Force the DNS step to yield nothing (an empty list, not false), so the
        // gethostbyname() fallback path is exercised deterministically.
        Resolver::setDnsResolver(fn() => []);

        $this->expectException(SmppInvalidArgumentException::class);

        // .invalid is reserved (RFC 2606) and never resolves.
        iterator_to_array(Resolver::getIPsByHost('definitely-not-a-host.invalid', 2775));
    }
}
