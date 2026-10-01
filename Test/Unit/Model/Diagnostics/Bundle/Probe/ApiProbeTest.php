<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\Probe;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Canada\CanadaAccessChecker;
use Taxcloud\Magento2\Model\Canada\CanadaAccessResult;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Probe\ApiProbe;
use Taxcloud\Magento2\Model\Diagnostics\Bundle\Probe\EndpointCheck;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\AuthMethod;
use Taxcloud\Magento2\Model\Gateway\Rest\AuthProvider;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestResponse;
use Taxcloud\Magento2\Model\Gateway\Rest\RestTransportException;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenCache;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchangeException;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapGateway;
use Taxcloud\Magento2\Test\Unit\Model\Diagnostics\Bundle\DiagnosticsFixture;

/**
 * The probe must tell a network problem from a credential problem, never touch
 * customer data, never mutate anything, and never throw.
 */
#[AllowMockObjectsWithoutExpectations]
class ApiProbeTest extends TestCase
{
    use DiagnosticsFixture;

    /**
     * @var RestClient|\PHPUnit\Framework\MockObject\MockObject
     */
    private $restClient;

    /**
     * @var AuthProvider|\PHPUnit\Framework\MockObject\MockObject
     */
    private $authProvider;

    /**
     * @var EndpointCheck|\PHPUnit\Framework\MockObject\MockObject
     */
    private $endpointCheck;

    /**
     * @var SoapGateway|\PHPUnit\Framework\MockObject\MockObject
     */
    private $soapGateway;

    /**
     * @var CanadaAccessChecker|\PHPUnit\Framework\MockObject\MockObject
     */
    private $canadaAccessChecker;

    protected function setUp(): void
    {
        $this->restClient = $this->createMock(RestClient::class);
        $this->authProvider = $this->createMock(AuthProvider::class);
        $this->endpointCheck = $this->createMock(EndpointCheck::class);
        $this->soapGateway = $this->createMock(SoapGateway::class);
        $this->canadaAccessChecker = $this->createMock(CanadaAccessChecker::class);
    }

    private function probe(): ApiProbe
    {
        $config = new TaxcloudConfig($this->scopeConfig());
        $requestBuilder = $this->createMock(RequestBuilder::class);
        $requestBuilder->method('buildOrigin')->willReturn(null);

        return new ApiProbe(
            $config,
            $this->restClient,
            $this->authProvider,
            $this->createMock(TokenCache::class),
            $this->soapGateway,
            $requestBuilder,
            $this->endpointCheck,
            $this->canadaAccessChecker
        );
    }

    private function network(bool $dns, bool $tls): array
    {
        return ['dns' => ['ok' => $dns], 'tls' => ['ok' => $tls]];
    }

    public function testRestWithApiKeyMakesALookupAndVerifyAgainstTheTestAddressOnly()
    {
        $this->setConfig([
            'tax/taxcloud_settings/enabled' => '1',
            'tax/taxcloud_settings/api_type' => 'rest',
            'tax/taxcloud_settings/rest_api_key' => 'v3-key',
            'tax/taxcloud_settings/rest_connection_id' => 'conn-1',
        ]);
        $this->endpointCheck->method('check')->willReturn($this->network(true, true));

        $calls = [];
        $this->restClient->method('request')->willReturnCallback(
            function ($method, $path, $body, $store, $connectionScoped = true) use (&$calls) {
                $calls[] = [$method, $path, $body, $connectionScoped];
                return $path === '/carts'
                    ? new RestResponse(200, '{"items":[]}')
                    : new RestResponse(422, '{"title":"Invalid address","code":"ADDR"}');
            }
        );

        $result = $this->probe()->probe($this->stores());

        $this->assertCount(1, $result['configurations'], 'three stores with one configuration are probed once');
        $config = $result['configurations'][0];
        $this->assertSame(['us_en', 'us_es', 'ca_en'], $config['stores']);
        $this->assertSame('api_key', $config['authentication']['method']);
        $this->assertTrue($config['calls']['lookup']['success']);
        $this->assertSame(200, $config['calls']['lookup']['http_status']);
        $this->assertFalse($config['calls']['verify_address']['success']);
        $this->assertSame('ADDR', $config['calls']['verify_address']['error_code']);
        $this->assertStringContainsString('Invalid address', $config['calls']['verify_address']['error_message']);
        $this->assertArrayHasKey('duration_ms', $config['calls']['lookup']);

        $this->assertSame(['POST', 'POST'], array_column($calls, 0), 'only Lookup and VerifyAddress — nothing captured');
        $this->assertSame(['/carts', '/tax/verify-address'], array_column($calls, 1));
        $cart = $calls[0][2]['items'][0];
        $this->assertSame(ApiProbe::PROBE_CART_ID, $cart['cartId']);
        $this->assertSame('06851', $cart['destination']['zip']);
        $this->assertSame('162 East Avenue', $calls[1][2]['line1']);
        $this->assertFalse($calls[1][3], 'verify-address is account-level');
    }

