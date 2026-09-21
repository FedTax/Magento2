<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Canada;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Canada\CanadaAccessChecker;
use Taxcloud\Magento2\Model\Canada\CanadaAccessResult;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\PingResult;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestConfigurationException;
use Taxcloud\Magento2\Model\Gateway\Rest\RestRequestBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\RestResponse;
use Taxcloud\Magento2\Model\Gateway\Rest\RestTransportException;
use Taxcloud\Magento2\Model\ProductTicService;

/**
 * The Canada access check has three answers, and only one of them tells the
 * merchant to contact TaxCloud support about Canada. Getting that wrong either
 * hides a missing entitlement or sends a merchant with broken credentials to
 * support for the wrong reason, so every path into each answer is pinned.
 */
#[AllowMockObjectsWithoutExpectations]
class CanadaAccessCheckerTest extends TestCase
{
    private const STORE_ID = 5;

    private const ORIGIN = [
        'Address1' => '1401 Lavaca St',
        'Address2' => '',
        'City' => 'Austin',
        'State' => 'TX',
        'Zip5' => '78701',
        'Zip4' => '',
    ];

    /**
     * @var TaxcloudConfig&\PHPUnit\Framework\MockObject\MockObject
     */
    private $config;

    /**
     * @var RestClient&\PHPUnit\Framework\MockObject\MockObject
     */
    private $restClient;

    /**
     * @var RequestBuilder&\PHPUnit\Framework\MockObject\MockObject
     */
    private $requestBuilder;

    protected function setUp(): void
    {
        $this->config = $this->createMock(TaxcloudConfig::class);
        $this->restClient = $this->createMock(RestClient::class);
        $this->requestBuilder = $this->createMock(RequestBuilder::class);
    }

    private function checker(): CanadaAccessChecker
    {
        return new CanadaAccessChecker(
            $this->config,
            $this->restClient,
            $this->requestBuilder,
            new RestRequestBuilder(
                $this->createMock(TaxcloudConfig::class),
                $this->createMock(RequestBuilder::class),
                $this->createMock(ProductTicService::class)
            )
        );
    }

    /**
     * A REST store with a connection and a valid origin, configured only for
     * STORE_ID so an ambient-store read finds nothing.
     */
    private function configureReadyStore(): void
    {
        $this->config->method('getApiType')->willReturnMap([[self::STORE_ID, 'rest'], [null, 'soap']]);
        $this->config->method('getRestConnectionId')->willReturnMap([[self::STORE_ID, 'conn-5'], [null, null]]);
        $this->requestBuilder->method('buildOrigin')->willReturnMap([[self::STORE_ID, self::ORIGIN], [null, null]]);
        $this->restClient->method('pingForScope')->willReturn(new PingResult(PingResult::OK));
    }

    private function cartResponse(float $rate): RestResponse
    {
        return new RestResponse(200, (string) json_encode(['items' => [[
            'cartId' => CanadaAccessChecker::SAMPLE_CART_ID,
            'lineItems' => [['index' => 0, 'tax' => ['rate' => $rate, 'amount' => $rate * 100]]],
        ]]]));
    }

