<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway\Soap;

use Magento\Framework\Webapi\Soap\ClientFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapGateway;
use Taxcloud\Magento2\Test\Unit\Double\SoapClientDouble;

/**
 * Covers the SOAP client provisioning extracted from Model\Api: option
 * construction from the configured timeout, lazy/cached client creation, and
 * the fail-soft null return when the WSDL fetch throws.
 */
#[AllowMockObjectsWithoutExpectations]
class SoapGatewayTest extends TestCase
{
    private $soapClientFactory;
    private $config;

    protected function setUp(): void
    {
        $this->soapClientFactory = $this->createMock(ClientFactory::class);
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->config->method('getSoapTimeout')->willReturn(10);
    }

    private function gateway(): SoapGateway
    {
        return new SoapGateway($this->soapClientFactory, $this->config, new NullLogger());
    }

    public function testBuildSoapOptionsUsesConfiguredTimeout()
    {
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->config->method('getSoapTimeout')->willReturn(25);

        $options = $this->gateway()->buildSoapOptions();

        $this->assertSame(25, $options['connection_timeout']);
        $this->assertSame(WSDL_CACHE_BOTH, $options['cache_wsdl']);
        $this->assertTrue($options['keep_alive']);
        $this->assertIsResource($options['stream_context']);
    }

    public function testGetClientBuildsFromFactoryAtWsdlEndpoint()
    {
        $soapClient = $this->getMockBuilder(SoapClientDouble::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->soapClientFactory->expects($this->once())
            ->method('create')
            ->with(SoapGateway::WSDL, $this->callback(static fn ($options) => is_array($options)))
            ->willReturn($soapClient);

        $gateway = $this->gateway();
        $this->assertSame($soapClient, $gateway->getClient());
    }

    public function testGetClientIsCachedAcrossCalls()
    {
        $soapClient = $this->getMockBuilder(SoapClientDouble::class)
            ->disableOriginalConstructor()
            ->getMock();

        // expects once() proves the second getClient() reuses the cached client.
        $this->soapClientFactory->expects($this->once())
            ->method('create')
            ->willReturn($soapClient);

        $gateway = $this->gateway();
        $gateway->getClient();
        $this->assertSame($soapClient, $gateway->getClient());
    }

    public function testGetClientReturnsNullWhenFactoryThrows()
    {
        $this->soapClientFactory->method('create')
            ->willThrowException(new \RuntimeException('WSDL fetch failed'));

        $this->assertNull($this->gateway()->getClient());
    }
}
