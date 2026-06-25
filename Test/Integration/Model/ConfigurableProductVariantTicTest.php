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
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\DataObject;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Proves that when a configurable product is purchased and a specific simple
 * variant is chosen, the TIC sent in the TaxCloud lookup is the *variant's*
 * TIC — not the parent's, not the sibling's. Covers gap #2.1.
 *
 * This needs real Magento: configurable handling spans the catalog type model,
 * super-attribute selection, and quote-item compositing. Which product
 * reference ends up on the tax line item (variant vs. parent) is exactly what
 * decides the TIC, and only the real stack answers that faithfully.
 *
 * The configurable + its two variants (RED tic 20010, BLUE tic 00000) are
 * provisioned by scripts/seed-test-data.php so the same fixture backs e2e tests.
 */
class ConfigurableProductVariantTicTest extends IntegrationTestCase
{
    private const CONFIGURABLE_SKU = 'test-configurable';
    private const ATTRIBUTE_CODE = 'test_variant_color';
    private const RED_LABEL = 'Red';
    private const RED_TIC = '20010';
    private const BLUE_TIC = '00000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->installSoapMock();
    }

    public function testTicResolvesFromSelectedSimpleVariant(): void
    {
        $soap = $this->soapClient();

        [$attributeId, $redValueIndex] = $this->resolveVariantAttribute(self::RED_LABEL);

        $configurable = $this->get(ProductRepositoryInterface::class)->get(self::CONFIGURABLE_SKU);

        $quote = $this->newGuestQuote();
        $quote->addProduct(
            $configurable,
            new DataObject([
                'qty' => 1,
                'super_attribute' => [$attributeId => $redValueIndex],
            ])
        );
        $this->collectAndSaveQuote($quote);

        $this->assertGreaterThanOrEqual(
            1,
            $soap->callCount('lookup'),
            'Collecting totals for the configurable should have triggered a lookup SOAP call.'
        );

        $cartItems = $soap->firstCallArgs('lookup')['cartItems'] ?? [];
        $this->assertNotEmpty($cartItems, 'The lookup payload should carry the cart line items.');

        $productLines = array_values(array_filter(
            $cartItems,
            static fn ($line) => ($line['ItemID'] ?? null) !== 'shipping'
        ));
        $this->assertCount(1, $productLines, 'Expected exactly one product line in the lookup payload.');

        $this->assertSame(
            self::RED_TIC,
            (string) ($productLines[0]['TIC'] ?? null),
            'The lookup must carry the chosen RED variant TIC (' . self::RED_TIC . ') — not the BLUE '
            . 'sibling (' . self::BLUE_TIC . ') and not the parent default. If this fails, the '
            . 'configurable quote item is exposing the wrong product to ProductTicService.'
        );
    }

    /**
     * Resolve the seeded color attribute's id and the value index for one option
     * label, so we can build the super_attribute buy request.
     *
     * @return array{0: int, 1: int} [attributeId, valueIndex]
     */
    private function resolveVariantAttribute(string $label): array
    {
        $attribute = $this->get(EavConfig::class)->getAttribute(Product::ENTITY, self::ATTRIBUTE_CODE);
        $this->assertNotEmpty(
            $attribute->getAttributeId(),
            'Seed data missing: the "' . self::ATTRIBUTE_CODE . '" attribute should exist '
            . '(run scripts/seed-test-data.php).'
        );

        foreach ($attribute->getOptions() as $option) {
            if ($option->getLabel() === $label) {
                return [(int) $attribute->getId(), (int) $option->getValue()];
            }
        }

        $this->fail('Seed data missing: no "' . $label . '" option on ' . self::ATTRIBUTE_CODE . '.');
    }
}
