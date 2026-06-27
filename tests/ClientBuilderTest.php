<?php

declare(strict_types=1);

namespace Smpp\Tests;

use PHPUnit\Framework\TestCase;
use Smpp\Client;
use Smpp\ClientBuilder;
use Smpp\Exceptions\SmppInvalidArgumentException;

class ClientBuilderTest extends TestCase
{
    /**
     * buildClient() without setCredentials() previously raised a fatal Error
     * from accessing the uninitialised typed systemId/password properties. It
     * must throw a catchable SmppInvalidArgumentException instead.
     */
    public function testBuildClientThrowsWhenCredentialsMissing(): void
    {
        $builder = ClientBuilder::createForSockets(['127.0.0.1:2775']);

        $this->expectException(SmppInvalidArgumentException::class);
        $builder->buildClient();
    }

    public function testBuildClientSucceedsWithCredentials(): void
    {
        $client = ClientBuilder::createForSockets(['127.0.0.1:2775'])
            ->setCredentials('sysid', 'secret')
            ->buildClient();

        self::assertInstanceOf(Client::class, $client);
    }
}
