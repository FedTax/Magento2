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

// Load the Tax class directly
require_once __DIR__ . '/../../../Model/Tax.php';

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
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
use Taxcloud\Magento2\Test\Unit\Double as Dbl;

#[AllowMockObjectsWithoutExpectations]
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
        // Magento's CommonTaxCollector constructor falls back to
        // ObjectManager::getInstance() for optional dependencies (taxHelper,
        // quoteDetailsItemExtensionFactory, customerAccountManagement). Give it
        // a permissive instance that hands back mocks for whatever it asks for.
        $om = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $om->method('get')->willReturnCallback(fn (string $type) => $this->createMock($type));
        \Magento\Framework\App\ObjectManager::setInstance($om);

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
    }

    /**
     * Helper: Create Tax instance (can be recreated if needed)
     */
    /**
     * Build $this->tax as a partial mock — the Magento parent helper methods are
     * stubbed while the real Tax::collect() and constructor run. The real
     * constructor (driven with mocks via setConstructorArgs) wires scopeConfig,
     * tcapi and tclogger, so no reflection is needed. Call this AFTER scopeConfig
     * is configured, since the constructor reads the logging flag from it.
     */
    private function createTaxInstance()
    {
        $this->tax = $this->getMockBuilder(Tax::class)
            ->setConstructorArgs([
                $this->taxConfig,
                $this->taxCalculationService,
                $this->quoteDetailsFactory,
                $this->quoteDetailsItemFactory,
                $this->taxClassKeyFactory,
                $this->customerAddressFactory,
                $this->customerAddressRegionFactory,
                $this->taxData,
                $this->scopeConfig,
                $this->tcapi,
                $this->tclogger,
                $this->serializer,
            ])
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
    }

    /**
     * Helper: Configure TaxCloud as enabled, then build the Tax instance so the
     * constructor sees the right logging flag (logging=1 wires the real logger).
     */
    private function configureTaxCloudEnabled($logging = false)
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, $logging ? '1' : '0']
            ]);

        $this->createTaxInstance();
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
        
        $quoteItem = $this->getMockBuilder(Dbl\QuoteItemDouble::class)
            ->onlyMethods(['getPrice', 'getProduct', 'getQty', 'getBasePrice', 'getBaseRowTotal', 'getRowTotal', 'getTaxCalculationItemId', 'setBasePriceInclTax', 'setBaseRowTotalInclTax', 'setBaseTaxAmount', 'setPriceInclTax', 'setRowTotalInclTax', 'setTaxAmount', 'setTaxPercent'])
            ->getMock();

        $product = $this->getMockBuilder(Dbl\ProductDouble::class)
            ->onlyMethods(['getTaxClassId'])
            ->getMock();
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
        $taxDetail = $this->getMockBuilder(Dbl\TaxDetailDouble::class)
            ->onlyMethods(['getPrice', 'getRowTax', 'getRowTotal', 'setAppliedTaxes', 'setPriceInclTax', 'setRowTax', 'setRowTotalInclTax', 'setTaxPercent'])
            ->getMock();
        $taxDetail->method('getPrice')->willReturn($price);
        $taxDetail->method('getRowTotal')->willReturn($rowTotal);

        $baseTaxDetail = $this->getMockBuilder(Dbl\TaxDetailDouble::class)
            ->onlyMethods(['getPrice', 'getRowTax', 'getRowTotal', 'setAppliedTaxes', 'setPriceInclTax', 'setRowTax', 'setRowTotalInclTax', 'setTaxPercent'])
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
        // organizeItemTaxDetailsByType() type-hints TaxDetailsInterface, and mocked
        // methods keep the real signature — null is rejected. Return a real mock.
        $this->tax->method('getQuoteTaxDetails')
            ->willReturn($this->createMock(\Magento\Tax\Api\Data\TaxDetailsInterface::class));
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
        
        $total = $this->getMockBuilder(Dbl\TotalDouble::class)
            ->onlyMethods(['getBaseTaxAmount', 'getTaxAmount'])
            ->getMock();
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
    public static function productTaxPersistenceDataProvider()
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
    #[DataProvider('productTaxPersistenceDataProvider')]
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
        $total = $this->getMockBuilder(Dbl\TotalDouble::class)
            ->onlyMethods(['addBaseTotalAmount', 'addTotalAmount', 'getBaseTaxAmount', 'getTaxAmount', 'setBaseTaxAmount', 'setTaxAmount'])
            ->getMock();
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
     * TC-011 end-to-end: the defensive safeguard exercised against a representative
     * multi-item quote using a REAL accumulating Total instead of a mock with a
     * canned getTaxAmount(). This proves the safeguard's arithmetic
     * ($currentTaxTotal + $productTaxTotal, summed across items) against actual
     * accumulated total state, not just that the right setters were invoked.
     *
     * Scenario mirrors production order #2000543282: Magento kept only shipping
     * tax in the totals; product tax from two line items was dropped and must be
     * added back.
     */
    public function testDefensiveSafeguardRestoresDroppedProductTaxAcrossRepresentativeQuote()
    {
        $this->configureTaxCloudEnabled(logging: true);

        // Two realistic line items: a $12.50 mug (qty 1) and a $24.00 shirt (qty 2).
        $mug   = $this->createMockQuoteItem('sku-mug', 1, 12.50, 12.50);
        $shirt = $this->createMockQuoteItem('sku-shirt', 2, 24.00, 48.00);

        $shippingAssignment = $this->createMock(ShippingAssignmentInterface::class);
        $shippingAssignment->method('getItems')->willReturn([$mug, $shirt]);

        // Per-item TaxCloud verdicts + a shipping tax. Product tax total = 4.99.
        $mugProductTax   = 1.03;
        $shirtProductTax = 3.96;
        $shippingTax     = 0.62;
        $productTaxTotal = $mugProductTax + $shirtProductTax; // 4.99

        [$mugDetail, $mugBaseDetail]     = $this->createMockTaxDetails(12.50, 12.50);
        [$shirtDetail, $shirtBaseDetail] = $this->createMockTaxDetails(24.00, 48.00);
        $itemsByType = [
            Tax::ITEM_TYPE_PRODUCT => [
                'sku-mug'   => [Tax::KEY_ITEM => $mugDetail,   Tax::KEY_BASE_ITEM => $mugBaseDetail],
                'sku-shirt' => [Tax::KEY_ITEM => $shirtDetail, Tax::KEY_BASE_ITEM => $shirtBaseDetail],
            ],
        ];

        $this->setupParentMethodMocks($itemsByType);
        $this->setupTaxCloudApiMock(
            ['sku-mug' => $mugProductTax, 'sku-shirt' => $shirtProductTax],
            $shippingTax
        );

        // A real accumulating Total: getTaxAmount()/setTaxAmount() and addTotalAmount()
        // reflect actual state rather than canned returns. Seed it with shipping tax
        // only — the hostile precondition where product tax was dropped from totals.
        $total = new class ($shippingTax) extends Total {
            private float $tax;
            private float $baseTax;
            public array $added = [];
            public array $addedBase = [];
            public function __construct(float $seedTax)
            {
                $this->tax = $seedTax;
                $this->baseTax = $seedTax;
            }
            public function getTaxAmount()
            {
                return $this->tax;
            }
            public function getBaseTaxAmount()
            {
                return $this->baseTax;
            }
            public function setTaxAmount($amount)
            {
                $this->tax = (float) $amount;
                return $this;
            }
            public function setBaseTaxAmount($amount)
            {
                $this->baseTax = (float) $amount;
                return $this;
            }
            public function addTotalAmount($code, $amount)
            {
                $this->added[$code] = ($this->added[$code] ?? 0) + $amount;
                return $this;
            }
            public function addBaseTotalAmount($code, $amount)
            {
                $this->addedBase[$code] = ($this->addedBase[$code] ?? 0) + $amount;
                return $this;
            }
            public function getExtraTaxAmount()
            {
                return 0;
            }
            public function getBaseExtraTaxAmount()
            {
                return 0;
            }
        };

        $quote = $this->createMockQuote();

        $this->tax->collect($quote, $shippingAssignment, $total);

        // Safeguard must have restored the two items' dropped product tax on top of
        // the shipping tax that was already present.
        $this->assertEqualsWithDelta(
            $shippingTax + $productTaxTotal,
            $total->getTaxAmount(),
            0.0001,
            'Total tax must equal shipping tax + summed product tax across both items'
        );
        $this->assertEqualsWithDelta(
            $shippingTax + $productTaxTotal,
            $total->getBaseTaxAmount(),
            0.0001,
            'Base total tax must be restored identically'
        );
        $this->assertEqualsWithDelta(
            $productTaxTotal,
            $total->added['tax'] ?? 0,
            0.0001,
            'addTotalAmount(tax, ...) must accumulate exactly the dropped product tax'
        );
        $this->assertEqualsWithDelta(
            $productTaxTotal,
            $total->addedBase['tax'] ?? 0,
            0.0001,
            'addBaseTotalAmount(tax, ...) must accumulate exactly the dropped product tax'
        );
    }

    /**
     * Data provider for edge case tests
     */
    public static function edgeCaseDataProvider()
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
    #[DataProvider('edgeCaseDataProvider')]
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

    /**
     * Section 1.2: Two items with different effective taxability.
     *
     * Item A ($20 clothing — TaxCloud returns $0 tax because clothing is exempt in NY).
     * Item B ($10 general — TaxCloud returns $1.00 tax).
     *
     * The Tax::collect path doesn't know about TICs, but it must faithfully apply whatever
     * per-item amounts lookupTaxes returns. Verifies setTaxAmount / setTaxPercent for both
     * items independently.
     */
    public function testCollectAppliesMixedTicsClothingExemptVsGeneral()
    {
        $this->configureTaxCloudEnabled();

        $quote = $this->createMockQuote();
        $itemA = $this->createMockQuoteItem('item-A', qty: 1, price: 20.00);
        $itemB = $this->createMockQuoteItem('item-B', qty: 1, price: 10.00);

        $shippingAssignment = $this->createMock(ShippingAssignmentInterface::class);
        $shippingAssignment->method('getItems')->willReturn([$itemA, $itemB]);

        $total = $this->getMockBuilder(Dbl\TotalDouble::class)
            ->onlyMethods(['getBaseTaxAmount', 'getTaxAmount'])
            ->getMock();
        $total->method('getTaxAmount')->willReturn(0);
        $total->method('getBaseTaxAmount')->willReturn(0);

        [$taxDetailA, $baseTaxDetailA] = $this->createMockTaxDetails(20.00, 20.00);
        [$taxDetailB, $baseTaxDetailB] = $this->createMockTaxDetails(10.00, 10.00);

        $itemsByType = [
            Tax::ITEM_TYPE_PRODUCT => [
                'item-A' => [Tax::KEY_ITEM => $taxDetailA, Tax::KEY_BASE_ITEM => $baseTaxDetailA],
                'item-B' => [Tax::KEY_ITEM => $taxDetailB, Tax::KEY_BASE_ITEM => $baseTaxDetailB],
            ],
        ];

        $this->setupParentMethodMocks($itemsByType);
        $this->setupTaxCloudApiMock(['item-A' => 0.00, 'item-B' => 1.00], 0);

        // Item A: clothing-exempt, zero tax
        $itemA->expects($this->once())->method('setTaxAmount')->with($this->equalTo(0));
        $itemA->expects($this->once())->method('setBaseTaxAmount')->with($this->equalTo(0));
        $itemA->expects($this->once())->method('setTaxPercent')->with($this->equalTo(0));

        // Item B: general, $1.00 tax on $10.00 row => 10% percent
        $itemB->expects($this->once())->method('setTaxAmount')->with($this->equalTo(1.00));
        $itemB->expects($this->once())->method('setBaseTaxAmount')->with($this->equalTo(1.00));
        $itemB->expects($this->once())->method('setTaxPercent')->with($this->equalTo(10.0));

        $this->tax->collect($quote, $shippingAssignment, $total);
    }

    /**
     * Section 1.4 (DEV-7268 regression).
     *
     * setTaxPercent must preserve 3-decimal precision — never round to 2 decimals.
     * Asserted at the boundary cases that motivated the original ticket.
     *
     * @dataProvider threeDecimalPrecisionProvider
     */
    #[DataProvider('threeDecimalPrecisionProvider')]
    public function testCollectPreservesThreeDecimalPrecisionInTaxPercent(
        float $productTax,
        float $rowTotal,
        float $expectedTaxPercent
    ) {
        $this->configureTaxCloudEnabled();

        $quote = $this->createMockQuote();
        $quoteItem = $this->createMockQuoteItem('item-1', qty: 1, price: $rowTotal);

        $shippingAssignment = $this->createMock(ShippingAssignmentInterface::class);
        $shippingAssignment->method('getItems')->willReturn([$quoteItem]);

        $total = $this->getMockBuilder(Dbl\TotalDouble::class)
            ->onlyMethods(['getBaseTaxAmount', 'getTaxAmount'])
            ->getMock();
        $total->method('getTaxAmount')->willReturn(0);
        $total->method('getBaseTaxAmount')->willReturn(0);

        [$taxDetail, $baseTaxDetail] = $this->createMockTaxDetails($rowTotal, $rowTotal);
        $itemsByType = $this->createItemsByTypeForProduct('item-1', $taxDetail, $baseTaxDetail);

        $this->setupParentMethodMocks($itemsByType);
        $this->setupTaxCloudApiMock(['item-1' => $productTax], 0);

        $quoteItem->expects($this->once())
            ->method('setTaxPercent')
            ->with($this->equalTo($expectedTaxPercent));

        $this->tax->collect($quote, $shippingAssignment, $total);
    }

    public static function threeDecimalPrecisionProvider(): array
    {
        return [
            'tax 4.875 on rowTotal 100 -> 4.875 percent (exact 3rd decimal)' => [4.875, 100.00, 4.875],
            'tax 4.8755 on rowTotal 100 -> 4.876 percent (rounds up at 4th decimal)' => [4.8755, 100.00, 4.876],
        ];
    }

    /**
     * Regression test for the 2x/3x per-line tax bug observed on prod
     * (e.g. order #2000543282: $27.30 line charged $8.03 tax instead of $2.68).
     *
     * The InclTax setters at Tax.php:171-174 compute incl-tax from getPrice() / getRowTotal().
     * If anything between consecutive collect() invocations pushes the incl-tax values back
     * into those pre-tax getters (3rd-party collector, promo recalc, address change),
     * the next collect() will compound. This test forces that hostile precondition and
     * asserts that the setters resist it.
     *
     * Expected: fails on current code, passes after the snapshot-based fix.
     */
    public function testInclTaxSettersAreIdempotentAcrossMultipleCollects()
    {
        $this->configureTaxCloudEnabled();

        $price           = 27.30;
        $tax             = 2.68;
        $expectedInclTax = 29.98; // price + tax — invariant across N collects

        // Stateful mirror of the quote item's mutable fields.
        $currentPrice    = $price;
        $currentRowTotal = $price; // qty = 1
        $lastInclPrice   = null;
        $lastInclRow     = null;

        $product = $this->getMockBuilder(Dbl\ProductDouble::class)
            ->onlyMethods(['getTaxClassId'])
            ->getMock();
        $product->method('getTaxClassId')->willReturn('2');

        // onlyMethods leaves getData()/setData() real on the double, so the
        // snapshot guard (taxcloud_pretax_*) exercises genuine DataObject state.
        $quoteItem = $this->getMockBuilder(Dbl\QuoteItemDouble::class)
            ->onlyMethods(['getPrice', 'getProduct', 'getQty', 'getBasePrice', 'getBaseRowTotal', 'getRowTotal', 'getTaxCalculationItemId', 'setBasePriceInclTax', 'setBaseRowTotalInclTax', 'setBaseTaxAmount', 'setPriceInclTax', 'setRowTotalInclTax', 'setTaxAmount', 'setTaxPercent'])
            ->getMock();

        $quoteItem->method('getTaxCalculationItemId')->willReturn('item-1');
        $quoteItem->method('getProduct')->willReturn($product);
        $quoteItem->method('getQty')->willReturn(1);

        // Pre-tax getters reflect whatever the test has mutated them to.
        $quoteItem->method('getPrice')->willReturnCallback(function () use (&$currentPrice) { return $currentPrice; });
        $quoteItem->method('getBasePrice')->willReturnCallback(function () use (&$currentPrice) { return $currentPrice; });
        $quoteItem->method('getRowTotal')->willReturnCallback(function () use (&$currentRowTotal) { return $currentRowTotal; });
        $quoteItem->method('getBaseRowTotal')->willReturnCallback(function () use (&$currentRowTotal) { return $currentRowTotal; });

        // Capture what the InclTax setters were last called with.
        $quoteItem->method('setPriceInclTax')->willReturnCallback(function ($v) use (&$lastInclPrice) { $lastInclPrice = $v; });
        $quoteItem->method('setBasePriceInclTax')->willReturnCallback(function ($v) use (&$lastInclPrice) { $lastInclPrice = $v; });
        $quoteItem->method('setRowTotalInclTax')->willReturnCallback(function ($v) use (&$lastInclRow) { $lastInclRow = $v; });
        $quoteItem->method('setBaseRowTotalInclTax')->willReturnCallback(function ($v) use (&$lastInclRow) { $lastInclRow = $v; });

        $shippingAssignment = $this->createMock(ShippingAssignmentInterface::class);
        $shippingAssignment->method('getItems')->willReturn([$quoteItem]);

        $total = $this->getMockBuilder(Dbl\TotalDouble::class)
            ->onlyMethods(['getBaseTaxAmount', 'getTaxAmount'])
            ->getMock();
        $total->method('getTaxAmount')->willReturn(0);
        $total->method('getBaseTaxAmount')->willReturn(0);

        [$taxDetail, $baseTaxDetail] = $this->createMockTaxDetails($price, $price);
        $this->setupParentMethodMocks(
            $this->createItemsByTypeForProduct('item-1', $taxDetail, $baseTaxDetail)
        );
        $this->setupTaxCloudApiMock(['item-1' => $tax], 0);

        $quote = $this->createMockQuote();

        // --- Pass 1: clean state. Should produce price + tax. ---
        $this->tax->collect($quote, $shippingAssignment, $total);
        $this->assertEqualsWithDelta(
            $expectedInclTax, $lastInclRow, 0.0001,
            'Baseline: first collect must set RowTotalInclTax = price + tax'
        );

        // --- Hostile precondition: simulate an external mutator (3rd-party collector,
        //     promo recalc, etc.) that pushed InclTax values back into pre-tax fields. ---
        $currentPrice    = $lastInclPrice;
        $currentRowTotal = $lastInclRow;

        // --- Pass 2: under the bug, this compounds to price + 2*tax. ---
        $this->tax->collect($quote, $shippingAssignment, $total);
        $this->assertEqualsWithDelta(
            $expectedInclTax, $lastInclRow, 0.0001,
            sprintf(
                'Idempotency violated: second collect produced %.4f, expected %.4f. ' .
                'InclTax setters must compute from a stable pre-tax snapshot, not from live getters.',
                (float) $lastInclRow, $expectedInclTax
            )
        );

        // --- Pass 3: the 3x symptom reported on order #2000543282. ---
        $currentPrice    = $lastInclPrice;
        $currentRowTotal = $lastInclRow;
        $this->tax->collect($quote, $shippingAssignment, $total);
        $this->assertEqualsWithDelta(
            $expectedInclTax, $lastInclRow, 0.0001,
            'Three collects must not produce the 3x compounding observed on order #2000543282'
        );
    }

    // ─── Coverage section B: Tax.php gaps ─────────────────────────────────────

    /**
     * B1: cover the shipping branch — itemsByType has ITEM_TYPE_SHIPPING and the API
     * returned a non-zero shipping tax. Verifies setRowTax/setTaxPercent on the shipping
     * tax detail.
     */
    public function testCollectAppliesShippingTaxAndPercent()
    {
        $this->configureTaxCloudEnabled();

        $quote = $this->createMockQuote();
        $quoteItem = $this->createMockQuoteItem('item-1', qty: 1, price: 50.00);

        $shippingAssignment = $this->createMock(ShippingAssignmentInterface::class);
        $shippingAssignment->method('getItems')->willReturn([$quoteItem]);

        $total = $this->getMockBuilder(Dbl\TotalDouble::class)
            ->onlyMethods(['getBaseTaxAmount', 'getTaxAmount'])
            ->getMock();
        $total->method('getTaxAmount')->willReturn(0);
        $total->method('getBaseTaxAmount')->willReturn(0);

        [$taxDetail, $baseTaxDetail] = $this->createMockTaxDetails(50.00, 50.00);
        [$shippingTaxDetail, $baseShippingTaxDetail] = $this->createMockTaxDetails(10.00, 10.00);
        // Tax.php:217 reads getRowTax() to compute the percent — mocks don't carry state
        // from setRowTax, so pin the expected value directly.
        $shippingTaxDetail->method('getRowTax')->willReturn(1.50);
        $baseShippingTaxDetail->method('getRowTax')->willReturn(1.50);

        // Both the product entry (so collect's product loop runs) and a shipping entry.
        $itemsByType = [
            Tax::ITEM_TYPE_PRODUCT => [
                'item-1' => [Tax::KEY_ITEM => $taxDetail, Tax::KEY_BASE_ITEM => $baseTaxDetail],
            ],
            Tax::ITEM_TYPE_SHIPPING => [
                Tax::ITEM_CODE_SHIPPING => [
                    Tax::KEY_ITEM => $shippingTaxDetail,
                    Tax::KEY_BASE_ITEM => $baseShippingTaxDetail,
                ],
            ],
        ];

        $this->setupParentMethodMocks($itemsByType);
        $this->setupTaxCloudApiMock(['item-1' => 5.00], 1.50);

        // Shipping tax detail must receive the row tax + 15% (1.50 on rowTotal 10.00).
        $shippingTaxDetail->expects($this->once())->method('setRowTax')->with($this->equalTo(1.50));
        $shippingTaxDetail->expects($this->once())->method('setTaxPercent')->with($this->equalTo(15.0));

        $baseShippingTaxDetail->expects($this->once())->method('setRowTax')->with($this->equalTo(1.50));
        $baseShippingTaxDetail->expects($this->once())->method('setTaxPercent')->with($this->equalTo(15.0));

        $this->tax->collect($quote, $shippingAssignment, $total);
    }

    /**
     * B3: cover the extra-tax branch — includeExtraTax()=true must trigger
     * total->addTotalAmount('extra_tax', ...).
     */
    public function testCollectIncludesExtraTaxWhenFlagged()
    {
        $this->configureTaxCloudEnabled();

        $quote = $this->createMockQuote();
        $quoteItem = $this->createMockQuoteItem('item-1', qty: 1, price: 50.00);
        $shippingAssignment = $this->createMock(ShippingAssignmentInterface::class);
        $shippingAssignment->method('getItems')->willReturn([$quoteItem]);

        $total = $this->getMockBuilder(Dbl\TotalDouble::class)
            ->onlyMethods(['addBaseTotalAmount', 'addTotalAmount', 'getBaseExtraTaxAmount', 'getBaseTaxAmount', 'getExtraTaxAmount', 'getTaxAmount', 'setBaseTaxAmount', 'setTaxAmount'])
            ->getMock();
        // Pre-set getTaxAmount to match (productTax + shippingTax) so the defensive
        // safeguard at Tax.php:252 doesn't also call addTotalAmount('tax', ...).
        $total->method('getTaxAmount')->willReturn(2.50);
        $total->method('getBaseTaxAmount')->willReturn(2.50);
        $total->method('getExtraTaxAmount')->willReturn(0.50);
        $total->method('getBaseExtraTaxAmount')->willReturn(0.50);

        [$taxDetail, $baseTaxDetail] = $this->createMockTaxDetails(50.00, 50.00);
        $itemsByType = $this->createItemsByTypeForProduct('item-1', $taxDetail, $baseTaxDetail);

        // Don't call setupParentMethodMocks — it locks includeExtraTax to false.
        // Replicate it inline with includeExtraTax→true.
        $this->tax->method('clearValues')->willReturnSelf();
        // organizeItemTaxDetailsByType() type-hints TaxDetailsInterface, and mocked
        // methods keep the real signature — null is rejected. Return a real mock.
        $this->tax->method('getQuoteTaxDetails')
            ->willReturn($this->createMock(\Magento\Tax\Api\Data\TaxDetailsInterface::class));
        $this->tax->method('organizeItemTaxDetailsByType')->willReturn($itemsByType);
        $this->tax->method('processProductItems')->willReturnSelf();
        $this->tax->method('processShippingTaxInfo')->willReturnSelf();
        $this->tax->method('processExtraTaxables')->willReturnSelf();
        $this->tax->method('processAppliedTaxes')->willReturnSelf();
        $this->tax->method('includeExtraTax')->willReturn(true);

        $this->setupTaxCloudApiMock(['item-1' => 2.50], 0);

        // Expect both extra_tax totals to be added.
        $total->expects($this->once())->method('addTotalAmount')->with('extra_tax', 0.50);
        $total->expects($this->once())->method('addBaseTotalAmount')->with('extra_tax', 0.50);

        $this->tax->collect($quote, $shippingAssignment, $total);
    }

    /**
     * B4: empty shippingAssignment items — collect must return early without calling
     * the TaxCloud API.
     */
    public function testCollectSkipsPersistWhenShippingAssignmentHasNoItems()
    {
        $this->configureTaxCloudEnabled();

        $quote = $this->createMockQuote();
        $shippingAssignment = $this->createMock(ShippingAssignmentInterface::class);
        $shippingAssignment->method('getItems')->willReturn([]);

        $total = $this->createMock(Total::class);

        // tcapi must never be called when there are no items to consider.
        $this->tcapi->expects($this->never())->method('lookupTaxes');

        // Parent's setup mocks aren't strictly needed (we return early before them) but
        // clearValues runs first — guard it.
        $this->tax->method('clearValues')->willReturnSelf();

        $result = $this->tax->collect($quote, $shippingAssignment, $total);

        $this->assertSame($this->tax, $result);
    }

    /**
     * Section 11.1: enabled=0 — Tax::collect must defer to the Magento parent and never
     * call lookupTaxes. The parent's return value is what Tax::collect returns.
     */
    public function testCollectDefersToParentWhenModuleDisabled()
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
                ['tax/taxcloud_settings/logging', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0'],
            ]);
        // scopeConfig is configured directly here (module disabled), so build Tax now.
        $this->createTaxInstance();

        $quote = $this->createMockQuote();
        $quoteItem = $this->createMockQuoteItem();
        $shippingAssignment = $this->createMock(ShippingAssignmentInterface::class);
        $shippingAssignment->method('getItems')->willReturn([$quoteItem]);
        $total = $this->createMock(Total::class);

        // The parent's real collect() runs in this path; its helpers are mocked
        // but their real type-hints still apply — unstubbed mocks would return
        // null into TaxDetailsInterface / array parameters and fatal.
        $this->tax->method('getQuoteTaxDetails')
            ->willReturn($this->createMock(\Magento\Tax\Api\Data\TaxDetailsInterface::class));
        $this->tax->method('organizeItemTaxDetailsByType')->willReturn([]);

        // tcapi must NOT be called when the module is disabled — Tax::collect returns
        // before reaching its TaxCloud path.
        $this->tcapi->expects($this->never())->method('lookupTaxes');

        $result = $this->tax->collect($quote, $shippingAssignment, $total);

        // The real Magento parent collector (\Magento\Tax\Model\Sales\Total\Quote\Tax,
        // which Tax extends) returns $this from collect(); since $this->tax is a partial
        // mock of Tax, a successful defer yields the Tax instance itself.
        $this->assertSame($this->tax, $result);
    }

    /**
     * Section 1.5: Zero rowTotal must not divide-by-zero — setTaxPercent gets 0.
     *
     * The ternary at Tax.php:170 is the protected branch.
     */
    public function testCollectSetsZeroTaxPercentWhenRowTotalIsZero()
    {
        $this->configureTaxCloudEnabled();

        $quote = $this->createMockQuote();
        // taxClassId='2' (taxable), qty=1 — bypasses the early-zero short-circuit at Tax.php:156
        // and forces the divbyzero-guard branch at Tax.php:170
        $quoteItem = $this->createMockQuoteItem('item-1', qty: 1, price: 0, taxClassId: '2');

        $shippingAssignment = $this->createMock(ShippingAssignmentInterface::class);
        $shippingAssignment->method('getItems')->willReturn([$quoteItem]);

        $total = $this->getMockBuilder(Dbl\TotalDouble::class)
            ->onlyMethods(['getBaseTaxAmount', 'getTaxAmount'])
            ->getMock();
        $total->method('getTaxAmount')->willReturn(0);
        $total->method('getBaseTaxAmount')->willReturn(0);

        [$taxDetail, $baseTaxDetail] = $this->createMockTaxDetails(0, 0);
        $itemsByType = $this->createItemsByTypeForProduct('item-1', $taxDetail, $baseTaxDetail);

        $this->setupParentMethodMocks($itemsByType);
        $this->setupTaxCloudApiMock(['item-1' => 0], 0);

        $quoteItem->expects($this->once())->method('setTaxPercent')->with($this->equalTo(0));

        // No DivisionByZeroError must escape
        $this->tax->collect($quote, $shippingAssignment, $total);
    }
}
