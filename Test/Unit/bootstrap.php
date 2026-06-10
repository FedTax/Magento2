<?php
/**
 * Bootstrap file for unit tests.
 *
 * MagentoMocks.php lives outside the module's PSR-4 root (under dev/) so the
 * Composer classmap and Magento's setup:di:compile can never accidentally pick
 * it up in a real Magento install. It is loaded here only for unit-test runs.
 */

require_once __DIR__ . '/../../dev/test-bootstrap/MagentoMocks.php';
require_once __DIR__ . '/../../vendor/autoload.php';
