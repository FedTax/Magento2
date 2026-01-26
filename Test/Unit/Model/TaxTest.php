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

// Load Magento mocks before any other includes
require_once __DIR__ . '/../Mocks/MagentoMocks.php';

// Load the Tax class directly
require_once __DIR__ . '/../../../Model/Tax.php';

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Tax;
use Taxcloud\Magento2\Model\Api as TaxCloudApi;
use Magento\Tax\Model\Config;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Tax\Helper\Data;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Taxcloud\Magento2\Logger\Logger;
use Magento\Quote\Model\Quote;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Catalog\Model\Product;

class TaxTest extends TestCase
{
    private $tax;
    private $scopeConfig;
    private $taxConfig;
    private $taxCalculationService;
    private $quoteDetailsFactory;
    private $quoteDetailsItemFactory;
    private $taxClassKeyFactory;
    private $customerAddressFactory;
    private $customerAddressRegionFactory;
    private $taxData;
    private $serializer;
    private $tcapi;
    private $tclogger;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->taxConfig = $this->createMock(Config::class);
        $this->taxCalculationService = $this->createMock(TaxCalculationInterface::class);
        $this->quoteDetailsFactory = $this->createMock(QuoteDetailsInterfaceFactory::class);
        $this->quoteDetailsItemFactory = $this->createMock(QuoteDetailsItemInterfaceFactory::class);
        $this->taxClassKeyFactory = $this->createMock(TaxClassKeyInterfaceFactory::class);
        $this->customerAddressFactory = $this->createMock(AddressInterfaceFactory::class);
        $this->customerAddressRegionFactory = $this->createMock(RegionInterfaceFactory::class);
        $this->taxData = $this->createMock(Data::class);
        $this->serializer = $this->createMock(Json::class);
        $this->tcapi = $this->createMock(TaxCloudApi::class);
        $this->tclogger = $this->createMock(Logger::class);

