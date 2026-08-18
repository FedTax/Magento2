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
 *   - Configurable    "Test Configurable" (sku: test-configurable) with two
 *                     simple variants on a test_variant_color attribute:
 *                     test-variant-red (TIC 20010), test-variant-blue (TIC 00000)
 *   - Every other     one product per remaining catalog type, all $10 and all on
 *     catalog type    the store default TIC, so each quote-line shape is covered:
 *                     test-virtual, test-downloadable, test-grouped (associates
 *                     the simple + the virtual), test-bundle-dynamic ($30/bundle:
 *                     1x test-product + 2x test-virtual, selections carry the
 *                     price), test-bundle-fixed ($50 on the parent), and on
 *                     Adobe Commerce only, test-giftcard ($25 virtual)
 *   - Config          tax/taxcloud_settings/* (enabled, logging,
 *                     verify_address, default_tic, api creds from env)
 *                     shipping/origin/*       (1401 Lavaca St, Austin TX)
 *                     carriers/flatrate/active + payment/checkmo/active
 *                     (so at least one shipping + payment method works)
 *                     web/url/use_store = 1 (store code in URLs, so both
 *                     stores are reachable on one base URL: /default/, /second/)
 *   - Multi-store     second website/group/store view (all code "second"),
 *                     same root category + full test catalog assigned;
 *                     TaxCloud DISABLED on it via store-scope override;
 *                     own customer record (same email/password — accounts
 *                     are per-website)
 *   - Reindex all indexers, flush all caches
 *
 * Usage:
 *   php seed-test-data.php [magento-root] [--products-only] [--recreate[=sku,sku]]
 *
 * The Magento root is resolved from: argv[1], then $MAGENTO_ROOT, then the
 * current working directory, then this script's location under
 * app/code/Taxcloud/Magento2/scripts/.
 *
 * Required env vars: TAXCLOUD_API_ID, TAXCLOUD_API_KEY
 *
 * --products-only seeds ONLY the test category and the catalog above (plus the
 * reindex and cache flush that make them visible). It writes no configuration,
 * no admin user, no customer and no second website — which is what makes it
 * safe to point at a working development store, where the rest of this script
 * would overwrite live credentials, reset the admin password, rewrite every URL
 * via web/url/use_store, and disable stock management globally. The API
 * credentials are not required in this mode, since nothing reads them.
 *
 * --recreate deletes the seeded products before rebuilding them. Everything
 * here is idempotent by SKU, which means an existing product is left ALONE —
 * so a correction to how a product is built never reaches a store that already
 * has the old one. That is what this flag is for. Narrow it to the type you are
 * iterating on with --recreate=test-giftcard,test-bundle-fixed; with no list it
 * drops the whole seeded catalog.
 *
 * Deleting is not free of consequences on a store with its own content: a
 * hand-built bundle or grouped product that uses a seeded SKU as one of its
 * selections loses that selection. Pass the list when that matters.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

error_reporting(E_ALL);

// --- Locate and bootstrap Magento ------------------------------------------

// Catalog only: leave this store's configuration, admin user, customers and
// scope tree exactly as they are. See the usage note above for why.
$options = array_values(array_filter($argv, static fn ($arg) => str_starts_with((string) $arg, '--')));
$positional = array_values(array_filter(
    array_slice($argv, 1),
    static fn ($arg) => !str_starts_with((string) $arg, '--')
));
$productsOnly = in_array('--products-only', $options, true);

// --recreate, or --recreate=sku,sku for a subset. null = not requested.
$recreateSkus = null;
foreach ($options as $option) {
    if ($option === '--recreate') {
        $recreateSkus = [];
    } elseif (str_starts_with($option, '--recreate=')) {
        $recreateSkus = array_values(array_filter(array_map('trim', explode(',', substr($option, 11)))));
    }
}

$candidates = array_filter([
    $positional[0] ?? null,
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

$apiId    = getenv('TAXCLOUD_API_ID');
$apiKey   = getenv('TAXCLOUD_API_KEY');
$apiV3Key = getenv('TAXCLOUD_API_V3_KEY');
if (!$productsOnly && (!$apiId || !$apiKey || !$apiV3Key)) {
    fwrite(STDERR, "ERROR: TAXCLOUD_API_ID, TAXCLOUD_API_KEY and TAXCLOUD_API_V3_KEY must be set in the environment.\n");
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

if ($productsOnly) {
    // Resetting an existing admin's password is exactly the kind of damage
    // --products-only exists to avoid.
    $step('skipped admin user (--products-only)');
} else {
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
}

// --- 2. System configuration -------------------------------------------------

// Empty in catalog-only mode, so the loop below writes nothing at all.
$configValues = $productsOnly ? [] : [
    'tax/taxcloud_settings/enabled'        => '1',
    'tax/taxcloud_settings/logging'        => '1',
    'tax/taxcloud_settings/verify_address' => '1',
    'tax/taxcloud_settings/api_id'         => $apiId,
    'tax/taxcloud_settings/api_key'        => $apiKey,
    // The sandbox simulates a legacy SOAP install. On real upgrades the
    // PinSoapApiTypeForExistingInstalls patch writes this row; here the
    // credentials are seeded after setup:upgrade, so the patch saw a fresh
    // install and pinned nothing — seed the pin explicitly instead.
    'tax/taxcloud_settings/api_type'       => 'soap',
    // v3 REST connection: the v1 apiKey IS the v3 connection ID (verified
    // against the live API), so the REST transport is fully configured from
    // the same credential pair. REST-path tests flip api_type per scope.
    'tax/taxcloud_settings/rest_connection_id' => $apiKey,
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

    // Magento blocks guest checkout outright for any cart holding a downloadable
    // product (default on), which would leave the seeded downloadable reachable
    // only through a logged-in journey while every other type is testable as a
    // guest. Off here so one checkout flow covers the whole catalog.
    'catalog/downloadable/disable_guest_checkout' => '0',

    // Store code in URLs so the default and second store (section 4i) are both
    // reachable on the same base URL as /default/... and /second/... — no extra
    // vhost/DNS needed for multi-store E2E navigation.
    'web/url/use_store' => '1',

    // Take stock out of the equation for the test catalog. Product salability
    // (Quote::addProduct -> isSalable()) otherwise depends on the MSI salable-qty
    // index, which is populated differently across editions/versions (Enterprise
    // and 2.4.7 left seeded products "not available" even after a full reindex).
    // With manage_stock off, products are salable regardless of the index, so the
    // same seed behaves identically on every matrix row.
    'cataloginventory/item_options/manage_stock' => '0',
];

// Stored encrypted by the field's backend model (PreparedValueFactory
// below honors it, exactly like an admin save). With a saved key the REST
// path authenticates with X-API-KEY — the primary mode under test; the
// Bearer-via-exchange fallback keeps only basic connectivity coverage.
//
// Guarded like every other config write: --products-only must leave the store's
// credentials alone, and in that mode TAXCLOUD_API_V3_KEY is not even required,
// so an unguarded write here would blank a working key with an empty string.
if (!$productsOnly) {
    $configValues['tax/taxcloud_settings/rest_api_key'] = $apiV3Key;
}

// Go through PreparedValueFactory (what `bin/magento config:set` uses) so any
// backend model attached to a field (e.g. encrypted values) is honored.
$preparedValueFactory = $om->get(\Magento\Config\Model\PreparedValueFactory::class);
$secretPaths = [
    'tax/taxcloud_settings/api_key',
    'tax/taxcloud_settings/rest_api_key',
    // Same value as api_key, so masked for the same reason.
    'tax/taxcloud_settings/rest_connection_id',
];
if ($productsOnly) {
    $step('skipped all system configuration (--products-only)');
}
foreach ($configValues as $path => $value) {
    $backendModel = $preparedValueFactory->create(
        $path,
        $value,
        \Magento\Framework\App\Config\ScopeConfigInterface::SCOPE_TYPE_DEFAULT
    );
    if ($backendModel instanceof \Magento\Framework\App\Config\Value) {
        $backendModel->getResource()->save($backendModel);
    }
    $shown = in_array($path, $secretPaths, true) ? '***' : $value;
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

// --- 4-pre. Drop seeded products so they are rebuilt -------------------------
//
// Composites first: deleting a selection out from under its parent leaves the
// parent referencing a product that no longer exists.
if ($recreateSkus !== null) {
    $seededSkus = [
        'test-bundle-dynamic',
        'test-bundle-fixed',
        'test-grouped',
        'test-configurable',
        'test-variant-red',
        'test-variant-blue',
        'test-giftcard',
        'test-downloadable',
        'test-virtual',
        'test-product',
    ];
    $targets = $recreateSkus === []
        ? $seededSkus
        : array_values(array_intersect($seededSkus, $recreateSkus));

    if ($unknown = array_diff($recreateSkus, $seededSkus)) {
        // Refuse to delete anything this script did not create.
        fwrite(STDERR, 'ERROR: --recreate only accepts SKUs this seed owns; not seeded: '
            . implode(', ', $unknown) . "\n");
        exit(1);
    }

    // deleteById() on a catalog product needs the secure area, the same guard
    // the admin product grid sets before a mass delete.
    $om->get(\Magento\Framework\Registry::class)->register('isSecureArea', true, true);

    $connection = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
    $quoteItemTable = $connection->getTableName('quote_item');

    foreach ($targets as $sku) {
        try {
            $productId = (int) $productRepository->get($sku)->getId();
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            continue; // Never existed here; nothing to rebuild from.
        }

        // Take this product out of every cart BEFORE deleting it. Magento drops
        // a quote line whose product has vanished, but it does not drop that
        // line's CHILDREN — they keep a parent_item_id pointing at nothing. The
        // orphan survives quietly until checkout, where resolveItems() hands the
        // missing parent to ToOrderItem::convert() and the order dies on
        // "Argument #2 ($quoteItem) must be of type AbstractItem, null given".
        // Deleting a bundle or configurable that is sitting in a live cart is
        // exactly how that happens.
        $lineIds = $connection->fetchCol(
            $connection->select()->from($quoteItemTable, 'item_id')->where('product_id = ?', $productId)
        );
        if ($lineIds) {
            $connection->delete($quoteItemTable, ['parent_item_id IN (?)' => $lineIds]);
            $connection->delete($quoteItemTable, ['item_id IN (?)' => $lineIds]);
            $step('removed "' . $sku . '" from ' . count($lineIds) . ' cart line(s) before deleting it');
        }

        $productRepository->deleteById($sku);
        $step('deleted "' . $sku . '" (--recreate)');
    }
}

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

// --- 4b. Configurable test product (two color variants, distinct TICs) -------
//
// A configurable product with two simple variants that carry different TaxCloud
// TICs (Red = 20010, Blue = 00000); the parent carries none. Used by integration
// and e2e tests that need real configurable handling — e.g. proving the chosen
// variant's taxcloud_tic (not the parent's) flows into the TaxCloud lookup.
// Idempotent like everything else here.

const CONFIGURABLE_SKU       = 'test-configurable';
const CONFIGURABLE_ATTRIBUTE = 'test_variant_color';
const VARIANT_RED_SKU        = 'test-variant-red';
const VARIANT_BLUE_SKU       = 'test-variant-blue';
const VARIANT_RED_TIC        = '20010';
const VARIANT_BLUE_TIC       = '00000';

$eavConfig = $om->get(\Magento\Eav\Model\Config::class);
$colorAttribute = $eavConfig->getAttribute(\Magento\Catalog\Model\Product::ENTITY, CONFIGURABLE_ATTRIBUTE);

if (!$colorAttribute || !$colorAttribute->getAttributeId()) {
    /** @var \Magento\Eav\Setup\EavSetup $eavSetup */
    $eavSetup = $om->create(\Magento\Eav\Setup\EavSetup::class);
    $configAttrSetId = (int) $eavSetup->getAttributeSetId(\Magento\Catalog\Model\Product::ENTITY, 'Default');
    $configGroupId = (int) $eavSetup->getDefaultAttributeGroupId(
        \Magento\Catalog\Model\Product::ENTITY,
        $configAttrSetId
    );

    $attribute = $om->get(\Magento\Catalog\Api\Data\ProductAttributeInterfaceFactory::class)->create();
    $attribute->setData([
        'attribute_code' => CONFIGURABLE_ATTRIBUTE,
        'entity_type_id' => $eavSetup->getEntityTypeId(\Magento\Catalog\Model\Product::ENTITY),
        'is_global' => 1,
        'is_user_defined' => 1,
        'frontend_input' => 'select',
        'is_required' => 0,
        'frontend_label' => ['Test Variant Color'],
        'backend_type' => 'int',
        'option' => [
            'value' => ['opt_red' => ['Red'], 'opt_blue' => ['Blue']],
            'order' => ['opt_red' => 1, 'opt_blue' => 2],
        ],
    ]);
    $attribute = $om->get(\Magento\Catalog\Api\ProductAttributeRepositoryInterface::class)->save($attribute);
    $eavSetup->addAttributeToGroup(
        \Magento\Catalog\Model\Product::ENTITY,
        $configAttrSetId,
        $configGroupId,
        $attribute->getId()
    );
    $eavConfig->clear();
    $colorAttribute = $eavConfig->getAttribute(\Magento\Catalog\Model\Product::ENTITY, CONFIGURABLE_ATTRIBUTE);
    $step('attribute "' . CONFIGURABLE_ATTRIBUTE . '" created (id ' . $colorAttribute->getId() . ')');
} else {
    $step('attribute "' . CONFIGURABLE_ATTRIBUTE . '" already exists (id ' . $colorAttribute->getId() . ')');
}

