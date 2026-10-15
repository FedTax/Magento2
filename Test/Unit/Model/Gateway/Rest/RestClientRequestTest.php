<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway\Rest;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Rest\AuthProvider;
use Taxcloud\Magento2\Model\Gateway\Rest\BearerToken;
use Taxcloud\Magento2\Model\Gateway\Rest\FinalStatusCurl;
use Taxcloud\Magento2\Model\Gateway\Rest\FinalStatusCurlFactory;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestConfigurationException;
use Taxcloud\Magento2\Model\Gateway\Rest\RestTransportException;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenCache;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchange;
use Taxcloud\Magento2\Test\Unit\BuildsUserAgent;

/**
 * The generic request() entry point: URL and header construction for
 * connection-scoped and account-level paths, JSON body handling, the Bearer
 * 401-invalidate-retry-once rule, and scrubbed transport failures.
 */
#[AllowMockObjectsWithoutExpectations]
class RestClientRequestTest extends TestCase
{
    use BuildsUserAgent;

    private const CONN = '25eb9b97-5acb-492d-b720-c03e79cf715a';

    /**
     * @var FinalStatusCurl&\PHPUnit\Framework\MockObject\MockObject
     */
    private $curl;

    /**
     * @var TokenExchange&\PHPUnit\Framework\MockObject\MockObject
     */
    private $exchange;

    /**
     * @var TokenCache&\PHPUnit\Framework\MockObject\MockObject
     */
    private $cache;

    /**
     * @param array $configMap
     * @param FinalStatusCurl|null $transport A scripted client to use instead of the mock
     * @return RestClient
     */
    private function client(array $configMap, ?FinalStatusCurl $transport = null): RestClient
    {
        $this->curl = $this->createMock(FinalStatusCurl::class);
        $curlFactory = $this->createMock(FinalStatusCurlFactory::class);
        $curlFactory->method('create')->willReturn($transport ?? $this->curl);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap($configMap);
        $config = new TaxcloudConfig($scopeConfig);

        $this->exchange = $this->createMock(TokenExchange::class);
        $this->cache = $this->createMock(TokenCache::class);

        return new RestClient(
            $curlFactory,
            $config,
            new AuthProvider($config, $this->exchange, $this->cache),
            $this->userAgent()
        );
    }

    /**
     * A real FinalStatusCurl with only the socket faked: post()/get() replay
     * raw header lines through the production header callback, then set the
     * body. Status parsing is the code under test, not a stub.
     *
     * @param string[] $headerLines
     * @param string $body
     * @return FinalStatusCurl
     */
    private static function scriptedCurl(array $headerLines, string $body): FinalStatusCurl
    {
        return new class ($headerLines, $body) extends FinalStatusCurl {
            /**
             * @var string[]
             */
            private $lines;

            /**
             * @var string
             */
            private $scriptedBody;

            /**
             * @param string[] $lines
             * @param string $body
             */
            public function __construct(array $lines, string $body)
            {
                parent::__construct();
                $this->lines = $lines;
                $this->scriptedBody = $body;
            }

            /**
             * @inheritdoc
             */
            public function post($uri, $params)
            {
                $this->replay();
            }

            /**
             * @inheritdoc
             */
            public function get($uri)
            {
                $this->replay();
            }

            /**
             * @return void
             */
            private function replay(): void
            {
                foreach ($this->lines as $line) {
                    $this->parseHeaders(null, $line);
                }
                $this->_responseBody = $this->scriptedBody;
            }
        };
    }

    private static function value(string $path, $value): array
    {
        return [$path, ScopeInterface::SCOPE_STORE, null, $value];
    }

    private static function apiKeyScopeConfig(): array
    {
        return [
            self::value(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, self::CONN),
            self::value(TaxcloudConfig::XML_PATH_REST_API_KEY, 'rest-api-key'),
        ];
    }

    private static function bearerScopeConfig(): array
    {
        return [
            self::value(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, self::CONN),
            self::value(TaxcloudConfig::XML_PATH_API_ID, 'v1-id'),
            self::value(TaxcloudConfig::XML_PATH_API_KEY, 'v1-key'),
        ];
    }