        $this->createTaxInstance();
    }

    /**
     * Helper: Create Tax instance (can be recreated if needed)
     */
    private function createTaxInstance()
    {
        // Create Tax instance with disabled constructor to avoid parent class issues
        $this->tax = $this->getMockBuilder(Tax::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'clearValues',
                'getQuoteTaxDetails',
                'organizeItemTaxDetailsByType',
                'processProductItems',
                'processShippingTaxInfo',
                'processExtraTaxables',
                'processAppliedTaxes',
                'includeExtraTax'
            ])
            ->getMock();
        
        // Manually set the properties we need using reflection
        $reflection = new \ReflectionClass($this->tax);
        
        $scopeConfigProperty = $reflection->getProperty('scopeConfig');
        $scopeConfigProperty->setAccessible(true);
        $scopeConfigProperty->setValue($this->tax, $this->scopeConfig);
        
        $tcapiProperty = $reflection->getProperty('tcapi');
        $tcapiProperty->setAccessible(true);
        $tcapiProperty->setValue($this->tax, $this->tcapi);
        
        $tcloggerProperty = $reflection->getProperty('tclogger');
        $tcloggerProperty->setAccessible(true);
        $tcloggerProperty->setValue($this->tax, $this->tclogger);
    }

    /**
     * Helper: Configure TaxCloud as enabled
     */
    private function configureTaxCloudEnabled($logging = false)
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, $logging ? '1' : '0']
            ]);
        
        // Set logger based on logging setting
        // In real code, Tax constructor checks config, but we bypass that with disabled constructor
        $reflection = new \ReflectionClass($this->tax);
        $tcloggerProperty = $reflection->getProperty('tclogger');
        $tcloggerProperty->setAccessible(true);
        // Always use mock logger so we can verify calls in tests
        $tcloggerProperty->setValue($this->tax, $this->tclogger);
    }

    /**
     * Helper: Create a mock quote
     */
    private function createMockQuote($customerTaxClassId = '3', $storeId = 1)
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getCustomerTaxClassId')->willReturn($customerTaxClassId);
        $quote->method('getStoreId')->willReturn($storeId);
        return $quote;
    }

    /**
     * Helper: Create a mock quote item
     */
    private function createMockQuoteItem($itemId = 'item-1', $qty = 1, $price = 50.00, $rowTotal = null, $taxClassId = '2')
    {
        $rowTotal = $rowTotal ?? ($price * $qty);
        
        $quoteItem = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTaxCalculationItemId', 'getProduct', 'getQty', 'getPrice', 'getBasePrice', 'getRowTotal', 'getBaseRowTotal', 'setTaxAmount', 'setBaseTaxAmount', 'setTaxPercent', 'setPriceInclTax', 'setBasePriceInclTax', 'setRowTotalInclTax', 'setBaseRowTotalInclTax'])
            ->getMock();
        
        $product = $this->createMock(Product::class);
        $product->method('getTaxClassId')->willReturn($taxClassId);
        
        $quoteItem->method('getTaxCalculationItemId')->willReturn($itemId);
        $quoteItem->method('getProduct')->willReturn($product);
        $quoteItem->method('getQty')->willReturn($qty);
        $quoteItem->method('getPrice')->willReturn($price);
        $quoteItem->method('getBasePrice')->willReturn($price);
        $quoteItem->method('getRowTotal')->willReturn($rowTotal);
        $quoteItem->method('getBaseRowTotal')->willReturn($rowTotal);
        
        return $quoteItem;
    }

    /**
     * Helper: Create mock tax detail objects
     */
    private function createMockTaxDetails($price = 50.00, $rowTotal = 100.00)
    {
        $taxDetail = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getPrice', 'getRowTotal', 'setRowTax', 'setPriceInclTax', 'setRowTotalInclTax', 'setAppliedTaxes', 'setTaxPercent', 'getRowTax'])
            ->getMock();
        $taxDetail->method('getPrice')->willReturn($price);
        $taxDetail->method('getRowTotal')->willReturn($rowTotal);
        
        $baseTaxDetail = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getPrice', 'getRowTotal', 'setRowTax', 'setPriceInclTax', 'setRowTotalInclTax', 'setAppliedTaxes', 'setTaxPercent', 'getRowTax'])
            ->getMock();
        $baseTaxDetail->method('getPrice')->willReturn($price);
        $baseTaxDetail->method('getRowTotal')->willReturn($rowTotal);
        
        return [$taxDetail, $baseTaxDetail];
    }

    /**
     * Helper: Setup parent class method mocks
     */
    private function setupParentMethodMocks($itemsByType = [])
    {
        $this->tax->method('clearValues')->willReturnSelf();
        $this->tax->method('getQuoteTaxDetails')->willReturn(null);
        $this->tax->method('organizeItemTaxDetailsByType')->willReturn($itemsByType);
        $this->tax->method('processProductItems')->willReturnSelf();
        $this->tax->method('processShippingTaxInfo')->willReturnSelf();
        $this->tax->method('processExtraTaxables')->willReturnSelf();
        $this->tax->method('processAppliedTaxes')->willReturnSelf();
        $this->tax->method('includeExtraTax')->willReturn(false);
    }

    /**
     * Helper: Create itemsByType structure for product items
     */
    private function createItemsByTypeForProduct($itemId, $taxDetail, $baseTaxDetail)
    {
        return [
            Tax::ITEM_TYPE_PRODUCT => [
                $itemId => [
                    Tax::KEY_ITEM => $taxDetail,
                    Tax::KEY_BASE_ITEM => $baseTaxDetail
                ]
            ]
        ];
    }

    /**
     * Helper: Setup TaxCloud API mock
     */
    private function setupTaxCloudApiMock($productTax = [], $shippingTax = 0)
    {
        $taxAmounts = [
            Tax::ITEM_TYPE_PRODUCT => $productTax,
            Tax::ITEM_TYPE_SHIPPING => $shippingTax
        ];
        $this->tcapi->method('lookupTaxes')->willReturn($taxAmounts);
    }

    /**
     * Helper: Create a complete test scenario
     */
    private function createTestScenario($productTaxAmount = 5.00, $shippingTaxAmount = 2.50, $itemPrice = 50.00, $itemQty = 1, $logging = false)
    {
        $this->configureTaxCloudEnabled($logging);
        
        $quote = $this->createMockQuote();
        $quoteItem = $this->createMockQuoteItem('item-1', $itemQty, $itemPrice);
        
        $shippingAssignment = $this->createMock(ShippingAssignmentInterface::class);
        $shippingAssignment->method('getItems')->willReturn([$quoteItem]);
        
        $total = $this->createMock(Total::class);
        $total->method('getTaxAmount')->willReturn(0);
        $total->method('getBaseTaxAmount')->willReturn(0);
        
        [$taxDetail, $baseTaxDetail] = $this->createMockTaxDetails($itemPrice, $itemPrice * $itemQty);
        $itemsByType = $this->createItemsByTypeForProduct('item-1', $taxDetail, $baseTaxDetail);
        
        $this->setupParentMethodMocks($itemsByType);
        $this->setupTaxCloudApiMock(['item-1' => $productTaxAmount], $shippingTaxAmount);
        
        return [$quote, $shippingAssignment, $total, $quoteItem];
    }

    /**
     * Data provider for product tax persistence tests
     * Format: [productTaxAmount, shippingTaxAmount, itemPrice, itemQty, expectedTaxPercent, expectedPriceInclTax, expectedRowTotalInclTax, description]
     */
    public function productTaxPersistenceDataProvider()
    {
        return [
            'single item with tax' => [
                'productTaxAmount' => 5.00,
                'shippingTaxAmount' => 2.50,
                'itemPrice' => 50.00,
                'itemQty' => 1,
                'expectedTaxPercent' => 10.00, // 5/50 * 100
                'expectedPriceInclTax' => 55.00, // 50 + 5
                'expectedRowTotalInclTax' => 55.00, // 50 + 5
                'description' => 'Single item with $5 tax'
            ],
            'multiple quantity items' => [
                'productTaxAmount' => 5.00,
                'shippingTaxAmount' => 2.50,
                'itemPrice' => 50.00,
                'itemQty' => 2,
                'expectedTaxPercent' => 5.00, // 5/100 * 100
                'expectedPriceInclTax' => 52.50, // 50 + (5/2)
                'expectedRowTotalInclTax' => 105.00, // 100 + 5
                'description' => 'Two items with $5 total tax'
            ],
            'high price item' => [
                'productTaxAmount' => 10.00,
                'shippingTaxAmount' => 0.00,
                'itemPrice' => 100.00,
                'itemQty' => 1,
                'expectedTaxPercent' => 10.00, // 10/100 * 100
                'expectedPriceInclTax' => 110.00, // 100 + 10
                'expectedRowTotalInclTax' => 110.00, // 100 + 10
                'description' => 'High price item with 10% tax'
            ],
            'low tax amount' => [
                'productTaxAmount' => 0.50,
                'shippingTaxAmount' => 0.00,
                'itemPrice' => 10.00,
                'itemQty' => 1,
                'expectedTaxPercent' => 5.00, // 0.5/10 * 100
                'expectedPriceInclTax' => 10.50, // 10 + 0.5
                'expectedRowTotalInclTax' => 10.50, // 10 + 0.5
                'description' => 'Low tax amount on low price item'
            ],
            'zero tax' => [
                'productTaxAmount' => 0.00,
                'shippingTaxAmount' => 0.00,
                'itemPrice' => 50.00,
                'itemQty' => 1,
                'expectedTaxPercent' => 0.00,
                'expectedPriceInclTax' => 50.00, // 50 + 0
                'expectedRowTotalInclTax' => 50.00, // 50 + 0
                'description' => 'Zero tax scenario'
            ],
        ];
    }

    /**
     * Test that product tax is persisted to quote items
     * @dataProvider productTaxPersistenceDataProvider
     */
    public function testProductTaxIsPersistedToQuoteItems(
        $productTaxAmount,
        $shippingTaxAmount,
        $itemPrice,
        $itemQty,
        $expectedTaxPercent,
        $expectedPriceInclTax,
        $expectedRowTotalInclTax,
        $description
    ) {
        [$quote, $shippingAssignment, $total, $quoteItem] = $this->createTestScenario(
            productTaxAmount: $productTaxAmount,
            shippingTaxAmount: $shippingTaxAmount,
            itemPrice: $itemPrice,
            itemQty: $itemQty
        );

        $rowTotal = $itemPrice * $itemQty;
        $expectedRowTotalInclTax = $rowTotal + $productTaxAmount;

        // Expect tax to be set on quote item
        $quoteItem->expects($this->once())
            ->method('setTaxAmount')
            ->with($this->equalTo($productTaxAmount));
        
        $quoteItem->expects($this->once())
            ->method('setBaseTaxAmount')
            ->with($this->equalTo($productTaxAmount));
        
        $quoteItem->expects($this->once())
            ->method('setTaxPercent')
            ->with($this->equalTo($expectedTaxPercent));
        
        $quoteItem->expects($this->once())
            ->method('setPriceInclTax')
            ->with($this->equalTo($expectedPriceInclTax));
        
        $quoteItem->expects($this->once())
            ->method('setBasePriceInclTax')
            ->with($this->equalTo($expectedPriceInclTax));
        
        $quoteItem->expects($this->once())
            ->method('setRowTotalInclTax')
            ->with($this->equalTo($expectedRowTotalInclTax));
        
        $quoteItem->expects($this->once())
            ->method('setBaseRowTotalInclTax')
            ->with($this->equalTo($expectedRowTotalInclTax));

        // Call collect
        $result = $this->tax->collect($quote, $shippingAssignment, $total);

        $this->assertSame($this->tax, $result, "Failed for: $description");
    }

    /**
     * Test defensive safeguard adds product tax when missing from totals
     */
    public function testDefensiveSafeguardAddsProductTaxToTotals()
    {
        $this->configureTaxCloudEnabled(logging: true);
        
        $quote = $this->createMockQuote();
        $quoteItem = $this->createMockQuoteItem('item-1', 1, 50.00);
        
        $shippingAssignment = $this->createMock(ShippingAssignmentInterface::class);
        $shippingAssignment->method('getItems')->willReturn([$quoteItem]);
        
        // Create mock total - simulate that only shipping tax is present AFTER processAppliedTaxes
        // The defensive safeguard checks getTaxAmount() AFTER processAppliedTaxes runs
        $total = $this->createMock(Total::class);
        // getTaxAmount() is called once in defensive safeguard, should return 2.50 (only shipping tax)
        $total->method('getTaxAmount')->willReturn(2.50);
        $total->method('getBaseTaxAmount')->willReturn(2.50);
        
        [$taxDetail, $baseTaxDetail] = $this->createMockTaxDetails(50.00, 50.00);
        $itemsByType = $this->createItemsByTypeForProduct('item-1', $taxDetail, $baseTaxDetail);
        
        $this->setupParentMethodMocks($itemsByType);
        $this->setupTaxCloudApiMock(['item-1' => 5.00], 2.50);

        // Expect defensive safeguard to add product tax
        $total->expects($this->once())
            ->method('setTaxAmount')
            ->with($this->equalTo(7.50)); // 2.50 shipping + 5.00 product
        
        $total->expects($this->once())
            ->method('setBaseTaxAmount')
            ->with($this->equalTo(7.50));
        
        $total->expects($this->once())
            ->method('addTotalAmount')
            ->with('tax', 5.00);
        
        $total->expects($this->once())
            ->method('addBaseTotalAmount')
            ->with('tax', 5.00);

        // Mock logger to verify defensive safeguard message
        $this->tclogger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Product tax missing from totals'));

        // Call collect
        $this->tax->collect($quote, $shippingAssignment, $total);
    }

    /**
     * Data provider for edge case tests
     */
    public function edgeCaseDataProvider()
    {
        return [
            'zero quantity' => [
                'productTaxAmount' => 5.00,
                'shippingTaxAmount' => 0.00,
                'itemPrice' => 50.00,
                'itemQty' => 0,
                'expectedTaxAmount' => 0,
                'expectedPriceInclTax' => 50.00,
                'description' => 'Zero quantity should result in zero tax'
            ],
            'tax exempt product' => [
                'productTaxAmount' => 0.00,
                'shippingTaxAmount' => 0.00,
                'itemPrice' => 50.00,
                'itemQty' => 1,
                'expectedTaxAmount' => 0,
                'expectedPriceInclTax' => 50.00,
                'description' => 'Tax exempt product (tax class 0)'
            ],
        ];
    }

    /**
     * Test edge cases that don't cause errors
     * @dataProvider edgeCaseDataProvider
     */
    public function testEdgeCases(
        $productTaxAmount,
        $shippingTaxAmount,
        $itemPrice,
        $itemQty,
        $expectedTaxAmount,
        $expectedPriceInclTax,
        $description
    ) {
        $taxClassId = ($productTaxAmount > 0 && $itemQty > 0) ? '2' : '0';
        
        [$quote, $shippingAssignment, $total, $quoteItem] = $this->createTestScenario(
            productTaxAmount: $productTaxAmount,
            shippingTaxAmount: $shippingTaxAmount,
            itemPrice: $itemPrice,
            itemQty: $itemQty
        );

        // Override tax class if needed
        if ($taxClassId === '0') {
            $product = $quoteItem->getProduct();
            $product->method('getTaxClassId')->willReturn('0');
        }

        // Expect tax to be set correctly
        $quoteItem->expects($this->once())
            ->method('setTaxAmount')
            ->with($this->equalTo($expectedTaxAmount));
        
        $quoteItem->expects($this->once())
            ->method('setBaseTaxAmount')
            ->with($this->equalTo($expectedTaxAmount));
        
        $quoteItem->expects($this->once())
            ->method('setPriceInclTax')
            ->with($this->equalTo($expectedPriceInclTax));
        
        $quoteItem->expects($this->once())
            ->method('setBasePriceInclTax')
            ->with($this->equalTo($expectedPriceInclTax));

        // Should not throw any errors
        $result = $this->tax->collect($quote, $shippingAssignment, $total);
        
        $this->assertSame($this->tax, $result, "Failed for: $description");
    }
}
