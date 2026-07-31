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

    // Store code in URLs so the default and second store (section 4d) are both
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

// --- 4c. Registered customer + default address ------------------------------
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
// second store (section 4d) gets its own record with the SAME credentials —
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
$ensureCustomer($storeManager->getDefaultStoreView());

// --- 4d. Second website / store group / store view (multi-store) -------------
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

const SECOND_WEBSITE_CODE = 'second';
const SECOND_GROUP_CODE   = 'second';
const SECOND_STORE_CODE   = 'second';

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
$catalogSkus = [PRODUCT_SKU, CONFIGURABLE_SKU, VARIANT_RED_SKU, VARIANT_BLUE_SKU];
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

// Same credentials, second website: accounts are per-website (section 4c), so
// the second store needs its own customer record for logged-in test flows.
$ensureCustomer($secondStore);

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
echo '       customer: ' . CUSTOMER_EMAIL . ' / ' . CUSTOMER_PASSWORD
    . " (default addr Austin TX; one account per website, same creds)\n";
echo '       category: ' . CATEGORY_NAME . ' (' . CATEGORY_URL_KEY . ")\n";
echo '       product:  ' . PRODUCT_SKU . ' ($' . number_format(PRODUCT_PRICE, 2) . ")\n";
echo '       config.:  ' . CONFIGURABLE_SKU . ' (' . VARIANT_RED_SKU . ' tic '
    . VARIANT_RED_TIC . ', ' . VARIANT_BLUE_SKU . ' tic ' . VARIANT_BLUE_TIC . ")\n";
echo '       stores:   /default/ (TaxCloud ON), /' . SECOND_STORE_CODE
    . "/ (TaxCloud OFF; store codes in URLs enabled)\n";