$configAttributeId = (int) $colorAttribute->getId();
$valueByLabel = [];
foreach ($colorAttribute->getOptions() as $opt) {
    if ($opt->getLabel() !== null && $opt->getLabel() !== '') {
        $valueByLabel[$opt->getLabel()] = (int) $opt->getValue();
    }
}
$redValueIndex = $valueByLabel['Red'];
$blueValueIndex = $valueByLabel['Blue'];

$variantAttributeSetId = (int) $om->get(\Magento\Eav\Model\Config::class)
    ->getEntityType(\Magento\Catalog\Model\Product::ENTITY)
    ->getDefaultAttributeSetId();
$variantWebsiteId = (int) $storeManager->getDefaultStoreView()->getWebsiteId();

$ensureVariant = function (string $sku, string $name, int $valueIndex, string $tic) use (
    $om,
    $productRepository,
    $variantAttributeSetId,
    $variantWebsiteId,
    $step
): int {
    try {
        $existing = $productRepository->get($sku);
        $step('variant "' . $sku . '" already exists');
        return (int) $existing->getId();
    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
        // fall through and create
    }

    /** @var \Magento\Catalog\Model\Product $variant */
    $variant = $om->create(\Magento\Catalog\Model\ProductFactory::class)->create();
    $variant->setSku($sku)
        ->setName($name)
        ->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE)
        ->setAttributeSetId($variantAttributeSetId)
        ->setPrice(10.00)
        ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
        ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_NOT_VISIBLE)
        ->setWebsiteIds([$variantWebsiteId])
        ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_in_stock' => 1])
        ->setData(CONFIGURABLE_ATTRIBUTE, $valueIndex)
        ->setData('taxcloud_tic', $tic);
    $saved = $productRepository->save($variant);
    $step('variant "' . $sku . '" created (tic ' . $tic . ')');
    return (int) $saved->getId();
};

