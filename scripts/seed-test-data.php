#!/usr/bin/env php
<?php
/**
 * Seed the standard TaxCloud test environment into an already-installed
 * Magento. Replaces the old committed-DB-fixture approach: instead of
 * restoring a dump, we apply the same known state programmatically on top
 * of ANY installed Magento database, regardless of edition or version.
 *
 * What it seeds (idempotent — safe to re-run):
 *   - Admin user      admin / admin@example.com / 1234567a (Administrators)
 *   - Category        "Test Category" (url key: test-category)
 *   - Product         "Test Product"  (sku: test-product, simple, $10,
 *                     in stock, assigned to Test Category)
 *   - Config          tax/taxcloud_settings/* (enabled, logging,
 *                     verify_address, default_tic, api creds from env)
 *                     shipping/origin/*       (1401 Lavaca St, Austin TX)
 *                     carriers/flatrate/active + payment/checkmo/active
 *                     (so at least one shipping + payment method works)
 *   - Reindex all indexers, flush all caches
 *
 * Usage:
 *   php seed-test-data.php [magento-root]
 *
 * The Magento root is resolved from: argv[1], then $MAGENTO_ROOT, then the
 * current working directory, then this script's location under
 * app/code/Taxcloud/Magento2/scripts/.
 *
 * Required env vars: TAXCLOUD_API_ID, TAXCLOUD_API_KEY
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL);

// --- Locate and bootstrap Magento ------------------------------------------

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
    fwrite(STDERR, "Pass the Magento root as the first argument or set MAGENTO_ROOT.\n");
    exit(1);
}

$apiId  = getenv('TAXCLOUD_API_ID');
$apiKey = getenv('TAXCLOUD_API_KEY');
if (!$apiId || !$apiKey) {
    fwrite(STDERR, "ERROR: TAXCLOUD_API_ID and TAXCLOUD_API_KEY must be set in the environment.\n");
    exit(1);
}

require $magentoRoot . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

try {
    $om->get(\Magento\Framework\App\State::class)
        ->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
} catch (\Magento\Framework\Exception\LocalizedException $e) {
    // Area code already set — fine.
}

/** @var \Magento\Store\Model\StoreManagerInterface $storeManager */
$storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$storeManager->setCurrentStore(\Magento\Store\Model\Store::ADMIN_CODE);

$step = function (string $msg): void {
    echo "[seed] $msg\n";
};

// --- 1. Admin user ----------------------------------------------------------

const ADMIN_USERNAME = 'admin';
const ADMIN_EMAIL    = 'admin@example.com';
const ADMIN_PASSWORD = '1234567a';

$roleCollection = $om->create(\Magento\Authorization\Model\ResourceModel\Role\CollectionFactory::class)
    ->create()
    ->addFieldToFilter('role_name', 'Administrators');
$adminRoleId = (int) ($roleCollection->getFirstItem()->getId() ?: 1);

/** @var \Magento\User\Model\User $user */
$user = $om->create(\Magento\User\Model\UserFactory::class)->create();
$user->loadByUsername(ADMIN_USERNAME);
$existed = (bool) $user->getId();

$user->setFirstname('Admin')
    ->setLastname('User')
    ->setUsername(ADMIN_USERNAME)
    ->setEmail(ADMIN_EMAIL)
    ->setPassword(ADMIN_PASSWORD)
    ->setIsActive(1)
    ->setRoleId($adminRoleId);
$user->save();

$step('admin user "' . ADMIN_USERNAME . '" <' . ADMIN_EMAIL . '> '
    . ($existed ? 'updated' : 'created') . " (role $adminRoleId)");

// --- 2. System configuration -------------------------------------------------

$configValues = [
    'tax/taxcloud_settings/enabled'        => '1',
    'tax/taxcloud_settings/logging'        => '1',
    'tax/taxcloud_settings/verify_address' => '1',
    'tax/taxcloud_settings/api_id'         => $apiId,
    'tax/taxcloud_settings/api_key'        => $apiKey,
    'tax/taxcloud_settings/default_tic'    => '20000',

    // Ship-from origin: 1401 Lavaca St, Austin TX 78701 (region 57 = Texas)
    'shipping/origin/country_id'   => 'US',
    'shipping/origin/region_id'    => '57',
    'shipping/origin/postcode'     => '78701-1634',
    'shipping/origin/city'         => 'Austin',
    'shipping/origin/street_line1' => '1401 Lavaca St',

    // Guarantee at least one active shipping carrier + payment method.
    'carriers/flatrate/active' => '1',
    'payment/checkmo/active'   => '1',
];

