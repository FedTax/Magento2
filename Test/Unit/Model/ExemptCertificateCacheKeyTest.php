<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @package    Taxcloud_Magento2
 * @author     TaxCloud <service@taxcloud.net>
 * @copyright  2021 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Taxcloud\Magento2\Model\Api;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Webapi\Soap\ClientFactory;
use Magento\Framework\DataObjectFactory;
use Magento\Catalog\Model\ProductFactory;
use Magento\Directory\Model\RegionFactory;
use Taxcloud\Magento2\Logger\Logger;
use Magento\Framework\Serialize\SerializerInterface;
use Taxcloud\Magento2\Model\CartItemResponseHandler;
use Taxcloud\Magento2\Model\ProductTicService;
use Taxcloud\Magento2\Model\RefundDistributor;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Taxcloud\Magento2\Test\Unit\Double\SoapClientDouble;

/**
 * Locks in the security property that the exempt-states cache for an
 * exemption certificate is scoped per (customer, certificate), so a
 * customer who learns another customer's certificate UUID and pastes it
 * into their own profile cannot inherit the original holder's cached
 * state list.
 */
#[AllowMockObjectsWithoutExpectations]
class ExemptCertificateCacheKeyTest extends TestCase
{
    private const CERT_ID    = '11111111-2222-3333-4444-555555555555';
    private const STATE      = 'NY';

    private $cacheType;

    public function testDifferentCustomersWithSameCertificateProduceDifferentCacheKeys()
    {
        // Record every (key, payload, lifetime) that the SUT writes to cache,
        // so we can compare the keys used for two different customers.
        $writtenKeys = [];

        $this->cacheType = $this->createMock(CacheInterface::class);
        $this->cacheType->method('load')->willReturn(false);
        $this->cacheType->method('save')
            ->willReturnCallback(function ($data, $key) use (&$writtenKeys) {
                $writtenKeys[] = $key;
                return true;
            });

        $api = $this->buildApiWithMockedSoapClient(self::CERT_ID, ['NY']);

        // Customer A — the rightful holder of the certificate.
        $resultA = $api->getValidatedCertificateID(self::CERT_ID, 'customer_A', self::STATE);
        // Customer B — pasted the same certificate UUID into their own profile.
        $resultB = $api->getValidatedCertificateID(self::CERT_ID, 'customer_B', self::STATE);

        // Both reach SOAP because cache always misses in this test — but the
        // keys they wrote under must differ. If the bug regresses they would
        // be identical.
        $this->assertCount(2, $writtenKeys, 'expected one cache write per customer');
        $this->assertNotSame(
            $writtenKeys[0],
            $writtenKeys[1],
            'cache key must include the customer ID to prevent cross-customer reuse'
        );

        // Sanity: each key must actually carry the customer ID it belongs to.
        $this->assertStringContainsString('customer_A', $writtenKeys[0]);
        $this->assertStringContainsString('customer_B', $writtenKeys[1]);

        // And the mocked SOAP response said NY is exempt for this cert, so
        // both customers do get the certificate ID back. The point of the
        // test is not whether the result matches — it's that the *cache
        // slot* is per-customer.
        $this->assertSame(self::CERT_ID, $resultA);
        $this->assertSame(self::CERT_ID, $resultB);
    }

    public function testSameCustomerSameCertificateReusesCachedResultWithoutSoapCall()
    {
        $storedPayload = null;
        $storedKey     = null;

        $this->cacheType = $this->createMock(CacheInterface::class);
        $this->cacheType->method('load')
            ->willReturnCallback(function ($key) use (&$storedPayload, &$storedKey) {
                return ($key === $storedKey) ? $storedPayload : false;
            });
        $this->cacheType->method('save')
            ->willReturnCallback(function ($data, $key) use (&$storedPayload, &$storedKey) {
                $storedKey     = $key;
                $storedPayload = $data;
                return true;
            });

        $soapCallCount = 0;
        $api = $this->buildApiWithMockedSoapClient(
            self::CERT_ID,
            ['NY'],
            function () use (&$soapCallCount) {
                $soapCallCount++;
            }
        );

        // First call: cache miss, populates.
        $api->getValidatedCertificateID(self::CERT_ID, 'customer_A', self::STATE);
        // Second call: cache hit, must NOT touch SOAP.
        $api->getValidatedCertificateID(self::CERT_ID, 'customer_A', self::STATE);

        $this->assertSame(1, $soapCallCount, 'second lookup for same (customer, cert) must use cache');
    }