$redVariantId = $ensureVariant(VARIANT_RED_SKU, 'Test Variant Red', $redValueIndex, VARIANT_RED_TIC);
$blueVariantId = $ensureVariant(VARIANT_BLUE_SKU, 'Test Variant Blue', $blueValueIndex, VARIANT_BLUE_TIC);

try {
    $productRepository->get(CONFIGURABLE_SKU);
    $step('configurable "' . CONFIGURABLE_SKU . '" already exists');
} catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
    $optionsFactory = $om->create(\Magento\ConfigurableProduct\Helper\Product\Options\Factory::class);
    $configurableOptions = $optionsFactory->create([
        [
            'attribute_id' => $configAttributeId,
            'code' => CONFIGURABLE_ATTRIBUTE,
            'label' => $colorAttribute->getStoreLabel(),
            'position' => '0',
            'values' => [
                ['label' => 'Red', 'attribute_id' => $configAttributeId, 'value_index' => $redValueIndex],
                ['label' => 'Blue', 'attribute_id' => $configAttributeId, 'value_index' => $blueValueIndex],
            ],
        ],
    ]);

    /** @var \Magento\Catalog\Model\Product $configurable */
    $configurable = $om->create(\Magento\Catalog\Model\ProductFactory::class)->create();
    $configurable->setSku(CONFIGURABLE_SKU)
        ->setName('Test Configurable')
        ->setTypeId(\Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE)
        ->setAttributeSetId($variantAttributeSetId)
        ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
        ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
        ->setWebsiteIds([$variantWebsiteId])
        ->setStockData(['use_config_manage_stock' => 1, 'is_in_stock' => 1]);

    $extension = $configurable->getExtensionAttributes();
    $extension->setConfigurableProductOptions($configurableOptions);
    $extension->setConfigurableProductLinks([$redVariantId, $blueVariantId]);
    $configurable->setExtensionAttributes($extension);

    $productRepository->save($configurable);
    $step('configurable "' . CONFIGURABLE_SKU . '" created (variants: '
        . VARIANT_RED_SKU . ', ' . VARIANT_BLUE_SKU . ')');
}

$om->get(\Magento\Catalog\Api\CategoryLinkManagementInterface::class)
    ->assignProductToCategories(CONFIGURABLE_SKU, [$categoryId]);
