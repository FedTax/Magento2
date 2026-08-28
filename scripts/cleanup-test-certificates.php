<?php
/**
 * Taxcloud_Magento2 - remove the certificates a test run filed at TaxCloud.
 *
 * The seed files every certificate under a run-unique TaxCloud identity
 * (see scripts/seed-test-data.php). That isolation is what stops parallel CI
 * jobs and interrupted runs from fighting over one shared identity — but
 * certificates are only removable through an explicit delete, so without this
 * every run would leave one behind in the sandbox forever.
 *
 * Deletes over v3, because v3 lists certificates created through EITHER API
 * while v1 cannot see v3-created ones.
 *
 * Usage, from a container with the module mounted:
 *
 *     php scripts/cleanup-test-certificates.php               # the seeded customer's namespace
 *     php scripts/cleanup-test-certificates.php e2e-2026...   # an explicit namespace
 *
 * Safe to run when there is nothing to delete, and safe to run twice. Intended
 * for `if: always()` in CI so a failed run still cleans up after itself.
 *
 * @package    Taxcloud_Magento2
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

const EXEMPT_CUSTOMER_EMAIL = 'exempt-customer@example.com';

$magentoRoot = getenv('MAGENTO_ROOT') ?: '/var/www/html';
require $magentoRoot . '/app/bootstrap.php';

$bootstrap = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

try {
    $om->get(\Magento\Framework\App\State::class)
        ->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
} catch (\Magento\Framework\Exception\LocalizedException $e) {
    // Already set — fine.
}

$say = static function (string $message): void {
    echo "[cleanup] $message\n";
};

$identity = $argv[1] ?? getenv('TAXCLOUD_E2E_IDENTITY') ?: '';

if ($identity === '') {
    // Read it back off the seeded customer, so a run that did not pass the
    // token around can still clean up after itself.
    try {
        $customer = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)
            ->get(EXEMPT_CUSTOMER_EMAIL);
        $attribute = $customer->getCustomAttribute('taxcloud_customer_id');
        $identity = $attribute ? trim((string) $attribute->getValue()) : '';
    } catch (\Throwable $e) {
        $say('no seeded customer to read an identity from (' . $e->getMessage() . ')');
        exit(0);
    }
}

if ($identity === '') {
    // Nothing to do, and deliberately NOT a failure: a store seeded before this
    // existed has no namespace, and its certificates are filed under the
    // customer's entity id where other runs may still rely on them.
    $say('no run-unique identity on the seeded customer; nothing to clean up');
    exit(0);
}

$say('cleaning certificates filed under ' . $identity);

try {
    $storeId = (int) $om->get(\Magento\Store\Model\StoreManagerInterface::class)
        ->getDefaultStoreView()->getId();

    /** @var \Taxcloud\Magento2\Model\Certificate\RestCertificateGateway $gateway */
    $gateway = $om->get(\Taxcloud\Magento2\Model\Certificate\RestCertificateGateway::class);

    $certificates = $gateway->listCertificates($identity, $storeId);
} catch (\Throwable $e) {
    // Cleanup must never fail a build: the tests have already had their say,
    // and an uncleaned certificate is a tidiness problem, not a broken one.
    $say('WARNING: could not list certificates - ' . $e->getMessage());
    exit(0);
}

if ($certificates === []) {
    $say('nothing filed under that identity');
    exit(0);
}

$removed = 0;
foreach ($certificates as $certificate) {
    $certificateId = $certificate->getCertificateId();

    try {
        $gateway->deleteCertificate($certificateId, $identity, $storeId);
        $removed++;
        $say('deleted ' . $certificateId);
    } catch (\Throwable $e) {
        $say('WARNING: could not delete ' . $certificateId . ' - ' . $e->getMessage());
    }
}

$say(sprintf('removed %d of %d', $removed, count($certificates)));
