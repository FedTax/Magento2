<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway\Soap;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Gateway\PingResult;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapClientProviderInterface;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapPing;
use Taxcloud\Magento2\Test\Unit\Double as Dbl;

/**
 * V1 credential verification: the SOAP Ping envelope must map onto the same
 * outcome vocabulary the REST ping uses, and transport failures must never
 * echo the credential pair.
 */
#[AllowMockObjectsWithoutExpectations]
class SoapPingTest extends TestCase
{
    private const API_ID = 'legacy-login-id';
    private const API_KEY = 'legacy-secret-key';

    /**
     * @param \SoapClient|null $client
     * @param mixed $store expected store forwarded to the provider
     */
    private function soapPing($client, $store = null): SoapPing
    {
        $provider = $this->createMock(SoapClientProviderInterface::class);
        $provider->method('getClient')->with($store)->willReturn($client);

        return new SoapPing($provider);
    }

    /**
     * @param string $responseType
     * @return object PingRsp-shaped envelope
     */
    private static function envelope(string $responseType): object
    {
        return (object) ['PingResult' => (object) ['ResponseType' => $responseType]];
    }

    public function testOkEnvelopeIsSuccessAndCredentialsAreForwarded()
    {
        $client = $this->getMockBuilder(Dbl\SoapClientDouble::class)
            ->onlyMethods(['Ping'])
            ->getMock();
        $client->expects($this->once())
            ->method('Ping')
            ->with(['apiLoginID' => self::API_ID, 'apiKey' => self::API_KEY])
            ->willReturn(self::envelope('OK'));

        $result = $this->soapPing($client)->ping(self::API_ID, self::API_KEY);

        $this->assertTrue($result->isSuccess());
    }

    /**
     * TaxCloud answers Ping with a non-OK envelope when the pair is invalid —
     * that is a credential failure, not a transport one.
     */
    public function testNonOkEnvelopeIsAuthFailure()
    {
        $client = $this->getMockBuilder(Dbl\SoapClientDouble::class)
            ->onlyMethods(['Ping'])
            ->getMock();
        $client->method('Ping')->willReturn(self::envelope('Error'));

        $result = $this->soapPing($client)->ping(self::API_ID, self::API_KEY);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(PingResult::AUTH_FAILED, $result->getOutcome());
    }

    /**
     * A malformed response (no PingResult member at all) must not be read as
     * success.
     */
    public function testMalformedEnvelopeIsAuthFailure()
    {
        $client = $this->getMockBuilder(Dbl\SoapClientDouble::class)
            ->onlyMethods(['Ping'])
            ->getMock();
        $client->method('Ping')->willReturn((object) []);

        $result = $this->soapPing($client)->ping(self::API_ID, self::API_KEY);

        $this->assertSame(PingResult::AUTH_FAILED, $result->getOutcome());
    }

    public function testUnavailableClientIsTransportError()
    {
        $result = $this->soapPing(null)->ping(self::API_ID, self::API_KEY);

        $this->assertSame(PingResult::TRANSPORT_ERROR, $result->getOutcome());
        $this->assertNotSame('', $result->getReason());
    }

    public function testSoapFaultIsTransportErrorScrubbedOfCredentials()
    {
        $client = $this->getMockBuilder(Dbl\SoapClientDouble::class)
            ->onlyMethods(['Ping'])
            ->getMock();
        $client->method('Ping')->willThrowException(
            new \Exception('fault while authenticating ' . self::API_ID . ' with ' . self::API_KEY)
        );

        $result = $this->soapPing($client)->ping(self::API_ID, self::API_KEY);

        $this->assertSame(PingResult::TRANSPORT_ERROR, $result->getOutcome());
        $this->assertStringNotContainsString(self::API_ID, $result->getReason());
        $this->assertStringNotContainsString(self::API_KEY, $result->getReason());
    }

    /**
     * The provider must receive the store being tested so WSDL endpoint and
     * timeout resolve for that scope, not the ambient one.
     */
    public function testStoreIsForwardedToTheClientProvider()
    {
        $client = $this->getMockBuilder(Dbl\SoapClientDouble::class)
            ->onlyMethods(['Ping'])
            ->getMock();
        $client->method('Ping')->willReturn(self::envelope('OK'));

        $result = $this->soapPing($client, 7)->ping(self::API_ID, self::API_KEY, 7);

        $this->assertTrue($result->isSuccess());
    }
}
