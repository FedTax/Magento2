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
use Magento\Framework\DataObject;
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
 * Covers Api::verifyAddress across success, SOAP-fault, non-OK response,
 * and no-SoapClient paths. Prior to this test, the method was uncovered,
 * which is how the `return $result;` undefined-variable bug at the
 * no-client and SOAP-retry-failure branches went unnoticed.
 */
#[AllowMockObjectsWithoutExpectations]
class VerifyAddressTest extends TestCase
{
    private $scopeConfig;
    private $cacheType;
    private $eventManager;
    private $soapClientFactory;
    private $objectFactory;
    private $productFactory;
    private $regionFactory;
    private $logger;
    private $serializer;
    private $cartItemResponseHandler;
    private $productTicService;
    private $taxCalculationService;
    private $quoteDetailsFactory;
    private $quoteDetailsItemFactory;
    private $taxClassKeyFactory;
    private $customerAddressFactory;
    private $customerAddressRegionFactory;
    private $refundDistributor;
    private $mockSoapClient;
    private $mockDataObject;

    private const ADDRESS = [
        'Address1' => '1 Infinite Loop',
        'Address2' => '',
        'City'     => 'Cupertino',
        'State'    => 'CA',
        'Zip5'     => '95014',
        'Zip4'     => '',
    ];

