<?php
/**
 * PHPStan bootstrap for the Taxcloud_Magento2 module.
 *
 * The module ships no vendor/ of its own — it is analyzed against the Magento
 * installation it lives inside (mirrors Test/Unit/bootstrap.php). This file:
 *
 *  - registers Magento's Composer autoloader so referenced Magento\* classes resolve,
 *  - registers the generated-classes autoloader (creates *Factory / *ExtensionAttributes
 *    classes on demand) so PHPStan understands DI factories, and
 *  - loads Magento's own PhpStan reflection extensions (DataObject magic getters/setters).
 *
 * The Magento root is six levels up from a conventional
 * <magento-root>/app/code/Taxcloud/Magento2/ checkout, or set MAGENTO_ROOT.
 */

declare(strict_types=1);

$magentoRoot = getenv('MAGENTO_ROOT') ?: realpath(__DIR__ . '/../../../..');

$autoload = $magentoRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(
        STDERR,
        "PHPStan bootstrap: could not find Magento's autoloader at {$autoload}.\n"
        . "Set MAGENTO_ROOT to the Magento installation root (the module is analyzed\n"
        . "against the installed Magento, which provides the real Magento\\* classes).\n"
    );
    exit(1);
}
require $autoload;

// Generated *Factory / *ExtensionAttributes autoloader + Magento's PhpStan reflection
// extensions (registered via the neon `services:` block below).
$phpstanAutoload = $magentoRoot . '/dev/tests/static/framework/Magento/PhpStan/autoload.php';
if (is_file($phpstanAutoload)) {
    require $phpstanAutoload;
}