    public function testNetworkFailureSkipsTheApiCallsAndIsReportedSeparately()
    {
        $this->setConfig([
            'tax/taxcloud_settings/enabled' => '1',
            'tax/taxcloud_settings/api_type' => 'rest',
            'tax/taxcloud_settings/rest_api_key' => 'v3-key',
            'tax/taxcloud_settings/rest_connection_id' => 'conn-1',
        ]);
        $this->endpointCheck->method('check')->willReturn($this->network(true, false));
        $this->restClient->expects($this->never())->method('request');

        $config = $this->probe()->probe([$this->stores()[1]])['configurations'][0];

        $this->assertSame('TLS handshake failed', $config['calls']['lookup']['skipped']);
        $this->assertSame('TLS handshake failed', $config['calls']['verify_address']['skipped']);
        $this->assertFalse($config['calls']['lookup']['success']);
    }

    public function testV1TokenExchangeFailureIsTheFindingNotAnException()
    {
        $this->setConfig([
            'tax/taxcloud_settings/enabled' => '1',
            'tax/taxcloud_settings/api_type' => 'rest',
            'tax/taxcloud_settings/api_id' => 'id',
            'tax/taxcloud_settings/api_key' => 'key',
            'tax/taxcloud_settings/rest_connection_id' => 'conn-1',
        ]);
        $this->endpointCheck->method('check')->willReturn($this->network(true, true));
        $this->authProvider->method('resolve')->willThrowException(
            new TokenExchangeException(TokenExchangeException::REJECTED, 'TaxCloud rejected the V1 credential pair (HTTP 401).')
        );
        $this->restClient->expects($this->never())->method('request');

        $config = $this->probe()->probe([$this->stores()[1]])['configurations'][0];

        $this->assertSame('v1_token_exchange', $config['authentication']['method']);
        $this->assertFalse($config['authentication']['token_acquired']);
        $this->assertStringContainsString('HTTP 401', $config['authentication']['error']);
        $this->assertArrayHasKey('auth_network', $config['authentication']);
        $this->assertSame('authentication failed', $config['calls']['lookup']['skipped']);
    }

    public function testV1TokenExchangeSuccessAndTransportErrorsAreRecorded()
    {
        $this->setConfig([
            'tax/taxcloud_settings/enabled' => '1',
            'tax/taxcloud_settings/api_type' => 'rest',
            'tax/taxcloud_settings/api_id' => 'id',
            'tax/taxcloud_settings/api_key' => 'key',
            'tax/taxcloud_settings/rest_connection_id' => 'conn-1',
        ]);
        $this->endpointCheck->method('check')->willReturn($this->network(true, true));
        $this->authProvider->method('resolve')->willReturn(new AuthMethod(['Authorization' => 'Bearer t'], true));
        $this->restClient->method('request')->willThrowException(new RestTransportException('Operation timed out after 10001 milliseconds'));

        $config = $this->probe()->probe([$this->stores()[1]])['configurations'][0];

        $this->assertTrue($config['authentication']['token_acquired']);
        $this->assertSame('exchange', $config['authentication']['token_source']);
        $this->assertNull($config['calls']['lookup']['http_status']);
        $this->assertStringContainsString('timed out', $config['calls']['lookup']['error_message']);
        $this->assertSame('RestTransportException', $config['calls']['lookup']['error_type']);
    }