// Go through PreparedValueFactory (what `bin/magento config:set` uses) so any
// backend model attached to a field (e.g. encrypted values) is honored.
$preparedValueFactory = $om->get(\Magento\Config\Model\PreparedValueFactory::class);
foreach ($configValues as $path => $value) {
    $backendModel = $preparedValueFactory->create(
        $path,
        $value,
        \Magento\Framework\App\Config\ScopeConfigInterface::SCOPE_TYPE_DEFAULT
    );
    if ($backendModel instanceof \Magento\Framework\App\Config\Value) {
        $backendModel->getResource()->save($backendModel);
    }
    $shown = ($path === 'tax/taxcloud_settings/api_key') ? '***' : $value;
    $step("config $path = $shown");
}

// --- 3. Test category --------------------------------------------------------

const CATEGORY_NAME    = 'Test Category';
const CATEGORY_URL_KEY = 'test-category';

$rootCategoryId = (int) $storeManager->getDefaultStoreView()->getRootCategoryId();

$categoryCollection = $om->create(\Magento\Catalog\Model\ResourceModel\Category\CollectionFactory::class)
    ->create()
    ->addAttributeToFilter('url_key', CATEGORY_URL_KEY)
    ->setPageSize(1);

if ($categoryCollection->getSize()) {
    $categoryId = (int) $categoryCollection->getFirstItem()->getId();
    $step('category "' . CATEGORY_NAME . "\" already exists (id $categoryId)");
} else {
    /** @var \Magento\Catalog\Model\Category $category */
    $category = $om->create(\Magento\Catalog\Model\CategoryFactory::class)->create();
    $category->setName(CATEGORY_NAME)
        ->setUrlKey(CATEGORY_URL_KEY)
        ->setParentId($rootCategoryId)
        ->setIsActive(true)
        ->setIncludeInMenu(true)
        ->setStoreId(0);
    $category = $om->get(\Magento\Catalog\Api\CategoryRepositoryInterface::class)->save($category);
    $categoryId = (int) $category->getId();
    $step('category "' . CATEGORY_NAME . "\" created (id $categoryId, parent $rootCategoryId)");
}

// --- 4. Test product ---------------------------------------------------------

const PRODUCT_SKU   = 'test-product';
const PRODUCT_NAME  = 'Test Product';
const PRODUCT_PRICE = 10.00;

/** @var \Magento\Catalog\Api\ProductRepositoryInterface $productRepository */
$productRepository = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

try {
    $productRepository->get(PRODUCT_SKU);
    $step('product "' . PRODUCT_SKU . '" already exists');
} catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
    $attributeSetId = (int) $om->get(\Magento\Eav\Model\Config::class)
        ->getEntityType(\Magento\Catalog\Model\Product::ENTITY)
        ->getDefaultAttributeSetId();
    $websiteId = (int) $storeManager->getDefaultStoreView()->getWebsiteId();

    /** @var \Magento\Catalog\Model\Product $product */
    $product = $om->create(\Magento\Catalog\Model\ProductFactory::class)->create();
    $product->setSku(PRODUCT_SKU)
        ->setName(PRODUCT_NAME)
        ->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE)
        ->setAttributeSetId($attributeSetId)
        ->setPrice(PRODUCT_PRICE)
        ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
        ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
        ->setWebsiteIds([$websiteId])
        ->setStockData([
            'use_config_manage_stock' => 1,
            'qty'                     => 100,
            'is_in_stock'             => 1,
        ]);
    $productRepository->save($product);
    $step('product "' . PRODUCT_SKU . '" created ($' . number_format(PRODUCT_PRICE, 2) . ')');
}

$om->get(\Magento\Catalog\Api\CategoryLinkManagementInterface::class)
    ->assignProductToCategories(PRODUCT_SKU, [$categoryId]);
$step('product "' . PRODUCT_SKU . "\" assigned to category $categoryId");

// --- 5. Reindex + cache flush --------------------------------------------------

$indexers = $om->create(\Magento\Indexer\Model\Indexer\CollectionFactory::class)
    ->create()
    ->getItems();
foreach ($indexers as $indexer) {
    $indexer->reindexAll();
    $step('reindexed ' . $indexer->getId());
}

$cacheManager = $om->get(\Magento\Framework\App\Cache\Manager::class);
$cacheManager->flush($cacheManager->getAvailableTypes());
$step('flushed all caches');

echo "\n[seed] Done. Test environment ready:\n";
echo '       admin:    ' . ADMIN_USERNAME . ' / ' . ADMIN_PASSWORD . ' <' . ADMIN_EMAIL . ">\n";
echo '       category: ' . CATEGORY_NAME . ' (' . CATEGORY_URL_KEY . ")\n";
echo '       product:  ' . PRODUCT_SKU . ' ($' . number_format(PRODUCT_PRICE, 2) . ")\n";