    public function testEmptyCustomerIdShortCircuitsWithoutCacheOrSoapAccess()
    {
        // Guest path (no logged-in customer): the call-site at
        // Api.php:584 guards with `if ($customer)`, but the method itself
        // also short-circuits on empty inputs. This test pins both, so a
        // future refactor that drops one of those guards still won't
        // produce a cache key without a customer scope.
        $this->cacheType = $this->createMock(CacheInterface::class);
        $this->cacheType->expects($this->never())->method('load');
        $this->cacheType->expects($this->never())->method('save');

        $soapCallCount = 0;
        $api = $this->buildApiWithMockedSoapClient(
            self::CERT_ID,
            ['NY'],
            function () use (&$soapCallCount) {
                $soapCallCount++;
            }
        );

        $this->assertNull($api->getValidatedCertificateID(self::CERT_ID, '', self::STATE));
        $this->assertNull($api->getValidatedCertificateID(self::CERT_ID, null, self::STATE));
        $this->assertSame(0, $soapCallCount, 'empty customer must never reach SOAP');
    }

    /**
     * Wire an Api instance with a mocked SoapClient whose GetExemptCertificates
     * returns a single-cert response naming the supplied exempt states for
     * the supplied certificate ID. The optional $onSoapCall hook lets a test
     * count invocations to assert cache-hit behavior.
     */
    private function buildApiWithMockedSoapClient(
        string $certificateId,
        array $exemptStates,
        ?\Closure $onSoapCall = null
    ): Api {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
            ]);

        // Shape a response that extractExemptStatesFromResponse() will parse
        // into the desired state list for our certificate ID.
        $response = new \stdClass();
        $response->GetExemptCertificatesResult = (object)[
            'ResponseType'      => 'OK',
            'ExemptCertificates' => (object)[
                'ExemptionCertificate' => (object)[
                    'CertificateID' => $certificateId,
                    'Detail'        => (object)[
                        'ExemptStates' => (object)[
                            'ExemptState' => array_map(
                                static fn (string $abbr) => (object)['StateAbbr' => $abbr],
                                $exemptStates
                            ),
                        ],
                    ],
                ],
            ],
        ];

        $client = $this->getMockBuilder(SoapClientDouble::class)
            ->onlyMethods(['GetExemptCertificates'])
            ->getMock();
        $client->method('GetExemptCertificates')
            ->willReturnCallback(function () use ($response, $onSoapCall) {
                if ($onSoapCall) {
                    $onSoapCall();
                }
                return $response;
            });

        // getClient() builds its SoapClient via the injected factory — hand it ours.
        $soapClientFactory = $this->createMock(ClientFactory::class);
        $soapClientFactory->method('create')->willReturn($client);

        return new Api(
            $scopeConfig,
            $this->cacheType,
            $this->createMock(ManagerInterface::class),
            $soapClientFactory,
            $this->createMock(DataObjectFactory::class),
            $this->createMock(ProductFactory::class),
            $this->createMock(RegionFactory::class),
            $this->createMock(Logger::class),
            $this->createMock(SerializerInterface::class),
            $this->createMock(CartItemResponseHandler::class),
            $this->createMock(ProductTicService::class),
            $this->createMock(TaxCalculationInterface::class),
            $this->createMock(QuoteDetailsInterfaceFactory::class),
            $this->createMock(QuoteDetailsItemInterfaceFactory::class),
            $this->createMock(TaxClassKeyInterfaceFactory::class),
            $this->createMock(AddressInterfaceFactory::class),
            $this->createMock(RegionInterfaceFactory::class),
            $this->createMock(RefundDistributor::class)
        );
    }
}