$step('configurable "' . CONFIGURABLE_SKU . "\" assigned to category $categoryId");

// NOTE: enabling the products does NOT happen here. On Adobe Commerce (Content
// Staging), the status set on a just-created product does not persist within the
// same process — products land DISABLED and Quote::addProduct() rejects them.
// scripts/enable-test-products.php fixes this from a FRESH process; the installer
// runs it right after this seed. If you run this seed standalone on Enterprise,
// run that script afterward (it's a harmless no-op on Open Source).

// --- 4c. One product per remaining type --------------------------------------
//
// Simple and configurable are covered above. The rest of Magento's catalog
// types get one product each, so tax behaviour can be exercised against every
// shape a quote line can take:
//
//   virtual / downloadable  — no shipping, so a cart of only these is assigned
//                             to the BILLING address (Quote\Address::getAllItems)
//   grouped                 — explodes into one independent line per associated
//                             product; no parent line ever reaches the quote
//   bundle (dynamic)        — the selections carry the price, and their quote qty
//                             is stored PER BUNDLE; the parent is a wrapper
//   bundle (fixed)          — the inverse: the parent carries the price and the
//                             selections are priced at 0
//   giftcard (Commerce)     — priced by the chosen amount; seeded only where the
//                             module exists
//
// Every product is $10 (gift card $25) so expected tax stays easy to reason
// about, and none carries its own TIC — they resolve through the store default
// (20000), which keeps these lines independent of the TIC tests above.

$defaultAttributeSetId = (int) $om->get(\Magento\Eav\Model\Config::class)
    ->getEntityType(\Magento\Catalog\Model\Product::ENTITY)
    ->getDefaultAttributeSetId();
$defaultWebsiteId = (int) $storeManager->getDefaultStoreView()->getWebsiteId();

/**
 * A URL key for a seeded product that no existing product or category has
 * already claimed.
 *
 * On a fresh install the SKU is free and becomes the key verbatim. Pointed at a
 * store that already has its own catalog — which is the whole point of
 * --products-only — a name collision is entirely possible, and Magento answers
 * it with a bare "URL key for specified store already exists" that aborts the
 * seed partway through. Stepping aside is better than failing.
 */
$uniqueUrlKey = function (string $sku) use ($om, $step): string {
    $connection = $om->get(\Magento\Framework\App\ResourceConnection::class);
    $table = $connection->getTableName('url_rewrite');

    $taken = (bool) $connection->getConnection()->fetchOne(
        $connection->getConnection()
            ->select()
            ->from($table, 'url_rewrite_id')
            ->where('request_path = ?', $sku . '.html')
            ->limit(1)
    );

    if (!$taken) {
        return $sku;
    }

    $step('url key "' . $sku . '" is taken; seeding as "' . $sku . '-taxcloud"');

    return $sku . '-taxcloud';
};

/**
 * Create a product unless its SKU already exists, letting the caller shape the
 * type-specific parts. Returns the product id either way. $configure receives
 * the unsaved product and may return a replacement to save.
 */
$ensureProduct = function (
    string $sku,
    string $name,
    string $typeId,
    ?float $price,
    ?callable $configure = null
) use (
    $om,
    $productRepository,
    $defaultAttributeSetId,
    $defaultWebsiteId,
    $categoryId,
    $uniqueUrlKey,
    $step
): int {
    try {
        $existing = $productRepository->get($sku);
        $step('product "' . $sku . '" already exists (' . $typeId . ')');
        $om->get(\Magento\Catalog\Api\CategoryLinkManagementInterface::class)
            ->assignProductToCategories($sku, [$categoryId]);
        return (int) $existing->getId();
    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
        // fall through and create
    }

    /** @var \Magento\Catalog\Model\Product $product */
    $product = $om->create(\Magento\Catalog\Model\ProductFactory::class)->create();
    $product->setSku($sku)
        ->setName($name)
        ->setUrlKey($uniqueUrlKey($sku))
        ->setTypeId($typeId)
        ->setAttributeSetId($defaultAttributeSetId)
        ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
        ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
        ->setWebsiteIds([$defaultWebsiteId])
        ->setStockData(['use_config_manage_stock' => 1, 'qty' => 100, 'is_in_stock' => 1]);

    if ($price !== null) {
        $product->setPrice($price);
    }

    if ($configure !== null) {
        $product = $configure($product) ?? $product;
    }

    $saved = $productRepository->save($product);
    $step('product "' . $sku . '" created (' . $typeId
        . ($price !== null ? ', $' . number_format($price, 2) : '') . ')');

    $om->get(\Magento\Catalog\Api\CategoryLinkManagementInterface::class)
        ->assignProductToCategories($sku, [$categoryId]);

    return (int) $saved->getId();
};

// Virtual — no weight, no shipment.
const VIRTUAL_SKU = 'test-virtual';
$ensureProduct(VIRTUAL_SKU, 'Test Virtual', \Magento\Catalog\Model\Product\Type::TYPE_VIRTUAL, 10.00);

// Downloadable — one URL link, included in the product price (links are not
// purchased separately), so the line's price is a flat $10 like everything else.
//
// A URL link is rejected unless its host is listed in env.php's
// downloadable_domains, so register it first — the same thing
// `bin/magento downloadable:domains:add` does. addDomains() merges, so this is
// idempotent.
const DOWNLOADABLE_SKU    = 'test-downloadable';
const DOWNLOADABLE_DOMAIN = 'example.com';

$om->get(\Magento\Downloadable\Api\DomainManagerInterface::class)
    ->addDomains([DOWNLOADABLE_DOMAIN]);
$step('downloadable domain "' . DOWNLOADABLE_DOMAIN . '" whitelisted in env.php');

