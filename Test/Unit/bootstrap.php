<?php
/**
 * Bootstrap file for unit tests
 * This file loads the Magento mocks before any other classes
 */

// Load Magento mocks first to prevent registration.php errors
require_once __DIR__ . '/Mocks/MagentoMocks.php';

// Now it's safe to load the autoloader
require_once __DIR__ . '/../../vendor/autoload.php';