    public function testAnAccountWithCanadaIsConfirmedWithTheSampleRate()
    {
        $this->configureReadyStore();
        $sent = null;
        $this->restClient->expects($this->once())->method('request')->willReturnCallback(
            function ($method, $path, $body, $store) use (&$sent) {
                $sent = [$method, $path, $body, $store];
                return $this->cartResponse(0.14975);
            }
        );

        $result = $this->checker()->check(self::STORE_ID);

        $this->assertSame(CanadaAccessResult::ENABLED, $result->getOutcome());
        $this->assertTrue($result->isEnabled());
        $this->assertSame(0.14975, $result->getRate());
        $this->assertSame(200, $result->getHttpStatus());
        $this->assertStringContainsString('14.975%', (string) $result->getMessage());

        // One sample cart under the fixed id, for the checked store, from its
        // origin to Toronto — never an order.
        [$method, $path, $body, $store] = $sent;
        $this->assertSame(['POST', '/carts', self::STORE_ID], [$method, $path, $store]);
        $cart = $body['items'][0];
        $this->assertSame(CanadaAccessChecker::SAMPLE_CART_ID, $cart['cartId']);
        $this->assertSame(['currencyCode' => 'CAD'], $cart['currency']);
        $this->assertSame(
            [
                'line1' => '100 Queen St W',
                'city' => 'Toronto',
                'state' => 'ON',
                'zip' => 'M5H 2N2',
                'countryCode' => 'CA',
            ],
            $cart['destination']
        );
        $this->assertSame('US', $cart['origin']['countryCode']);
        $this->assertSame('78701', $cart['origin']['zip']);
        $this->assertCount(1, $cart['lineItems']);
    }

    public function testAZeroRateIsNotAccess()
    {
        $this->configureReadyStore();
        $this->restClient->method('request')->willReturn($this->cartResponse(0.0));

        $result = $this->checker()->check(self::STORE_ID);

        $this->assertSame(CanadaAccessResult::NOT_ENABLED, $result->getOutcome());
        $this->assertStringContainsString('Contact TaxCloud support', (string) $result->getMessage());
    }

    /**
     * With the connection known good, TaxCloud refusing the Canadian cart is
     * about Canada — including a 403.
     *
     * @dataProvider refusalProvider
     */
    #[DataProvider('refusalProvider')]
    public function testARefusedSampleMeansCanadaIsNotEnabled(int $status, string $body)
    {
        $this->configureReadyStore();
        $this->restClient->method('request')->willReturn(new RestResponse($status, $body));

        $result = $this->checker()->check(self::STORE_ID);

        $this->assertSame(CanadaAccessResult::NOT_ENABLED, $result->getOutcome());
        $this->assertSame($status, $result->getHttpStatus());
        $message = (string) $result->getMessage();
        $this->assertStringContainsString('Contact TaxCloud support', $message);
        $this->assertStringContainsString('HTTP ' . $status, $message, 'TaxCloud\'s reason is shown');
    }

    public static function refusalProvider(): array
    {
        return [
            'validation error' => [422, '{"title":"Unprocessable Entity","detail":"country not enabled"}'],
            'forbidden' => [403, '{"title":"Forbidden"}'],
            'bad request' => [400, '{"title":"Bad Request","detail":"unsupported country code"}'],
        ];
    }

    /**
     * @dataProvider pingFailureProvider
     */
    #[DataProvider('pingFailureProvider')]
    public function testBrokenCredentialsOrConnectionAreNeverReportedAsMissingCanada(
        PingResult $ping,
        string $expectedFragment
    ) {
        $this->config->method('getApiType')->willReturn('rest');
        $this->config->method('getRestConnectionId')->willReturn('conn-5');
        $this->requestBuilder->method('buildOrigin')->willReturn(self::ORIGIN);
        $this->restClient->method('pingForScope')->with(self::STORE_ID)->willReturn($ping);
        $this->restClient->expects($this->never())->method('request');

        $result = $this->checker()->check(self::STORE_ID);

        $this->assertSame(CanadaAccessResult::UNAVAILABLE, $result->getOutcome());
        $this->assertStringContainsString($expectedFragment, (string) $result->getMessage());
        $this->assertStringNotContainsString('TaxCloud support', (string) $result->getMessage());
    }

    public static function pingFailureProvider(): array
    {
        return [
            'credentials rejected' => [new PingResult(PingResult::AUTH_FAILED), 'credentials'],
            'unknown connection' => [new PingResult(PingResult::UNKNOWN_CONNECTION), 'Connection ID'],
            'network' => [new PingResult(PingResult::TRANSPORT_ERROR, 'timed out'), 'timed out'],
        ];
    }

