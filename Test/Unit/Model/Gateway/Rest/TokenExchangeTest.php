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
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchange;
use Taxcloud\Magento2\Model\Gateway\Rest\TokenExchangeException;

/**
 * The v1→v3 exchange call: request construction against the documented-by-woo
 * endpoint shape, outcome mapping (rejected vs unreachable), and the
 * credential-free-messages guarantee.
 */
#[AllowMockObjectsWithoutExpectations]
class TokenExchangeTest extends TestCase
{
    private const API_ID = 'legacy-login';
    private const API_KEY = 'fb3e8a3a-057b-4628-a743-c89b4e37dfa8';

    /**
     * @var Curl&\PHPUnit\Framework\MockObject\MockObject
     */
    private $curl;

    private function exchange(array $configMap = []): TokenExchange
    {
        $this->curl = $this->createMock(Curl::class);

        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap($configMap);

        return new TokenExchange($curlFactory, new TaxcloudConfig($scopeConfig));
    }

    public function testSuccessfulExchangePostsThePairAndParsesTokenAndValidity()
    {
        $exchange = $this->exchange();

        $validTo = gmdate('Y-m-d\TH:i:s\Z', time() + 86400);
        $captured = [];
        $this->curl->expects($this->once())
            ->method('post')
            ->with(
                TaxcloudConfig::DEFAULT_REST_AUTH_ENDPOINT . '/api/v3/auth/token',
                $this->callback(function ($body) use (&$captured) {
                    $captured = json_decode($body, true);
                    return true;
                })
            );
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode([
            'access_token' => 'jwt-abc',
            'access_token_validTo' => $validTo,
            'token_type' => 'Bearer',
        ]));

        $token = $exchange->exchange(self::API_ID, self::API_KEY);

        $this->assertSame(['apiLoginID' => self::API_ID, 'apiKey' => self::API_KEY], $captured);
        $this->assertSame('jwt-abc', $token->getToken());
        $this->assertSame(strtotime($validTo), $token->getValidTo());
    }

    public function testConfiguredAuthEndpointIsResolvedForThePassedStore()
    {
        $exchange = $this->exchange([
            [TaxcloudConfig::XML_PATH_REST_AUTH_ENDPOINT, ScopeInterface::SCOPE_STORE, 7,
                'https://staging-auth.example/'],
        ]);

        $this->curl->expects($this->once())
            ->method('post')
            ->with('https://staging-auth.example/api/v3/auth/token', $this->anything());
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['access_token' => 'jwt']));

        $exchange->exchange(self::API_ID, self::API_KEY, 7);
    }

    public function testRejectedPairThrowsRejectedWithoutCredentialValues()
    {
        $exchange = $this->exchange();
        $this->curl->method('getStatus')->willReturn(400);
        $this->curl->method('getBody')->willReturn('{"message":"bad credentials"}');

        try {
            $exchange->exchange(self::API_ID, self::API_KEY);
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $e) {
            $this->assertTrue($e->isRejected());
            $this->assertStringNotContainsString(self::API_ID, $e->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    public function testNetworkFailureThrowsUnreachableScrubbedOfCredentials()
    {
        $exchange = $this->exchange();
        $this->curl->method('post')->willThrowException(
            new \Exception('could not connect sending ' . self::API_ID . '/' . self::API_KEY)
        );

        try {
            $exchange->exchange(self::API_ID, self::API_KEY);
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $e) {
            $this->assertSame(TokenExchangeException::UNREACHABLE, $e->getOutcome());
            $this->assertStringNotContainsString(self::API_ID, $e->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
    }

    public function testServerErrorAndMissingTokenAreUnreachable()
    {
        $exchange = $this->exchange();
        $this->curl->method('getStatus')->willReturn(500);
        $this->curl->method('getBody')->willReturn('oops');

        try {
            $exchange->exchange(self::API_ID, self::API_KEY);
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $e) {
            $this->assertSame(TokenExchangeException::UNREACHABLE, $e->getOutcome());
        }

        $exchange = $this->exchange();
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['token_type' => 'Bearer']));

        try {
            $exchange->exchange(self::API_ID, self::API_KEY);
            $this->fail('Expected TokenExchangeException');
        } catch (TokenExchangeException $e) {
            $this->assertSame(TokenExchangeException::UNREACHABLE, $e->getOutcome());
        }
    }

    /**
     * A 2xx with no usable expiry must still yield a briefly-valid token so
     * the current request can proceed.
     */
    public function testMissingValidToYieldsShortLivedToken()
    {
        $exchange = $this->exchange();
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(json_encode(['access_token' => 'jwt']));

        $token = $exchange->exchange(self::API_ID, self::API_KEY);

        $this->assertGreaterThan(time(), $token->getValidTo());
        $this->assertLessThanOrEqual(time() + 61, $token->getValidTo());
    }
}