$ensureProduct(
    DOWNLOADABLE_SKU,
    'Test Downloadable',
    \Magento\Downloadable\Model\Product\Type::TYPE_DOWNLOADABLE,
    10.00,
    function ($product) use ($om) {
        /** @var \Magento\Downloadable\Api\Data\LinkInterface $link */
        $link = $om->create(\Magento\Downloadable\Api\Data\LinkInterfaceFactory::class)->create();
        // Shareable explicitly, not "use config". Magento refuses guest checkout
        // for any cart holding a downloadable whose links are not shareable, and
        // the store default is not shareable — so LINK_SHAREABLE_CONFIG would
        // make this product reachable only through a logged-in checkout while
        // every other seeded type is testable as a guest.
        $link->setTitle('Test Download')
            ->setPrice(0)
            ->setNumberOfDownloads(0) // unlimited
            ->setIsShareable(\Magento\Downloadable\Model\Link::LINK_SHAREABLE_YES)
            ->setLinkType(\Magento\Downloadable\Helper\Download::LINK_TYPE_URL)
            ->setLinkUrl('https://example.com/taxcloud-test-download.txt')
            ->setSortOrder(1);

        $product->setLinksTitle('Test Downloads')
            ->setLinksPurchasedSeparately(false);

        $extension = $product->getExtensionAttributes();
        $extension->setDownloadableProductLinks([$link]);
        $product->setExtensionAttributes($extension);

        return $product;
    }
);

// Grouped — associates the simple and the virtual. Magento never puts a grouped
// product itself in the quote: each association becomes its own top-level line
// with its own absolute qty, which is exactly what makes it uninteresting to
// composite tax logic and worth pinning.
const GROUPED_SKU = 'test-grouped';
$ensureProduct(
    GROUPED_SKU,
    'Test Grouped',
    \Magento\GroupedProduct\Model\Product\Type\Grouped::TYPE_CODE,
    null,
    function ($product) use ($om) {
        $associations = [
            [PRODUCT_SKU, \Magento\Catalog\Model\Product\Type::TYPE_SIMPLE, 1, 1],
            [VIRTUAL_SKU, \Magento\Catalog\Model\Product\Type::TYPE_VIRTUAL, 1, 2],
        ];

        $links = [];
        foreach ($associations as [$linkedSku, $linkedType, $qty, $position]) {
            /** @var \Magento\Catalog\Api\Data\ProductLinkInterface $link */
            $link = $om->create(\Magento\Catalog\Api\Data\ProductLinkInterfaceFactory::class)->create();
            $link->setSku(GROUPED_SKU)
                ->setLinkType('associated')
                ->setLinkedProductSku($linkedSku)
                ->setLinkedProductType($linkedType)
                ->setPosition($position);

            // qty lives on the link's extension attributes, and the getter
            // returns null until one is created.
            $linkExtension = $link->getExtensionAttributes()
                ?: $om->create(\Magento\Catalog\Api\Data\ProductLinkExtensionFactory::class)->create();
            $linkExtension->setQty($qty);
            $link->setExtensionAttributes($linkExtension);

            $links[] = $link;
        }

        $product->setProductLinks($links);

        return $product;
    }
);

// Bundles. Selections are built the same way for both; only the price type and
// what carries the price differ.
const BUNDLE_DYNAMIC_SKU = 'test-bundle-dynamic';
const BUNDLE_FIXED_SKU   = 'test-bundle-fixed';
const BUNDLE_FIXED_PRICE = 50.00;

/**
 * One required checkbox option holding the given selections, both checked by
 * default so Add to Cart works on the storefront without any interaction.
 *
 * @param array $selections [sku, qty] pairs
 */
$bundleOption = function (string $parentSku, string $title, array $selections, int $priceType) use ($om) {
    $links = [];
    $position = 1;
    foreach ($selections as [$selectionSku, $selectionQty]) {
        /** @var \Magento\Bundle\Api\Data\LinkInterface $link */
        $link = $om->create(\Magento\Bundle\Api\Data\LinkInterfaceFactory::class)->create();
        $link->setSku($selectionSku)
            ->setQty($selectionQty)
            ->setCanChangeQuantity(0)
            ->setIsDefault(true)
            ->setPosition($position++)
            ->setPriceType($priceType)
            ->setPrice(0.00);
        $links[] = $link;
    }

    /** @var \Magento\Bundle\Api\Data\OptionInterface $option */
    $option = $om->create(\Magento\Bundle\Api\Data\OptionInterfaceFactory::class)->create();
    $option->setTitle($title)
        ->setType('checkbox')
        ->setRequired(true)
        ->setPosition(1)
        ->setSku($parentSku)
        ->setProductLinks($links);

    return $option;
};

// Dynamic: the parent has no price of its own — it is the sum of the selections,
// $10 + (2 x $10) = $30 per bundle. The qty-2 selection is deliberate: its quote
// line stores qty 2 per bundle, so a qty-3 cart has to reach TaxCloud as 6.
$ensureProduct(
    BUNDLE_DYNAMIC_SKU,
    'Test Bundle Dynamic',
    \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE,
    null,
    function ($product) use ($om, $bundleOption) {
        $product->setPriceType(\Magento\Bundle\Model\Product\Price::PRICE_TYPE_DYNAMIC)
            ->setSkuType(0)      // dynamic sku: parent sku grows the selection skus
            ->setWeightType(0)   // dynamic weight
            ->setPriceView(0)    // price range
            ->setShipmentType(\Magento\Bundle\Model\Product\Type::SHIPMENT_TOGETHER);

        $extension = $product->getExtensionAttributes();
        $extension->setBundleProductOptions([
            $bundleOption(
                BUNDLE_DYNAMIC_SKU,
                'Dynamic Selections',
                [[PRODUCT_SKU, 1], [VIRTUAL_SKU, 2]],
                \Magento\Bundle\Model\Product\Price::PRICE_TYPE_DYNAMIC
            ),
        ]);
        $product->setExtensionAttributes($extension);

        return $product;
    }
);