    protected function setUp(): void
    {
        $this->scopeConfig                  = $this->createMock(ScopeConfigInterface::class);
        $this->cacheType                    = $this->createMock(CacheInterface::class);
        $this->eventManager                 = $this->createMock(ManagerInterface::class);
        $this->soapClientFactory            = $this->createMock(ClientFactory::class);
        $this->objectFactory                = $this->createMock(DataObjectFactory::class);
        $this->productFactory               = $this->createMock(ProductFactory::class);
        $this->regionFactory                = $this->createMock(RegionFactory::class);
        $this->logger                       = $this->createMock(Logger::class);
        $this->serializer                   = $this->createMock(SerializerInterface::class);
        $this->cartItemResponseHandler      = $this->createMock(CartItemResponseHandler::class);
        $this->productTicService            = $this->createMock(ProductTicService::class);
        $this->taxCalculationService        = $this->createMock(TaxCalculationInterface::class);
        $this->quoteDetailsFactory          = $this->createMock(QuoteDetailsInterfaceFactory::class);
        $this->quoteDetailsItemFactory      = $this->createMock(QuoteDetailsItemInterfaceFactory::class);
        $this->taxClassKeyFactory           = $this->createMock(TaxClassKeyInterfaceFactory::class);
        $this->customerAddressFactory       = $this->createMock(AddressInterfaceFactory::class);
        $this->customerAddressRegionFactory = $this->createMock(RegionInterfaceFactory::class);
        $this->refundDistributor            = $this->createMock(RefundDistributor::class);

        // Default scopeConfig: API creds present, cache off, address verification on.
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/api_id', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_id'],
                ['tax/taxcloud_settings/api_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 'test_api_key'],
                ['tax/taxcloud_settings/cache_lifetime', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, 0],
            ]);

        // Cache miss for every load
        $this->cacheType->method('load')->willReturn(false);

        $this->mockSoapClient = $this->getMockBuilder(SoapClientDouble::class)
            ->onlyMethods(['verifyAddress'])
            ->getMock();
        // getClient() builds its SoapClient via the injected factory — hand it ours.
        $this->soapClientFactory->method('create')->willReturn($this->mockSoapClient);

        // Real DataObject for the before/after event handoff: the SUT calls
        // setParams()/getParams() and setResult()/getResult() on it, and a real
        // DataObject round-trips those through its data array — no stubbing needed.
        $this->mockDataObject = new DataObject();
        $this->objectFactory->method('create')->willReturn($this->mockDataObject);
    }

    public function testVerifyAddressReturnsVerifiedArrayOnSuccess()
    {
        $api = $this->newApi();

        $soapResponse = new \stdClass();
        $soapResponse->VerifyAddressResult = (object)[
            'Address1'       => '1 INFINITE LOOP',
            'Address2'       => '',
            'City'           => 'CUPERTINO',
            'State'          => 'CA',
            'Zip5'           => '95014',
            'Zip4'           => '2084',
            'ErrNumber'      => 0,
            'ErrDescription' => '',
        ];
        $this->mockSoapClient->method('verifyAddress')->willReturn($soapResponse);

        $result = $api->verifyAddress(self::ADDRESS);

        $this->assertIsArray($result);
        $this->assertSame('1 INFINITE LOOP', $result['Address1']);
        $this->assertSame('CUPERTINO', $result['City']);
        $this->assertSame('CA', $result['State']);
        $this->assertSame('95014', $result['Zip5']);
        $this->assertSame('2084', $result['Zip4']);
    }

    public function testVerifyAddressReturnsFalseWhenSoapFaultsOnBothAttempts()
    {
        $api = $this->newApi();

        $this->mockSoapClient->method('verifyAddress')
            ->willThrowException(new \RuntimeException('soap fault'));

        $previousErrorLevel = error_reporting();
        // Make any "Undefined variable" notice a hard failure for this test.
        // Catches a regression of `return $result;` in the SOAP-retry path.
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING | E_NOTICE);

        try {
            $result = $api->verifyAddress(self::ADDRESS);
        } finally {
            restore_error_handler();
            error_reporting($previousErrorLevel);
        }

        $this->assertFalse($result, 'SOAP-fault path must return explicit false (was undefined $result → null)');
    }

    public function testVerifyAddressReturnsFalseOnNonOkResponse()
    {
        $api = $this->newApi();

        $soapResponse = new \stdClass();
        $soapResponse->VerifyAddressResult = (object)[
            'ErrNumber'      => 99,
            'ErrDescription' => 'Address not found',
        ];
        $this->mockSoapClient->method('verifyAddress')->willReturn($soapResponse);

        $result = $api->verifyAddress([
            'Address1' => '12345 Nowhere Rd',
            'Address2' => '',
            'City'     => '?',
            'State'    => 'ZZ',
            'Zip5'     => '00000',
            'Zip4'     => '',
        ]);

        $this->assertFalse($result);
    }

    public function testVerifyAddressReturnsFalseWhenSoapClientUnavailable()
    {
        // Drop in a subclass whose getClient() returns null without ever
        // touching the network. Mirrors what production sees when the
        // TaxCloud WSDL fetch in getClient() fails.
        $api = new class (
            $this->scopeConfig,
            $this->cacheType,
            $this->eventManager,
            $this->soapClientFactory,
            $this->objectFactory,
            $this->productFactory,
            $this->regionFactory,
            $this->logger,
            $this->serializer,
            $this->cartItemResponseHandler,
            $this->productTicService,
            $this->taxCalculationService,
            $this->quoteDetailsFactory,
            $this->quoteDetailsItemFactory,
            $this->taxClassKeyFactory,
            $this->customerAddressFactory,
            $this->customerAddressRegionFactory,
            $this->refundDistributor
        ) extends Api {
            public function getClient()
            {
                return null;
            }
        };

        $previousErrorLevel = error_reporting();
        // Same guard as the SOAP-fault test: any undefined-variable warning
        // from the no-client branch becomes a hard failure.
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        }, E_WARNING | E_NOTICE);

        try {
            $result = $api->verifyAddress(self::ADDRESS);
        } finally {
            restore_error_handler();
            error_reporting($previousErrorLevel);
        }

        $this->assertFalse($result, 'No-SoapClient path must return explicit false (was undefined $result → null)');
    }

    private function newApi(): Api
    {
        return new Api(
            $this->scopeConfig,
            $this->cacheType,
            $this->eventManager,
            $this->soapClientFactory,
            $this->objectFactory,
            $this->productFactory,
            $this->regionFactory,
            $this->logger,
            $this->serializer,
            $this->cartItemResponseHandler,
            $this->productTicService,
            $this->taxCalculationService,
            $this->quoteDetailsFactory,
            $this->quoteDetailsItemFactory,
            $this->taxClassKeyFactory,
            $this->customerAddressFactory,
            $this->customerAddressRegionFactory,
            $this->refundDistributor
        );
    }
}