    public function testPostBuildsConnectionScopedUrlWithJsonBodyAndHeaders()
    {
        $client = $this->client(self::apiKeyScopeConfig());

        $headers = [];
        $this->curl->method('addHeader')->willReturnCallback(static function ($n, $v) use (&$headers) {
            $headers[$n] = $v;
        });
        $this->curl->expects($this->once())
            ->method('post')
            ->with(
                TaxcloudConfig::DEFAULT_REST_ENDPOINT . '/tax/connections/' . self::CONN . '/carts',
                '{"items":[]}'
            );
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{"items":[]}');

        $response = $client->request('POST', '/carts', ['items' => []]);

        $this->assertTrue($response->isSuccess());
        $this->assertSame(['items' => []], $response->getBody());
        $this->assertSame('rest-api-key', $headers['X-API-KEY']);
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame('application/json', $headers['Accept']);
        $this->assertSame($this->expectedUserAgent(), $headers['User-Agent']);
    }

    /**
     * request() is the single method every v3 operation funnels through, so
     * identifying it here is what makes the coverage structural rather than a
     * per-operation checklist — including on account-level paths, which skip
     * the connection prefix and could plausibly have taken another route.
     */
    public function testAccountLevelRequestCarriesTheUserAgent()
    {
        $client = $this->client(self::apiKeyScopeConfig());

        $headers = [];
        $this->curl->method('addHeader')->willReturnCallback(static function ($n, $v) use (&$headers) {
            $headers[$n] = $v;
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');

        $client->request('GET', '/tax/verify-address', null, null, false);

        $this->assertSame($this->expectedUserAgent(), $headers['User-Agent'] ?? null);
    }

    public function testGetOnAccountLevelPathSkipsConnectionPrefixAndBody()
    {
        $client = $this->client(self::apiKeyScopeConfig());

        $headers = [];
        $this->curl->method('addHeader')->willReturnCallback(static function ($n, $v) use (&$headers) {
            $headers[$n] = $v;
        });
        $this->curl->expects($this->once())
            ->method('get')
            ->with(TaxcloudConfig::DEFAULT_REST_ENDPOINT . '/tax/verify-address');
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');

        $client->request('GET', '/tax/verify-address', null, null, false);

        $this->assertArrayNotHasKey('Content-Type', $headers);
    }

    public function testBearer401InvalidatesAndRetriesOnceWithFreshToken()
    {
        $client = $this->client(self::bearerScopeConfig());

        // Stale cached token first, fresh exchange after invalidation.
        $this->cache->method('get')->willReturnOnConsecutiveCalls(
            new BearerToken('stale-jwt', time() + 3600),
            null
        );
        $this->exchange->method('exchange')->willReturn(new BearerToken('fresh-jwt', time() + 3600));
        $this->cache->expects($this->once())->method('invalidate');

        $authHeaders = [];
        $this->curl->method('addHeader')->willReturnCallback(static function ($n, $v) use (&$authHeaders) {
            if ($n === 'Authorization') {
                $authHeaders[] = $v;
            }
        });
        $this->curl->method('getStatus')->willReturnOnConsecutiveCalls(401, 201);
        $this->curl->method('getBody')->willReturn('{"orderId":"100000001"}');
        $this->curl->expects($this->exactly(2))->method('post');

        $response = $client->request('POST', '/orders', ['orderId' => '100000001']);

        $this->assertSame(201, $response->getStatus());
        $this->assertSame(['Bearer stale-jwt', 'Bearer fresh-jwt'], $authHeaders);
    }

    public function testApiKey401IsReturnedWithoutRetry()
    {
        $client = $this->client(self::apiKeyScopeConfig());

        $this->curl->method('getStatus')->willReturn(401);
        $this->curl->method('getBody')->willReturn('');
        $this->curl->expects($this->once())->method('post');
        $this->cache->expects($this->never())->method('invalidate');

        $response = $client->request('POST', '/carts', ['items' => []]);

        $this->assertTrue($response->isUnauthorized());
    }

    public function testMissingConnectionIdFailsLocallyForConnectionScopedCalls()
    {
        $client = $this->client([
            self::value(TaxcloudConfig::XML_PATH_REST_API_KEY, 'rest-api-key'),
        ]);

        $this->expectException(RestConfigurationException::class);
        $client->request('POST', '/carts', ['items' => []]);
    }

    public function testTransportFailureThrowsScrubbedException()
    {
        $client = $this->client(self::apiKeyScopeConfig());

        $this->curl->method('post')->willThrowException(
            new \Exception('Could not resolve host for /tax/connections/' . self::CONN . '/carts')
        );

        try {
            $client->request('POST', '/carts', ['items' => []]);
            $this->fail('Expected RestTransportException');
        } catch (RestTransportException $e) {
            $this->assertStringNotContainsString(self::CONN, $e->getMessage());
            $this->assertStringContainsString('***', $e->getMessage());
        }
    }

    /**
     * DELETE became supported when certificate deletion arrived — v3 offers it
     * no other way. PATCH and PUT stay rejected: nothing in the module needs
     * them, and a typo'd verb should fail loudly rather than be sent.
     */
    public function testUnsupportedMethodIsRejected()
    {
        $client = $this->client(self::apiKeyScopeConfig());

        $this->expectException(\InvalidArgumentException::class);
        $client->request('PATCH', '/carts', null);
    }

    /**
     * Older libcurl adds `Expect: 100-continue` to HTTP/1.1 bodies over 1 KiB
     * on its own; an empty Expect is how it is told not to. Asserted per verb
     * because send() is shared — a header set on only one branch would leave
     * the others soliciting an interim response.
     */
    public function testEveryMethodSuppressesExpectContinue()
    {
        foreach (['POST' => ['items' => []], 'GET' => null, 'DELETE' => null] as $method => $body) {
            $client = $this->client(self::apiKeyScopeConfig());

            $headers = [];
            $this->curl->method('addHeader')->willReturnCallback(static function ($n, $v) use (&$headers) {
                $headers[$n] = $v;
            });
            $this->curl->method('getStatus')->willReturn(200);
            $this->curl->method('getBody')->willReturn('{}');

            $client->request($method, '/carts', $body);

            $this->assertArrayHasKey('Expect', $headers, $method . ' must suppress Expect');
            $this->assertSame('', $headers['Expect'], $method . ' must suppress Expect');
        }
    }

    /**
     * The production failure (CXRE-132): TaxCloud answered `100 Continue`
     * then 200 with a priced cart, and the lookup fell back to Magento rates.
     */
    public function testInterimContinueBeforeSuccessIsReadAsTheSuccess()
    {
        $cart = '{"items":[{"cartId":"q1","lineItems":[{"index":0,"tax":{"amount":2.58}}]}]}';
        $client = $this->client(self::apiKeyScopeConfig(), self::scriptedCurl([
            "HTTP/1.1 100 Continue\r\n",
            "\r\n",
            "HTTP/1.1 200 OK\r\n",
            "Content-Type: application/json\r\n",
            "\r\n",
        ], $cart));

        $response = $client->request('POST', '/carts', ['items' => []]);

        $this->assertSame(200, $response->getStatus());
        $this->assertTrue($response->isSuccess());
        $this->assertSame(2.58, $response->getBody()['items'][0]['lineItems'][0]['tax']['amount']);
    }

    /**
     * A real error behind the interim response keeps its own status and
     * detail; the merchant log read "HTTP 100 Unprocessable Entity" for this.
     */
    public function testInterimContinueBeforeValidationErrorIsReadAsTheError()
    {
        $problem = '{"title":"Unprocessable Entity","detail":"validation failed",'
            . '"errors":[{"location":"body.items[0].destination.line1","message":"expected length >= 1"}]}';
        $client = $this->client(self::apiKeyScopeConfig(), self::scriptedCurl([
            "HTTP/1.1 100 Continue\r\n",
            "\r\n",
            "HTTP/1.1 422 Unprocessable Entity\r\n",
            "Content-Type: application/problem+json\r\n",
            "\r\n",
        ], $problem));

        $response = $client->request('POST', '/carts', ['items' => []]);

        $this->assertSame(422, $response->getStatus());
        $this->assertFalse($response->isSuccess());
        $this->assertFalse($response->isRetryable());
        $this->assertSame(
            'HTTP 422 Unprocessable Entity - validation failed'
            . ' - (body.items[0].destination.line1: expected length >= 1)',
            $response->errorDetail()
        );
    }
}
