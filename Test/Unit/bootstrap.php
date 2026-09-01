<?php
/**
 * Bootstrap for the Taxcloud_Magento2 unit test suite.
 *
 * The suite runs against the Magento installation this module lives inside —
 * there is no module-level vendor/ or hand-rolled mock layer. We delegate to
 * Magento's own canonical unit-test bootstrap, which provides:
 *
 *  - Composer autoload + app/code PSR-4, so Magento\* classes resolve to the
 *    real interfaces shipped with the installed Magento version
 *  - The generated-classes autoloader (creates *Factory / *Proxy /
 *    *ExtensionAttributes classes on demand in dev/tests/unit/tmp)
 *  - A Phrase renderer, so __('...') casts to string without an ObjectManager
 *  - Magento's standard test error handler
 *
 * Run from the Magento root with Magento's own PHPUnit:
 *
 *   vendor/bin/phpunit -c app/code/Taxcloud/Magento2/phpunit.xml.dist
 *
 * or via the module Makefile: `make test-unit`.
 *
 * The module is conventionally checked out at
 * <magento-root>/app/code/Taxcloud/Magento2/, so the Magento root is six
 * levels up. Override with the MAGENTO_ROOT env var for exotic layouts.
 */

declare(strict_types=1);

$magentoRoot = getenv('MAGENTO_ROOT') ?: realpath(__DIR__ . '/../../../../../..');
$magentoUnitBootstrap = $magentoRoot . '/dev/tests/unit/framework/bootstrap.php';

if (!is_file($magentoUnitBootstrap)) {
    fwrite(
        STDERR,
        "ERROR: Could not locate Magento's unit test bootstrap at:\n"
        . "  $magentoUnitBootstrap\n"
        . "\n"
        . "Unit tests for this module run against an installed Magento (its\n"
        . "vendor/ provides PHPUnit and the real Magento\\* interfaces). Either:\n"
        . "  1. Check the module out at <magento-root>/app/code/Taxcloud/Magento2/, or\n"
        . "  2. Set MAGENTO_ROOT to the Magento installation root, or\n"
        . "  3. Use the integration test stack (`make integration-test`), which\n"
        . "     provisions a known Magento version first.\n"
    );
    exit(1);
}

require $magentoUnitBootstrap;

// Unit-test doubles (declare Magento magic accessors / SOAP ops as real methods so
// they can be stubbed with onlyMethods() — PHPUnit 12 removed addMethods()).
require __DIR__ . '/Double/Doubles.php';

// Shared test traits. Required explicitly rather than autoloaded: composer's
// PSR-4 root for Taxcloud\Magento2\ is the module's app/code location, and the
// versioned unit runs (make test-unit-version) link only production paths into
// app/code while running the suite from the full module mount — so Test/ is
// outside the autoloader's reach there and a `use` of one of these traits is a
// fatal error. Same reason the doubles above are required by hand.
require_once __DIR__ . '/BuildsUserAgent.php';
require_once __DIR__ . '/BuildsGatewayApi.php';