    public function testDisabledStoresAreNotProbedAndSoapWithoutCredentialsIsSkipped()
    {
        $this->setConfig(
            ['tax/taxcloud_settings/enabled' => '1', 'tax/taxcloud_settings/api_type' => 'soap'],
            ['ca' => ['tax/taxcloud_settings/enabled' => '0']]
        );
        $this->endpointCheck->method('check')->willReturn($this->network(true, true));
        $this->soapGateway->expects($this->never())->method('createClient');

        $result = $this->probe()->probe($this->stores());

        $this->assertSame(['ca_en'], $result['skipped_stores_taxcloud_disabled']);
        $config = $result['configurations'][0];
        $this->assertSame('soap', $config['api_type']);
        $this->assertSame('credentials not configured', $config['calls']['lookup']['skipped']);
    }

    /**
     * A store with Canadian tax on gets its own probe group and a
     * canada_access call recorded in the common call shape, so a missing
     * Canadian entitlement shows up in the bundle; stores without it are
     * probed exactly as before, with no Canadian lookup.
     */
    public function testCanadaAccessIsProbedOnlyForStoresWithCanadianTaxOn()
    {
        $this->setConfig(
            [
                'tax/taxcloud_settings/enabled' => '1',
                'tax/taxcloud_settings/api_type' => 'rest',
                'tax/taxcloud_settings/rest_api_key' => 'v3-key',
                'tax/taxcloud_settings/rest_connection_id' => 'conn-1',
            ],
            ['ca' => ['tax/taxcloud_settings/canada_tax_enabled' => '1']]
        );
        $this->endpointCheck->method('check')->willReturn($this->network(true, true));
        $this->restClient->method('request')->willReturn(new RestResponse(200, '{"items":[]}'));
        $this->canadaAccessChecker->expects($this->once())
            ->method('check')
            ->with(3)
            ->willReturn(new CanadaAccessResult(
                CanadaAccessResult::NOT_ENABLED,
                'Contact TaxCloud support to enable it.',
                null,
                422,
                120
            ));

        $result = $this->probe()->probe($this->stores());

        $this->assertCount(2, $result['configurations'], 'Canada on splits the ca store into its own group');
        [$us, $ca] = $result['configurations'];
        $this->assertSame(['us_en', 'us_es'], $us['stores']);
        $this->assertArrayNotHasKey('canada_access', $us['calls']);

        $this->assertSame(['ca_en'], $ca['stores']);
        $call = $ca['calls']['canada_access'];
        $this->assertFalse($call['success']);
        $this->assertSame('not_enabled', $call['outcome']);
        $this->assertSame(422, $call['http_status']);
        $this->assertSame(120, $call['duration_ms']);
        $this->assertSame('Contact TaxCloud support to enable it.', $call['error_message']);
        $this->assertStringEndsWith('/tax/connections/conn-1/carts', $call['url']);
    }

    public function testCanadaAccessIsSkippedWhenTheConnectionIsBlocked()
    {
        $this->setConfig([
            'tax/taxcloud_settings/enabled' => '1',
            'tax/taxcloud_settings/api_type' => 'rest',
            'tax/taxcloud_settings/rest_api_key' => 'v3-key',
            'tax/taxcloud_settings/rest_connection_id' => 'conn-1',
            'tax/taxcloud_settings/canada_tax_enabled' => '1',
        ]);
        $this->endpointCheck->method('check')->willReturn($this->network(false, false));
        $this->canadaAccessChecker->expects($this->never())->method('check');

        $config = $this->probe()->probe([$this->stores()[1]])['configurations'][0];

        $this->assertSame('DNS resolution failed', $config['calls']['canada_access']['skipped']);
    }
}
