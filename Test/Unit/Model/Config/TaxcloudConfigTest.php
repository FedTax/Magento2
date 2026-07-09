<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Covers the config reader extracted from Model\Api's scattered scopeConfig
 * getters, pinning the coercions (int/bool) and the guest-id / timeout
 * fallbacks that the gateway relies on.
 */
#[AllowMockObjectsWithoutExpectations]
class TaxcloudConfigTest extends TestCase
{
    private function config(array $map): TaxcloudConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap($map);
        return new TaxcloudConfig($scopeConfig);
    }

    private static function value(string $path, $value): array
    {
        return [$path, ScopeInterface::SCOPE_STORE, null, $value];
    }

    public function testReadsCredentialsAndFlags()
    {
        $config = $this->config([
            self::value(TaxcloudConfig::XML_PATH_API_ID, 'login-123'),
            self::value(TaxcloudConfig::XML_PATH_API_KEY, 'secret-key'),
            self::value(TaxcloudConfig::XML_PATH_ENABLED, '1'),
            self::value(TaxcloudConfig::XML_PATH_LOGGING, '1'),
            self::value(TaxcloudConfig::XML_PATH_FALLBACK_TO_MAGENTO, '1'),
        ]);

        $this->assertSame('login-123', $config->getApiId());
        $this->assertSame('secret-key', $config->getApiKey());
        $this->assertTrue($config->isEnabled());
        $this->assertTrue($config->isLoggingEnabled());
        $this->assertTrue($config->isFallbackToMagentoEnabled());
    }

    public function testFlagsAreFalseWhenUnset()
    {
        $config = $this->config([]);

        $this->assertFalse($config->isEnabled());
        $this->assertFalse($config->isLoggingEnabled());
        $this->assertFalse($config->isFallbackToMagentoEnabled());
    }

    public function testGuestCustomerIdDefaultsToMinusOne()
    {
        $config = $this->config([]); // getValue returns null

        $this->assertSame('-1', $config->getGuestCustomerId());
    }

    public function testGuestCustomerIdHonorsConfiguredValue()
    {
        $config = $this->config([
            self::value(TaxcloudConfig::XML_PATH_GUEST_CUSTOMER_ID, '900'),
        ]);

        $this->assertSame('900', $config->getGuestCustomerId());
    }

    public function testCacheLifetimeIsCoercedToInt()
    {
        $config = $this->config([
            self::value(TaxcloudConfig::XML_PATH_CACHE_LIFETIME, '3600'),
        ]);

        $this->assertSame(3600, $config->getCacheLifetime());
    }

    public function testSoapTimeoutUsesConfiguredValueWhenPositive()
    {
        $config = $this->config([
            self::value(TaxcloudConfig::XML_PATH_API_TIMEOUT, '25'),
        ]);

        $this->assertSame(25, $config->getSoapTimeout());
    }

    public function testSoapTimeoutFallsBackToDefaultWhenZeroOrUnset()
    {
        $this->assertSame(
            TaxcloudConfig::DEFAULT_SOAP_TIMEOUT,
            $this->config([self::value(TaxcloudConfig::XML_PATH_API_TIMEOUT, '0')])->getSoapTimeout()
        );
        $this->assertSame(
            TaxcloudConfig::DEFAULT_SOAP_TIMEOUT,
            $this->config([])->getSoapTimeout()
        );
    }
}
