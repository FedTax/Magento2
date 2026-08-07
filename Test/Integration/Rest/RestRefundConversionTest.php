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
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Rest;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\DataObject;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Taxcloud\Magento2\Model\Gateway\Rest\RestRequestBuilder;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Regression coverage for the 2026-08-07 configurable-refund failure, against
 * REAL Magento shapes instead of fabricated fixtures: a configurable credit
 * memo carries both the parent row (priced) and its child row (zero-priced)
 * under the child's SKU, and the v3 refund conversion must collapse them into
 * a single item reference with the parent's quantity — v3 refunds reject
 * duplicate itemIds outright.
 *
 * No TaxCloud API is called: order placement runs against the SOAP mock (the
 * seeded api_type), and the conversion under test is a pure builder.
 */
class RestRefundConversionTest extends IntegrationTestCase
{
    private const CONFIGURABLE_SKU = 'test-configurable';
    private const ATTRIBUTE_CODE = 'test_variant_color';
    private const RED_SKU = 'test-variant-red';

    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
    }

    public function testConfigurableCreditmemoConvertsToUniqueRefundReferences(): void
    {
        // Place a real order for the RED variant of the seeded configurable.
        $attribute = $this->get(EavConfig::class)->getAttribute(Product::ENTITY, self::ATTRIBUTE_CODE);
        $this->assertNotEmpty(
            $attribute->getAttributeId(),
            'Seed data missing: run scripts/seed-test-data.php'
        );
        $redValueIndex = null;
        foreach ($attribute->getOptions() as $option) {
            if ($option->getLabel() === 'Red') {
                $redValueIndex = (int) $option->getValue();
            }
        }
        $this->assertNotNull($redValueIndex, 'Seeded color attribute must have a Red option');

        $configurable = $this->get(ProductRepositoryInterface::class)->get(self::CONFIGURABLE_SKU);

        $quote = $this->newGuestQuote();
        $quote->addProduct(
            $configurable,
            new DataObject([
                'qty' => 1,
                'super_attribute' => [(int) $attribute->getAttributeId() => $redValueIndex],
            ])
        );
        $this->collectAndSaveQuote($quote);

        $orderId = $this->get(CartManagementInterface::class)->placeOrder((int) $quote->getId());
        $order = $this->get(OrderRepositoryInterface::class)->get($orderId);
        $this->payInvoice($order);

        // Build the credit memo Magento's admin flow would build — its items
        // include BOTH the configurable parent row and the zero-priced child
        // row, each reporting the child SKU. This is the shape that broke live.
        $creditmemo = $this->get(CreditmemoFactory::class)->createByOrder($order, $order->getData());

        $skusInMemo = array_map(
            static fn ($item) => $item->getSku(),
            $creditmemo->getAllItems()
        );
        $this->assertGreaterThan(
            1,
            count(array_keys($skusInMemo, self::RED_SKU, true)),
            'Precondition: the composite credit memo must carry the child SKU on more than one row '
            . '(parent + child) — if Magento stops doing this, the regression scenario has changed.'
        );

        $built = $this->get(RestRequestBuilder::class)->buildRefundItems($creditmemo);

        $this->assertFalse($built['skip'], 'A priced configurable refund must reach TaxCloud');
        $itemIds = array_column($built['items'], 'itemId');
        $this->assertSame(
            array_unique($itemIds),
            $itemIds,
            'v3 refund submissions must carry at most one entry per item reference; duplicates are '
            . 'rejected by the API ("appears more than once ... combine the quantities")'
        );

        $quantities = array_column($built['items'], 'quantity', 'itemId');
        $this->assertArrayHasKey(self::RED_SKU, $quantities);
        $this->assertEqualsWithDelta(
            1.0,
            (float) $quantities[self::RED_SKU],
            0.0001,
            'The combined entry must carry the parent quantity — never the doubled parent+child sum'
        );
    }
}
