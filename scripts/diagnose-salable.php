#!/usr/bin/env php
<?php
/**
 * Read-only salability diagnostic. Explains WHY a product would be rejected by
 * Quote::addProduct() (which throws "Product that you are trying to add is not
 * available." when $product->isSalable() is false).
 *
 * Writes nothing — safe to run against any install, including a dev DB.
 *
 * Usage:
 *   php diagnose-salable.php [magento-root] [sku ...]
 *
 * Defaults: magento-root resolved like seed-test-data.php; SKUs default to the
 * seeded test catalog.
 *
 * In the integration stack (products already seeded by the test run):
 *   docker compose exec -T -w /var/www/html app \
 *     php /srv/module/scripts/diagnose-salable.php
 *
 * In the Warden dev shell (only if you've seeded the test catalog there):
 *   php app/code/Taxcloud/Magento2/scripts/diagnose-salable.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$args = array_slice($argv, 1);

// First arg may be the magento root; anything that isn't a dir is treated as a SKU.
$magentoRoot = null;
if (isset($args[0]) && is_file(rtrim($args[0], '/') . '/app/bootstrap.php')) {
    $magentoRoot = realpath(array_shift($args));
}

$candidates = array_filter([
    $magentoRoot,
    getenv('MAGENTO_ROOT') ?: null,
    getcwd() ?: null,
    dirname(__DIR__, 5),
]);
foreach ($candidates as $candidate) {
    if (is_file($candidate . '/app/bootstrap.php')) {
        $magentoRoot = realpath($candidate);
        break;
    }
}
if (!$magentoRoot) {
    fwrite(STDERR, "Could not find a Magento install (app/bootstrap.php). Pass the root as arg 1.\n");
    exit(1);
}

$skus = $args ?: ['test-product', 'test-configurable', 'test-variant-red', 'test-variant-blue'];

require $magentoRoot . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

try {
    $om->get(\Magento\Framework\App\State::class)
        ->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
} catch (\Magento\Framework\Exception\LocalizedException $e) {
    // already set
}

$storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$store = $storeManager->getStore('default');
$storeManager->setCurrentStore($store);

$scopeConfig = $om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class);
$repo = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

$line = str_repeat('-', 60);

echo "Magento root : $magentoRoot\n";
$productMeta = $om->get(\Magento\Framework\App\ProductMetadataInterface::class);
echo "Edition      : " . $productMeta->getEdition() . " " . $productMeta->getVersion() . "\n";
echo "Store        : " . $store->getCode() . " (id " . $store->getId()
    . ", website " . $store->getWebsiteId() . ")\n";
echo "manage_stock : " . var_export(
    $scopeConfig->getValue('cataloginventory/item_options/manage_stock'),
    true
) . " (global)\n";

// Salability gates that commonly differ on Adobe Commerce (Enterprise) and
// leave an otherwise-stocked product "not available".
$gates = [
    'catalog/magento_catalogpermissions/enabled' => 'CatalogPermissions (EE)',
    'btob/website_configuration/sharedcatalog_active' => 'B2B Shared Catalog (EE)',
    'cataloginventory/options/show_out_of_stock' => 'Show out-of-stock',
];
foreach ($gates as $path => $label) {
    echo str_pad($label, 28) . ": " . var_export($scopeConfig->getValue($path), true) . "  ($path)\n";
}

// Indexer modes — a scheduled inventory/stock indexer that hasn't run leaves
// salability stale.
try {
    $indexerRegistry = $om->get(\Magento\Framework\Indexer\IndexerRegistry::class);
    foreach (['cataloginventory_stock', 'inventory'] as $indexerId) {
        try {
            $ix = $indexerRegistry->get($indexerId);
            echo str_pad("indexer:$indexerId", 28) . ": mode=" . ($ix->isScheduled() ? 'schedule' : 'realtime')
                . " valid=" . var_export($ix->isValid(), true) . "\n";
        } catch (\Throwable $e) {
            // indexer not present on this edition/version
        }
    }
} catch (\Throwable $e) {
    // no indexer registry
}
echo "$line\n";

$describe = function ($sku) use ($repo, $store, $om, $line) {
    echo "SKU: $sku\n";
    try {
        $p = $repo->get($sku, false, (int) $store->getId(), true);
    } catch (\Throwable $e) {
        echo "  NOT FOUND: " . $e->getMessage() . "\n$line\n";
        return;
    }

    echo "  id        : " . $p->getId() . "\n";
    echo "  type      : " . $p->getTypeId() . "\n";
    echo "  status    : " . $p->getStatus() . " (1=enabled, 2=disabled)\n";
    echo "  visibility: " . $p->getVisibility() . "\n";
    echo "  websites  : " . implode(',', $p->getWebsiteIds() ?: []) . "\n";

    $stockItem = $p->getExtensionAttributes() ? $p->getExtensionAttributes()->getStockItem() : null;
    if ($stockItem) {
        echo "  stockItem : is_in_stock=" . var_export($stockItem->getIsInStock(), true)
            . " qty=" . $stockItem->getQty()
            . " manage_stock=" . var_export($stockItem->getManageStock(), true)
            . " use_config_manage_stock=" . var_export($stockItem->getUseConfigManageStock(), true) . "\n";
    } else {
        echo "  stockItem : (none on extension attributes)\n";
    }

    // MSI salable qty, if available.
    try {
        if (interface_exists(\Magento\InventorySalesApi\Api\GetProductSalableQtyInterface::class)) {
            $stockResolver = $om->get(\Magento\InventorySalesApi\Api\StockResolverInterface::class);
            $stockId = (int) $stockResolver->execute('website', $store->getWebsiteCode() ?? 'base')->getStockId();
            $salableQty = $om->get(\Magento\InventorySalesApi\Api\GetProductSalableQtyInterface::class)
                ->execute($sku, $stockId);
            echo "  MSI       : stockId=$stockId salableQty=$salableQty\n";
        }
    } catch (\Throwable $e) {
        echo "  MSI       : (could not resolve: " . $e->getMessage() . ")\n";
    }

    echo "  >> isSalable() = " . var_export($p->isSalable(), true) . "  <<\n";

    if ($p->getTypeId() === 'configurable') {
        $children = $p->getTypeInstance()->getUsedProducts($p);
        echo "  configurable children (associated simples): " . count($children) . "\n";
        foreach ($children as $c) {
            echo "    - " . $c->getSku() . " id=" . $c->getId()
                . " status=" . $c->getStatus()
                . " salable=" . var_export($c->isSalable(), true) . "\n";
        }
        if (count($children) === 0) {
            echo "  !! No associated simples -> a configurable with no salable child is NOT salable.\n";
        }
    }

    echo "$line\n";
};

foreach ($skus as $sku) {
    $describe($sku);
}
