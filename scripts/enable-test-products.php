#!/usr/bin/env php
<?php
/**
 * Enable the seeded test products — in a FRESH process, separate from the seed
 * that creates them.
 *
 * Why a separate process: on Adobe Commerce (Enterprise), Content Staging
 * (Magento_Staging) makes a just-created product's active "version" stale within
 * the process that created it. Calling Product\Action::updateAttributes() to set
 * status=Enabled in that same process (i.e. inside seed-test-data.php) does NOT
 * persist — the product stays Disabled, and Quote::addProduct() then rejects it
 * as "not available". Running the exact same call from a fresh process, which
 * re-reads the committed version from the DB, sticks. Open Source has no staging,
 * so products are already enabled and this is a harmless no-op there.
 *
 * scripts/install-magento.sh runs this immediately after the seed and before the
 * fresh reindex (the reindex re-aggregates the configurable's child salability
 * once the children are enabled).
 *
 * Usage:  php enable-test-products.php [magento-root]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

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
        ->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
} catch (\Magento\Framework\Exception\LocalizedException $e) {
    // area already set
}

// The full seeded catalog. Keep in sync with seed-test-data.php. test-giftcard
// only exists on Adobe Commerce; a missing sku is skipped below, so listing it
// unconditionally is safe.
$skus = [
    'test-product',
    'test-variant-red',
    'test-variant-blue',
    'test-configurable',
    'test-virtual',
    'test-downloadable',
    'test-grouped',
    'test-bundle-dynamic',
    'test-bundle-fixed',
    'test-giftcard',
];

$productRepository = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

$ids = [];
$found = [];
foreach ($skus as $sku) {
    try {
        $ids[] = (int) $productRepository->get($sku)->getId();
        $found[] = $sku;
    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
        echo "[enable] skip $sku (not found)\n";
    }
}

if (!$ids) {
    echo "[enable] no test products found; nothing to do.\n";
    exit(0);
}

$om->get(\Magento\Catalog\Model\Product\Action::class)->updateAttributes(
    $ids,
    ['status' => \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED],
    0
);

echo '[enable] enabled ' . count($ids) . ' test products: ' . implode(', ', $found) . "\n";
