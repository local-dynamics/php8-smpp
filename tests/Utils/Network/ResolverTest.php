<?php

namespace Utils\Network;

use Smpp\Utils\Network\Entry;
use Smpp\Utils\Network\Resolver;
use PHPUnit\Framework\TestCase;

class ResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        // The DNS resolver override is static; restore it so tests stay isolated.
        Resolver::resetDnsResolver();
    }

    public function testResolveIPsReturnsIpv4List(): void
    {
        Resolver::setDnsResolver(fn() => [['ip' => '1.1.1.1']]);
        $ips = Resolver::resolveIPs('example.com', DNS_A);
        $this->assertEquals(['1.1.1.1'], $ips);
    }

    /**
     * dns_get_record() returns false on lookup failure. resolveIPs() must
     * degrade to an empty list instead of raising a TypeError from
     * array_column(false, ...).
     */
    public function testResolveIPsReturnsEmptyArrayWhenLookupFails(): void
    {
        Resolver::setDnsResolver(fn() => false);
        $this->assertSame([], Resolver::resolveIPs('does-not-resolve.invalid', DNS_A));
        $this->assertSame([], Resolver::resolveIPs('does-not-resolve.invalid', DNS_AAAA));
    }

    public function testCreateFallbackEntryDetectsIpVersion(): void
    {
        $ipv4Entry = Resolver::createFallbackEntry('1.1.1.1', 2775);
        $this->assertNotNull($ipv4Entry->getIpv4());
        $this->assertEquals(new Entry(port: 2775, ipv4: '1.1.1.1'), $ipv4Entry);

        $ipv6Entry = Resolver::createFallbackEntry('::1', 2775);
        $this->assertNotNull($ipv6Entry->getIpv6());
        $this->assertEquals(new Entry(port: 2775, ipv6: '::1'), $ipv6Entry);
    }
}