    /**
     * @dataProvider transientFailureProvider
     */
    #[DataProvider('transientFailureProvider')]
    public function testTransientSampleFailuresCannotTell(int $status)
    {
        $this->configureReadyStore();
        $this->restClient->method('request')->willReturn(new RestResponse($status, '{"title":"Oops"}'));

        $result = $this->checker()->check(self::STORE_ID);

        $this->assertSame(CanadaAccessResult::UNAVAILABLE, $result->getOutcome());
        $this->assertStringNotContainsString('TaxCloud support', (string) $result->getMessage());
    }

    public static function transientFailureProvider(): array
    {
        return [
            'unauthorized' => [401],
            'not found' => [404],
            'throttled' => [429],
            'server error' => [503],
        ];
    }

    public function testATransportFailureOnTheSampleCannotTell()
    {
        $this->configureReadyStore();
        $this->restClient->method('request')->willThrowException(new RestTransportException('Connection reset'));

        $result = $this->checker()->check(self::STORE_ID);

        $this->assertSame(CanadaAccessResult::UNAVAILABLE, $result->getOutcome());
        $this->assertStringContainsString('Connection reset', (string) $result->getMessage());
    }

    public function testAPingConfigurationErrorCannotTell()
    {
        $this->config->method('getApiType')->willReturn('rest');
        $this->config->method('getRestConnectionId')->willReturn('conn-5');
        $this->requestBuilder->method('buildOrigin')->willReturn(self::ORIGIN);
        $this->restClient->method('pingForScope')
            ->willThrowException(new RestConfigurationException('No usable credentials'));

        $result = $this->checker()->check(self::STORE_ID);

        $this->assertSame(CanadaAccessResult::UNAVAILABLE, $result->getOutcome());
        $this->assertStringContainsString('No usable credentials', (string) $result->getMessage());
    }

    public function testASoapStoreIsNotCheckedAtAll()
    {
        $this->config->method('getApiType')->willReturn('soap');
        $this->restClient->expects($this->never())->method('pingForScope');
        $this->restClient->expects($this->never())->method('request');

        $result = $this->checker()->check(self::STORE_ID);

        $this->assertSame(CanadaAccessResult::UNAVAILABLE, $result->getOutcome());
        $this->assertStringContainsString('V3 REST', (string) $result->getMessage());
    }

    public function testMissingConnectionIdIsNotChecked()
    {
        $this->config->method('getApiType')->willReturn('rest');
        $this->config->method('getRestConnectionId')->willReturn(null);
        $this->restClient->expects($this->never())->method('request');

        $result = $this->checker()->check(self::STORE_ID);

        $this->assertSame(CanadaAccessResult::UNAVAILABLE, $result->getOutcome());
        $this->assertStringContainsString('Connection ID', (string) $result->getMessage());
    }

    public function testAnInvalidOriginIsNotChecked()
    {
        $this->config->method('getApiType')->willReturn('rest');
        $this->config->method('getRestConnectionId')->willReturn('conn-5');
        $this->requestBuilder->method('buildOrigin')->willReturn(null);
        $this->restClient->expects($this->never())->method('request');

        $result = $this->checker()->check(self::STORE_ID);

        $this->assertSame(CanadaAccessResult::UNAVAILABLE, $result->getOutcome());
        $this->assertStringContainsString('shipping origin', (string) $result->getMessage());
    }

    /**
     * Everything — API type, connection, origin, ping — is read for the store
     * being checked. The ready store is configured only for STORE_ID; an
     * ambient-store read would see SOAP and return before any call.
     */
    public function testEverythingResolvesAgainstTheCheckedStore()
    {
        $this->configureReadyStore();
        $this->restClient->method('request')->willReturn($this->cartResponse(0.13));

        $this->assertTrue($this->checker()->check(self::STORE_ID)->isEnabled());
        $this->assertFalse($this->checker()->check()->isEnabled());
    }
}
