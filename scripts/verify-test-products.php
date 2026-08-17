#!/usr/bin/env php
<?php
/**
 * Prove the seeded catalog is usable: every product loads, is enabled and
 * visible, and can actually be added to a cart.
 *
 * "Exists in the DB" is not the bar — a product can be saved and still be
 * unbuyable (disabled by Content Staging, not salable because an index is
 * stale, a bundle with no default selection). This adds each seeded SKU to a
 * real quote through Quote::addProduct() — the same call the storefront makes —
 * and prints the quote lines that result, so the composite shapes are visible
 * too: which line carries the price, and what quantity each one stores.
 *
 * The quote is built in memory and never saved, so this is read-only against
 * the catalog and writes nothing.
 *
 * Usage:  php verify-test-products.php [magento-root]
 *
 * Exit code is 0 only if every product was added successfully.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL);

$candidates = array_filter([
    $argv[1] ?? null,
    getenv('MAGENTO_ROOT') ?: null,
    getcwd() ?: null,
    dirname(__DIR__, 5), // app/code/Taxcloud/Magento2/scripts -> magento root
]);

$magentoRoot = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate . '/app/bootstrap.php')) {
        $magentoRoot = realpath($candidate);
        break;
    }
}
if ($magentoRoot === null) {
    fwrite(STDERR, "ERROR: could not find a Magento install (app/bootstrap.php).\n");
    exit(1);
}

require $magentoRoot . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

try {
    $om->get(\Magento\Framework\App\State::class)
        ->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
} catch (\Magento\Framework\Exception\LocalizedException $e) {
    // area already set
}

/** @var \Magento\Store\Model\StoreManagerInterface $storeManager */
$storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$store = $storeManager->getDefaultStoreView();
$storeManager->setCurrentStore($store);

/** @var \Magento\Catalog\Api\ProductRepositoryInterface $productRepository */
$productRepository = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

/**
 * The seeded catalog, with the buy request each type needs. A type whose add to
 * cart needs no options (simple, virtual, downloadable) just carries a qty.
 * Anything missing from the catalog is reported as skipped, not failed — the
 * gift card only exists on Adobe Commerce.
 */
$cases = [
    ['sku' => 'test-product',     'qty' => 1, 'options' => []],
    ['sku' => 'test-virtual',     'qty' => 1, 'options' => []],
    ['sku' => 'test-downloadable', 'qty' => 1, 'options' => []],
    ['sku' => 'test-configurable', 'qty' => 1, 'options' => 'configurable'],
    ['sku' => 'test-grouped',     'qty' => 1, 'options' => 'grouped'],
    // qty 3 on purpose: the bundle selection stored at qty 2 has to reach the
    // quote as 6, which is the arithmetic the composite handling turns on.
    ['sku' => 'test-bundle-dynamic', 'qty' => 3, 'options' => 'bundle'],
    ['sku' => 'test-bundle-fixed',   'qty' => 1, 'options' => 'bundle'],
    ['sku' => 'test-giftcard',       'qty' => 1, 'options' => 'giftcard'],
];

/**
 * Build the buy request for a product, choosing the first available value for
 * each required option so the add is deterministic.
 */
$buyRequestFor = function (\Magento\Catalog\Api\Data\ProductInterface $product, $kind, int $qty) use ($om): array {
    $request = ['qty' => $qty];

    if ($kind === 'configurable') {
        $typeInstance = $product->getTypeInstance();
        $attributes = $typeInstance->getConfigurableAttributesAsArray($product);
        $superAttribute = [];
        foreach ($attributes as $attribute) {
            $superAttribute[$attribute['attribute_id']] = $attribute['values'][0]['value_index'];
        }
        $request['super_attribute'] = $superAttribute;
    }

    if ($kind === 'grouped') {
        $superGroup = [];
        foreach ($product->getTypeInstance()->getAssociatedProducts($product) as $associated) {
            $superGroup[$associated->getId()] = 1;
        }
        $request['super_group'] = $superGroup;
    }

    if ($kind === 'bundle') {
        $bundleOption = [];
        $bundleOptionQty = [];
        $options = $product->getTypeInstance()->getOptionsCollection($product);
        $selections = $product->getTypeInstance()
            ->getSelectionsCollection($options->getAllIds(), $product);
        foreach ($options as $option) {
            $ids = [];
            foreach ($selections as $selection) {
                if ((int) $selection->getOptionId() === (int) $option->getId()) {
                    $ids[] = (int) $selection->getSelectionId();
                    $bundleOptionQty[(int) $option->getId()] = (int) $selection->getSelectionQty();
                }
            }
            if ($ids) {
                // checkbox options take an array of selection ids
                $bundleOption[(int) $option->getId()] = $ids;
            }
        }
        $request['bundle_option'] = $bundleOption;
        $request['bundle_option_qty'] = $bundleOptionQty;
    }

    if ($kind === 'giftcard') {
        $request['giftcard_amount'] = $product->getGiftcardAmounts()[0]['value']
            ?? $product->getGiftcardAmounts()[0]['website_value']
            ?? 25.00;
        $request['giftcard_sender_name'] = 'Test Sender';
        $request['giftcard_sender_email'] = 'sender@example.com';
        $request['giftcard_recipient_name'] = 'Test Recipient';
        $request['giftcard_recipient_email'] = 'recipient@example.com';
    }

    return $request;
};

