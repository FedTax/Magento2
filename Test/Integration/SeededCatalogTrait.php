<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Integration;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Module\Manager as ModuleManager;

/**
 * Adding a seeded product of any catalog type to a cart, and reading back the
 * lines that reached TaxCloud.
 *
 * Every composite type needs its own buy request, and getting one wrong fails in
 * ways that look like tax bugs ("Product that you are trying to add is not
 * available", "Please specify product option(s)"). Keeping that knowledge in one
 * place means a test that exercises a type is not also a test of whether its
 * author knew how to add it.
 *
 * The same option-choosing logic lives in scripts/verify-test-products.php,
 * which runs at install time — if these requests ever stop working, that script
 * fails first and names the product.
 */
trait SeededCatalogTrait
{
    /**
     * The buy request a seeded product needs, choosing every available option so
     * the add is deterministic.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @return array<string, mixed>
     */
    protected function buyRequestFor($product, int $qty = 1): array
    {
        $request = ['qty' => $qty];
        $type = $product->getTypeInstance();

        switch ($product->getTypeId()) {
            case 'configurable':
                $superAttribute = [];
                foreach ($type->getConfigurableAttributesAsArray($product) as $attribute) {
                    $superAttribute[$attribute['attribute_id']] = $attribute['values'][0]['value_index'];
                }
                $request['super_attribute'] = $superAttribute;
                break;

            case 'grouped':
                // A grouped product has no quantity of its own — the shopper sets
                // one per association — so $qty is applied to each of them. That
                // also means the resulting lines are independent and can be
                // refunded separately, unlike a composite's.
                $superGroup = [];
                foreach ($type->getAssociatedProducts($product) as $associated) {
                    $superGroup[$associated->getId()] = $qty;
                }
                $request['super_group'] = $superGroup;
                break;

            case 'bundle':
                $options = $type->getOptionsCollection($product);
                $selections = $type->getSelectionsCollection($options->getAllIds(), $product);
                $bundleOption = [];
                foreach ($options as $option) {
                    foreach ($selections as $selection) {
                        if ((int) $selection->getOptionId() === (int) $option->getId()) {
                            $bundleOption[(int) $option->getId()][] = (int) $selection->getSelectionId();
                        }
                    }
                }
                $request['bundle_option'] = $bundleOption;
                break;

            case 'giftcard':
                $amounts = $product->getGiftcardAmounts();
                $request['giftcard_amount'] = $amounts[0]['value'] ?? $amounts[0]['website_value'] ?? 25.00;
                $request['giftcard_sender_name'] = 'Test Sender';
                $request['giftcard_sender_email'] = 'sender@example.com';
                $request['giftcard_recipient_name'] = 'Test Recipient';
                $request['giftcard_recipient_email'] = 'recipient@example.com';
                break;
        }

        return $request;
    }

    /**
     * Load a seeded product, skipping the test when its module is not installed
     * and failing it when the seed simply has not been run.
     *
     * @return \Magento\Catalog\Api\Data\ProductInterface
     */
    protected function seededProduct(string $sku)
    {
        if ($sku === 'test-giftcard' && !$this->get(ModuleManager::class)->isEnabled('Magento_GiftCard')) {
            $this->markTestSkipped('Magento_GiftCard is Adobe Commerce only.');
        }

        try {
            return $this->get(ProductRepositoryInterface::class)->get($sku);
        } catch (NoSuchEntityException $e) {
            $this->fail("Seed data missing: $sku (run scripts/seed-test-data.php).");
        }
    }

    /**
     * TaxCloud cart lines as [sku, qty, price], with shipping dropped.
     *
     * @param array<int, array<string, mixed>> $cartItems
     * @return array<int, array{0: string, 1: float, 2: float}>
     */
    protected function productLines(array $cartItems): array
    {
        return array_map(
            static fn ($line) => [
                (string) $line['ItemID'],
                (float) $line['Qty'],
                round((float) $line['Price'], 4),
            ],
            array_values(array_filter(
                $cartItems,
                static fn ($line) => ($line['ItemID'] ?? null) !== 'shipping'
            ))
        );
    }

    /**
     * A lookup double that taxes whatever it is handed at a flat rate.
     *
     * Asserting against real TaxCloud rates would make these tests a subscription
     * to someone else's rate table; per-line cent rounding makes it worse (one
     * 8.25% rate reads as 8.3% on a $10 line and 8.267% on a $30 one). A flat
     * rate keeps the assertions about our arithmetic.
     */
    protected function flatRateLookupResponder(float $rate): \Closure
    {
        return static function (array $args) use ($rate): array {
            $responses = [];
            foreach ($args['cartItems'] ?? [] as $line) {
                $responses[] = [
                    'CartItemIndex' => $line['Index'],
                    'TaxAmount' => round($line['Price'] * $line['Qty'] * $rate, 2),
                ];
            }

            return [
                'LookupResult' => [
                    'ResponseType' => 'OK',
                    'Messages' => '',
                    'CartItemsResponse' => ['CartItemResponse' => $responses],
                ],
            ];
        };
    }
}
