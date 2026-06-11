<?php
/**
 * Integration test bootstrap for Taxcloud_Magento2.
 *
 * Boots the actual installed Magento (the one scripts/install-magento.sh
 * provisioned), grabs its ObjectManager, and stashes it where the test
 * cases can pull it from.
 *
 * Deliberately does NOT use Magento's dev/tests/integration/ framework:
 * - That framework installs a second Magento into a separate DB to give
 *   tests app-level isolation. We don't need that — we want to test
 *   against the actual installed Magento + the restored fixture DB.
 * - All the TESTS_* constants that framework demands are skipped.
 */

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

const MAGENTO_INTEGRATION_ROOT = '/var/www/html';

require MAGENTO_INTEGRATION_ROOT . '/app/bootstrap.php';

$params = $_SERVER;
$params[Bootstrap::INIT_PARAM_FILESYSTEM_DIR_PATHS] = [];

$magentoBootstrap = Bootstrap::create(MAGENTO_INTEGRATION_ROOT, $params);

\Taxcloud\Magento2\Test\Integration\TestEnvironment::setObjectManager(
    $magentoBootstrap->getObjectManager()
);
