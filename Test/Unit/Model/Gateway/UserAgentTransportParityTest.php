<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Webapi\Soap\ClientFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Rest\AuthProvider;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenCache;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchange;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapGateway;
use Taxcloud\Magento2\Model\Gateway\UserAgent;
use Taxcloud\Magento2\Test\Unit\BuildsUserAgent;

/**
 * One installation, one identity.
 *
 * Each transport reaches the header its own way — a cURL header on v3, two
 * SoapClient options on v1 — so nothing structural stops them drifting apart.
 * Attribution only works if they don't: this pins all three to a byte-identical
 * string built from a single shared source.
 */
#[AllowMockObjectsWithoutExpectations]
class UserAgentTransportParityTest extends TestCase
{
    use BuildsUserAgent;

    private const CONN = '25eb9b97-5acb-492d-b720-c03e79cf715a';

    /**
     * Header captured from the v3 REST operation path.
     *
     * @param UserAgent $userAgent
     * @return string|null
     */
    private function restHeader(UserAgent $userAgent): ?string
    {
        $curl = $this->createMock(Curl::class);
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($curl);

        $headers = [];
        $curl->method('addHeader')->willReturnCallback(static function ($n, $v) use (&$headers) {
            $headers[$n] = $v;
        });
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{}');

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            [
                TaxcloudConfig::XML_PATH_REST_CONNECTION_ID,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                null,
                self::CONN,
            ],
            [
                TaxcloudConfig::XML_PATH_REST_API_KEY,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                null,
                'rest-api-key',
            ],
        ]);
        $config = new TaxcloudConfig($scopeConfig);

        $client = new RestClient(
            $curlFactory,
            $config,
            new AuthProvider(
                $config,
                $this->createMock(TokenExchange::class),
                $this->createMock(TokenCache::class)
            ),
            $userAgent
        );
        $client->request('GET', '/carts');

        return $headers['User-Agent'] ?? null;
    }

    /**
     * Header captured from the v3 credential-exchange path.
     *
     * @param UserAgent $userAgent
     * @return string|null
     */
    private function exchangeHeader(UserAgent $userAgent): ?string
    {
        $curl = $this->createMock(Curl::class);
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($curl);

        $headers = [];
        $curl->method('addHeader')->willReturnCallback(static function ($n, $v) use (&$headers) {
            $headers[$n] = $v;
        });
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn(json_encode([
            'access_token' => 'jwt',
            'access_token_validTo' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
        ]));

        $exchange = new TokenExchange(
            $curlFactory,
            new TaxcloudConfig($this->createMock(ScopeConfigInterface::class)),
            $userAgent
        );
        $exchange->exchange('v1-id', 'v1-key');

        return $headers['User-Agent'] ?? null;
    }

    /**
     * The two places the v1 SOAP transport carries it.
     *
     * @param UserAgent $userAgent
     * @return array{option: string|null, wsdl: string|null}
     */
    private function soapHeaders(UserAgent $userAgent): array
    {
        $config = $this->createMock(TaxcloudConfig::class);
        $config->method('getSoapTimeout')->willReturn(10);

        $gateway = new SoapGateway(
            $this->createMock(ClientFactory::class),
            $config,
            $userAgent,
            new NullLogger()
        );
        $options = $gateway->buildSoapOptions();
        $context = stream_context_get_options($options['stream_context']);

        return [
            'option' => $options['user_agent'] ?? null,
            'wsdl' => $context['http']['user_agent'] ?? null,
        ];
    }

    public function testEveryTransportSendsAByteIdenticalUserAgent()
    {
        $userAgent = $this->userAgent();
        $expected = $this->expectedUserAgent();

        $soap = $this->soapHeaders($userAgent);

        $this->assertSame($expected, $this->restHeader($userAgent), 'v3 REST operation');
        $this->assertSame($expected, $this->exchangeHeader($userAgent), 'v3 credential exchange');
        $this->assertSame($expected, $soap['option'], 'v1 SOAP call');
        $this->assertSame($expected, $soap['wsdl'], 'v1 WSDL fetch');
    }

    /**
     * Degraded is still one identity: a component that cannot be resolved must
     * degrade the same way everywhere, or the same install would look like two.
     */
    public function testTransportsStayIdenticalWhenComponentsDegrade()
    {
        $userAgent = $this->userAgent('', '', '');
        $expected = $this->expectedUserAgent('unknown', 'unknown', 'unknown');

        $soap = $this->soapHeaders($userAgent);

        $this->assertSame($expected, $this->restHeader($userAgent));
        $this->assertSame($expected, $this->exchangeHeader($userAgent));
        $this->assertSame($expected, $soap['option']);
        $this->assertSame($expected, $soap['wsdl']);
    }
}