// Fixed: the control case. The parent carries the whole $50 and the selections
// are priced at 0, so Magento maps only the parent into the tax details.
$ensureProduct(
    BUNDLE_FIXED_SKU,
    'Test Bundle Fixed',
    \Magento\Catalog\Model\Product\Type::TYPE_BUNDLE,
    BUNDLE_FIXED_PRICE,
    function ($product) use ($om, $bundleOption) {
        $product->setPriceType(\Magento\Bundle\Model\Product\Price::PRICE_TYPE_FIXED)
            ->setSkuType(1)      // fixed sku: the parent sku is the order sku
            ->setWeightType(1)
            ->setWeight(1)
            ->setPriceView(0)
            ->setShipmentType(\Magento\Bundle\Model\Product\Type::SHIPMENT_TOGETHER);

        $extension = $product->getExtensionAttributes();
        $extension->setBundleProductOptions([
            $bundleOption(
                BUNDLE_FIXED_SKU,
                'Fixed Selections',
                [[PRODUCT_SKU, 1]],
                \Magento\Bundle\Model\Product\Price::PRICE_TYPE_FIXED
            ),
        ]);
        $product->setExtensionAttributes($extension);

        return $product;
    }
);

// Gift card — Adobe Commerce only. Guarded on the module being both present and
// enabled, so this whole block is a no-op on Open Source rather than a fatal.
const GIFTCARD_SKU    = 'test-giftcard';
const GIFTCARD_AMOUNT = 25.00;

$giftcardSeeded = false;
if ($om->get(\Magento\Framework\Module\Manager::class)->isEnabled('Magento_GiftCard')
    && class_exists(\Magento\GiftCard\Model\Catalog\Product\Type\Giftcard::class)
) {
    $ensureProduct(
        GIFTCARD_SKU,
        'Test Gift Card',
        \Magento\GiftCard\Model\Catalog\Product\Type\Giftcard::TYPE_GIFTCARD,
        null,
        function ($product) use ($om) {
            // Virtual: emailed, so it never adds a shippable line.
            $product->setGiftcardType(\Magento\GiftCard\Model\Giftcard::TYPE_VIRTUAL)
                ->setAllowOpenAmount(\Magento\GiftCard\Model\Giftcard::OPEN_AMOUNT_DISABLED)
                ->setUseConfigIsRedeemable(1)
                ->setUseConfigLifetime(1)
                ->setUseConfigAllowMessage(1)
                ->setUseConfigEmailTemplate(1);

            // Amounts go on the EXTENSION attributes, not setGiftcardAmounts():
            // GiftCard\Model\Product\SaveHandler reads
            // getExtensionAttributes()->getGiftcardAmounts() and ignores the data
            // array entirely, so a plain setter is dropped without complaint —
            // and a card with neither a stored amount nor an open amount reports
            // itself unsalable, failing add to cart with a bare "Product that you
            // are trying to add is not available."
            //
            // website_id 0 is the "All Websites" scope the backend model requires.
            /** @var \Magento\GiftCard\Api\Data\GiftcardAmountInterface $amount */
            $amount = $om->create(\Magento\GiftCard\Api\Data\GiftcardAmountInterfaceFactory::class)->create();
            $amount->setWebsiteId(0)->setValue(GIFTCARD_AMOUNT);

            $extension = $product->getExtensionAttributes();
            $extension->setGiftcardAmounts([$amount]);
            $product->setExtensionAttributes($extension);

            return $product;
        }
    );
    $giftcardSeeded = true;
} else {
    $step('gift card skipped (Magento_GiftCard not available — Open Source)');
}

// --- 4h. Registered customer + default address ------------------------------
//
// A storefront customer with a default shipping/billing address, for E2E tests
// that exercise logged-in flows (e.g. tax shown in the cart before checkout).
// Created via CustomerRepository with a pre-hashed password so NO welcome email
// is dispatched — the test stack has no working mail transport, and
// AccountManagement::createAccount() would try to send one. Integration tests
// use guest quotes and are unaffected. Idempotent.

const CUSTOMER_EMAIL    = 'customer@example.com';
const CUSTOMER_PASSWORD = 'Test1234!';

$customerRepository = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class);

// Customer accounts are scoped PER WEBSITE (customer/account_share/scope
// default). The same email on two websites is two independent accounts, so the
// second store (section 4i) gets its own record with the SAME credentials —
// tests log in with one email/password everywhere and the website context
// picks the account. Callable per store view; idempotent per website.
$ensureCustomer = function (\Magento\Store\Api\Data\StoreInterface $store) use (
    $om,
    $customerRepository,
    $step
): void {
    $websiteId = (int) $store->getWebsiteId();
    try {
        $customerRepository->get(CUSTOMER_EMAIL, $websiteId);
        $step('customer "' . CUSTOMER_EMAIL . "\" already exists (website $websiteId)");
        return;
    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
        // fall through and create
    }

    /** @var \Magento\Customer\Api\Data\CustomerInterface $customer */
    $customer = $om->create(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class)->create();
    $customer->setWebsiteId($websiteId)
        ->setStoreId((int) $store->getId())
        ->setEmail(CUSTOMER_EMAIL)
        ->setFirstname('Test')
        ->setLastname('Customer')
        ->setGroupId(1); // General

    // save($customer, $passwordHash) sets the password directly, bypassing the
    // welcome email that AccountManagement::createAccount() would send.
    $passwordHash = $om->get(\Magento\Framework\Encryption\EncryptorInterface::class)
        ->getHash(CUSTOMER_PASSWORD, true);
    $customer = $customerRepository->save($customer, $passwordHash);
    $step('customer "' . CUSTOMER_EMAIL . '" created (website ' . $websiteId
        . ', password ' . CUSTOMER_PASSWORD . ')');

    // Default shipping + billing: Austin TX 78701 — in-state with the seeded
    // origin, so a logged-in customer's cart computes deterministic in-state tax.
    $region = $om->create(\Magento\Customer\Api\Data\RegionInterfaceFactory::class)->create();
    $region->setRegionId(57)->setRegionCode('TX')->setRegion('Texas');

    /** @var \Magento\Customer\Api\Data\AddressInterface $address */
    $address = $om->create(\Magento\Customer\Api\Data\AddressInterfaceFactory::class)->create();
    $address->setFirstname('Test')
        ->setLastname('Customer')
        ->setStreet(['1401 Lavaca St'])
        ->setCity('Austin')
        ->setCountryId('US')
        ->setRegion($region)
        ->setRegionId(57)
        ->setPostcode('78701')
        ->setTelephone('5125550100')
        ->setCustomerId((int) $customer->getId())
        ->setIsDefaultBilling(true)
        ->setIsDefaultShipping(true);
    $om->get(\Magento\Customer\Api\AddressRepositoryInterface::class)->save($address);
    $step("customer default address set (1401 Lavaca St, Austin TX 78701; website $websiteId)");
};

