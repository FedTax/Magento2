<?php
/**
 * Bootstrap file for unit tests.
 *
 * The Magento stubs file uses a `.php.inc` extension on purpose: Magento's
 * setup:di:compile brute-force scanner walks app/code/<vendor>/<module>/ for
 * `*.php` files and `require_once`s them to extract class info. If our stubs
 * file were `.php`, the scanner would load it and crash with "Cannot declare
 * interface Magento\Framework\App\Config\ScopeConfigInterface, because the
 * name is already in use" — because the file declares stubs in real Magento
 * namespaces. The `.inc` suffix sidesteps the scanner entirely. PHPUnit
 * picks it up here via require_once before any test runs.
 *
 * Known limitation: some assertions in the unit suite implicitly depend on
 * stub signatures in MagentoMocks.php.inc that don't match Magento's actual
 * interfaces. Replacing the stubs with real Magento autoload reveals ~80
 * hidden test bugs (see DEV-8263 PR notes for the follow-up).
 */

require_once __DIR__ . '/../../dev/test-bootstrap/MagentoMocks.php.inc';
require_once __DIR__ . '/../../vendor/autoload.php';