/**
 * The SKUs the Test Category page would actually list, built with the same
 * store, category, status and visibility filters the storefront product list
 * applies. Checking the visibility attribute alone would not catch a product
 * that is enabled and visible but missing from the category index.
 */
$listedSkus = [];
$categoryCollection = $om->create(\Magento\Catalog\Model\ResourceModel\Category\CollectionFactory::class)
    ->create()
    ->addAttributeToFilter('url_key', 'test-category')
    ->setPageSize(1);

if ($categoryCollection->getSize()) {
    $category = $om->create(\Magento\Catalog\Model\CategoryFactory::class)
        ->create()
        ->setStoreId((int) $store->getId())
        ->load((int) $categoryCollection->getFirstItem()->getId());

    $listing = $om->create(\Magento\Catalog\Model\ResourceModel\Product\CollectionFactory::class)->create();
    $listing->setStore($store)
        ->addAttributeToSelect('sku')
        ->addCategoryFilter($category)
        ->setVisibility($om->get(\Magento\Catalog\Model\Product\Visibility::class)->getVisibleInCatalogIds())
        ->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);

    foreach ($listing as $listed) {
        $listedSkus[$listed->getSku()] = true;
    }
    echo 'Test Category lists ' . count($listedSkus) . " product(s)\n\n";
} else {
    echo "WARNING: Test Category not found; skipping the listing check\n\n";
}

$failures = 0;
$skipped = [];

foreach ($cases as $case) {
    $sku = $case['sku'];

    try {
        $product = $productRepository->get($sku, false, (int) $store->getId());
    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
        $skipped[] = $sku;
        echo "SKIP  $sku — not in this catalog\n";
        continue;
    }

    $status = (int) $product->getStatus();
    $visibility = (int) $product->getVisibility();
    $enabled = $status === \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED;
    $visible = $visibility !== \Magento\Catalog\Model\Product\Visibility::VISIBILITY_NOT_VISIBLE;

    // A fresh quote per product, so one failure cannot cascade into the next.
    /** @var \Magento\Quote\Model\Quote $quote */
    $quote = $om->create(\Magento\Quote\Model\QuoteFactory::class)->create();
    $quote->setStore($store);

    $addError = null;
    try {
        $request = new \Magento\Framework\DataObject(
            $buyRequestFor($product, $case['options'], $case['qty'])
        );
        $result = $quote->addProduct($product, $request);
        if (is_string($result)) {
            // addProduct returns the error message as a string rather than throwing
            $addError = $result;
        }
    } catch (\Throwable $e) {
        $addError = get_class($e) . ': ' . $e->getMessage();
    }

    // count(getAllItems()), not getItemsCount(): the latter reads a quote field
    // that is only maintained once the quote is saved, and this one never is.
    $lines = $quote->getAllItems();
    $listed = $listedSkus === [] || isset($listedSkus[$sku]);
    $ok = $enabled && $visible && $listed && $addError === null && count($lines) > 0;
    if (!$ok) {
        $failures++;
    }

    printf(
        "%-5s %-22s %-13s %-9s %-12s %s\n",
        $ok ? 'OK' : 'FAIL',
        $sku,
        $product->getTypeId(),
        $enabled ? 'enabled' : 'DISABLED',
        $visible ? 'visible' : 'NOT VISIBLE',
        $listed ? 'in category' : 'NOT IN CATEGORY'
    );

    if ($addError !== null) {
        echo "      add to cart failed: $addError\n";
        continue;
    }

    // Print the quote lines this product produced. For composites this is the
    // interesting part: which lines exist, which one carries the price, and the
    // qty each one stores. A bundle child stores its qty PER PARENT, so the
    // effective quantity — what a tax service has to be told — is the product of
    // the two. Row totals are not shown: they are only populated by
    // collectTotals(), which this deliberately does not run.
    foreach ($lines as $item) {
        $parent = $item->getParentItem();
        $qty = (float) $item->getQty();
        printf(
            "      %s%-44s qty %-5s price %-7s%s\n",
            $parent ? '↳ ' : '  ',
            $item->getSku(),
            rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.'),
            number_format((float) $item->getPrice(), 2),
            $parent
                ? sprintf(' x parent qty %g = %g effective', (float) $parent->getQty(), $qty * (float) $parent->getQty())
                : ''
        );
    }
}

echo "\n";
if ($skipped) {
    echo 'skipped: ' . implode(', ', $skipped) . "\n";
}

if ($failures > 0) {
    echo "$failures product(s) FAILED\n";
    exit(1);
}

echo "all seeded products load, are enabled and visible, and add to cart\n";
exit(0);