// getDefaultStoreView(), NOT getStore(): the current store was pinned to the
// admin scope at bootstrap, and a customer created there (website_id 0) can't
// log in on any storefront under per-website account sharing.
if ($productsOnly) {
    $step('skipped test customer (--products-only)');
} else {
    $ensureCustomer($storeManager->getDefaultStoreView());
}

// --- 4i. Second website / store group / store view (multi-store) -------------
//
// Skipped by --products-only: a second website is a structural change to the
// store, not a test fixture.

// Declared outside the guard below: const is only legal at the top level, and
// the closing summary reads these whether or not the scope tree was built.
const SECOND_WEBSITE_CODE = 'second';
const SECOND_GROUP_CODE   = 'second';
const SECOND_STORE_CODE   = 'second';

if ($productsOnly) {
    $step('skipped second website / store view (--products-only)');
} else {
    //
    // A complete second scope tree (website "second" -> group "second" -> store
    // view "second") sharing the main root category, so the same test catalog is
    // browsable on both stores. Combined with web/url/use_store=1 (section 2) the
    // storefronts live on one base URL as /default/... and /second/....
    //
    // TaxCloud is explicitly DISABLED on the second store via a store-scope config
    // override (the module reads tax/taxcloud_settings/enabled at SCOPE_STORE, see
    // Model/Tax.php), so multi-store tests can prove scope isolation: carts on
    // "second" must fall back to native Magento tax, never hitting TaxCloud.
    //
    // Saving through the resource models (not raw SQL) fires the real plugins and
    // events: MSI links the new website to the Default Stock, and Magento_Sales
    // creates the sales sequence tables for the new store. Idempotent.

    /** @var \Magento\Store\Model\ResourceModel\Website $websiteResource */
    $websiteResource = $om->get(\Magento\Store\Model\ResourceModel\Website::class);
    /** @var \Magento\Store\Model\Website $secondWebsite */
    $secondWebsite = $om->create(\Magento\Store\Model\WebsiteFactory::class)->create();
    $websiteResource->load($secondWebsite, SECOND_WEBSITE_CODE, 'code');
    if ($secondWebsite->getId()) {
        $step('website "' . SECOND_WEBSITE_CODE . '" already exists (id ' . $secondWebsite->getId() . ')');
    } else {
        $secondWebsite->setCode(SECOND_WEBSITE_CODE)
            ->setName('Second Website')
            ->setSortOrder(10);
        $websiteResource->save($secondWebsite);
        $step('website "' . SECOND_WEBSITE_CODE . '" created (id ' . $secondWebsite->getId() . ')');
    }

    /** @var \Magento\Store\Model\ResourceModel\Group $groupResource */
    $groupResource = $om->get(\Magento\Store\Model\ResourceModel\Group::class);
    /** @var \Magento\Store\Model\Group $secondGroup */
    $secondGroup = $om->create(\Magento\Store\Model\GroupFactory::class)->create();
    $groupResource->load($secondGroup, SECOND_GROUP_CODE, 'code');
    if ($secondGroup->getId()) {
        $step('store group "' . SECOND_GROUP_CODE . '" already exists (id ' . $secondGroup->getId() . ')');
    } else {
        $secondGroup->setWebsiteId((int) $secondWebsite->getId())
            ->setCode(SECOND_GROUP_CODE)
            ->setName('Second Store')
            ->setRootCategoryId($rootCategoryId);
        $groupResource->save($secondGroup);
        $step('store group "' . SECOND_GROUP_CODE . '" created (id ' . $secondGroup->getId()
            . ", root category $rootCategoryId)");
    }

    /** @var \Magento\Store\Model\ResourceModel\Store $storeResource */
    $storeResource = $om->get(\Magento\Store\Model\ResourceModel\Store::class);
    /** @var \Magento\Store\Model\Store $secondStore */
    $secondStore = $om->create(\Magento\Store\Model\StoreFactory::class)->create();
    $storeResource->load($secondStore, SECOND_STORE_CODE, 'code');
    if ($secondStore->getId()) {
        $step('store view "' . SECOND_STORE_CODE . '" already exists (id ' . $secondStore->getId() . ')');
    } else {
        $secondStore->setCode(SECOND_STORE_CODE)
            ->setName('Second Store View')
            ->setWebsiteId((int) $secondWebsite->getId())
            ->setGroupId((int) $secondGroup->getId())
            ->setSortOrder(10)
            ->setIsActive(1);
        $storeResource->save($secondStore);
        $step('store view "' . SECOND_STORE_CODE . '" created (id ' . $secondStore->getId() . ')');
    }

    // Wire the defaults so the new scope tree is fully navigable.
    if ((int) $secondGroup->getDefaultStoreId() !== (int) $secondStore->getId()) {
        $secondGroup->setDefaultStoreId((int) $secondStore->getId());
        $groupResource->save($secondGroup);
    }
    if ((int) $secondWebsite->getDefaultGroupId() !== (int) $secondGroup->getId()) {
        $secondWebsite->setDefaultGroupId((int) $secondGroup->getId());
        $websiteResource->save($secondWebsite);
    }
    $step('scope tree wired: website "' . SECOND_WEBSITE_CODE . '" -> group "'
        . SECOND_GROUP_CODE . '" -> store "' . SECOND_STORE_CODE . '"');

    // Make the whole test catalog visible on the second website too.
    $secondWebsiteId = (int) $secondWebsite->getId();
    $catalogSkus = [
        PRODUCT_SKU,
        CONFIGURABLE_SKU,
        VARIANT_RED_SKU,
        VARIANT_BLUE_SKU,
        VIRTUAL_SKU,
        DOWNLOADABLE_SKU,
        GROUPED_SKU,
        BUNDLE_DYNAMIC_SKU,
        BUNDLE_FIXED_SKU,
    ];
    if ($giftcardSeeded) {
        $catalogSkus[] = GIFTCARD_SKU;
    }
    $catalogIds = [];
    foreach ($catalogSkus as $sku) {
        $catalogIds[] = (int) $productRepository->get($sku)->getId();
    }
    $om->get(\Magento\Catalog\Model\Product\Action::class)
        ->updateWebsites($catalogIds, [$secondWebsiteId], 'add');
    $step('test catalog (' . implode(', ', $catalogSkus) . ") assigned to website $secondWebsiteId");

    // Disable TaxCloud on the second store only (store-scope override).
    $disabledValue = $preparedValueFactory->create(
        'tax/taxcloud_settings/enabled',
        '0',
        \Magento\Store\Model\ScopeInterface::SCOPE_STORES,
        SECOND_STORE_CODE
    );
    if ($disabledValue instanceof \Magento\Framework\App\Config\Value) {
        $disabledValue->getResource()->save($disabledValue);
    }
    $step('config tax/taxcloud_settings/enabled = 0 (scope stores/' . SECOND_STORE_CODE . ')');

    // Same credentials, second website: accounts are per-website (section 4h), so
    // the second store needs its own customer record for logged-in test flows.
    $ensureCustomer($secondStore);
}

// --- 4e. Unique order increment prefix per seed run --------------------------
//
// The TaxCloud sandbox account is persistent while every fresh install (and
// every reinstalled matrix row) restarts order increment ids at 000000001 —
// so e2e captures collide with orders a previous install already filed
// ("This transaction has already been captured") and refunds hit the OLD
// order's amounts. Prefixing the order sequence with a per-seed-run timestamp
// gives every run its own orderId namespace on the shared account. Digits
// only: the e2e specs assert /^\d+$/ on the order number, and TaxCloud gets a
// plain numeric string. Orders only — no other increment id reaches TaxCloud.
$orderPrefix = date('ymdHis');
$connection = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection('sales');
$profileTable = $connection->getTableName('sales_sequence_profile');
$metaTable = $connection->getTableName('sales_sequence_meta');
$updated = $connection->update(
    $profileTable,
    ['prefix' => $orderPrefix],
    ['meta_id IN (?)' => $connection->select()
        ->from($metaTable, ['meta_id'])
        ->where('entity_type = ?', 'order')]
);
$step("order increment prefix = $orderPrefix ($updated sequence profile(s), all stores)");

// Same collision, second identifier: v1 AuthorizedWithCapture dedupes on
// cartID — the Magento QUOTE id — not on orderID. A fresh install restarts
// quote ids at 1, so captures answer "Duplicate transaction" for carts a
// previous install already authorized on the shared sandbox account; the
// module reads that as benign success, nothing files under the new orderID,
// and every later refund fails "could not be found or has not been captured
// yet" (diagnosed from CI 2026-08-07, 2.4.8-p5). Start the quote sequence at
// the current unix timestamp: unique per run and well inside quote.entity_id's
// unsigned-int range.
$quoteStart = time();
$connection->query(
    'ALTER TABLE ' . $connection->getTableName('quote') . ' AUTO_INCREMENT = ' . (int) $quoteStart
);
$step("quote id sequence starts at $quoteStart (unique cartID namespace per run)");

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

echo "\n[seed] Done. " . ($productsOnly ? "Catalog seeded (--products-only):\n" : "Test environment ready:\n");
if (!$productsOnly) {
    echo '       admin:    ' . ADMIN_USERNAME . ' / ' . ADMIN_PASSWORD . ' <' . ADMIN_EMAIL . ">\n";
    echo '       customer: ' . CUSTOMER_EMAIL . ' / ' . CUSTOMER_PASSWORD
        . " (default addr Austin TX; one account per website, same creds)\n";
}
echo '       category: ' . CATEGORY_NAME . ' (' . CATEGORY_URL_KEY . ")\n";
echo '       product:  ' . PRODUCT_SKU . ' ($' . number_format(PRODUCT_PRICE, 2) . ")\n";
echo '       config.:  ' . CONFIGURABLE_SKU . ' (' . VARIANT_RED_SKU . ' tic '
    . VARIANT_RED_TIC . ', ' . VARIANT_BLUE_SKU . ' tic ' . VARIANT_BLUE_TIC . ")\n";
echo '       types:    ' . VIRTUAL_SKU . ', ' . DOWNLOADABLE_SKU . ', ' . GROUPED_SKU
    . ' ($10 each)' . "\n";
echo '                 ' . BUNDLE_DYNAMIC_SKU . ' ($30/bundle: 1x ' . PRODUCT_SKU
    . ' + 2x ' . VIRTUAL_SKU . '), ' . BUNDLE_FIXED_SKU
    . ' ($' . number_format(BUNDLE_FIXED_PRICE, 2) . ")\n";
echo '                 ' . ($giftcardSeeded
    ? GIFTCARD_SKU . ' ($' . number_format(GIFTCARD_AMOUNT, 2) . ', virtual)'
    : 'giftcard skipped (Open Source)') . "\n";
if (!$productsOnly) {
    echo '       stores:   /default/ (TaxCloud ON), /' . SECOND_STORE_CODE
        . "/ (TaxCloud OFF; store codes in URLs enabled)\n";
} else {
    echo "       untouched: configuration, admin user, customers, scope tree\n";
}
