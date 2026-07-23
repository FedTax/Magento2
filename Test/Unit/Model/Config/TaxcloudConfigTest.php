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
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @dataProvider loggingModeProvider
     */
    #[DataProvider('loggingModeProvider')]
    public function testLoggingModeAccessors($stored, int $mode, bool $enabled, bool $advanced, string $message)
    {
        $map = $stored === null ? [] : [self::value(TaxcloudConfig::XML_PATH_LOGGING, $stored)];
        $config = $this->config($map);

        $this->assertSame($mode, $config->getLoggingMode(), $message);
        $this->assertSame($enabled, $config->isLoggingEnabled(), $message);
        $this->assertSame($advanced, $config->isAdvancedLoggingEnabled(), $message);
    }

    public static function loggingModeProvider(): array
    {
        return [
            'unset stays disabled' => [null, TaxcloudConfig::LOGGING_DISABLED, false, false,
                'no stored value must not log'],
            'disabled' => ['0', TaxcloudConfig::LOGGING_DISABLED, false, false,
                'stored 0 stays disabled'],
            'legacy enable is basic' => ['1', TaxcloudConfig::LOGGING_BASIC, true, false,
                'the pre-upgrade "Enable" value must keep exactly Basic behavior'],
            'advanced' => ['2', TaxcloudConfig::LOGGING_ADVANCED, true, true,
                'stored 2 is advanced'],
            'unknown value collapses to basic' => ['7', TaxcloudConfig::LOGGING_BASIC, true, false,
                'a corrupted row must never silently enable payload logging'],
        ];
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

    public function testWsdlUrlHonorsConfiguredEndpoint()
    {
        $config = $this->config([
            self::value(TaxcloudConfig::XML_PATH_WSDL_URL, 'https://sandbox.example.test/TaxCloud.asmx?wsdl'),
        ]);

        $this->assertSame('https://sandbox.example.test/TaxCloud.asmx?wsdl', $config->getWsdlUrl());
    }

    public function testWsdlUrlTrimsSurroundingWhitespace()
    {
        $config = $this->config([
            self::value(TaxcloudConfig::XML_PATH_WSDL_URL, "  https://sandbox.example.test/TaxCloud.asmx?wsdl\n"),
        ]);

        $this->assertSame('https://sandbox.example.test/TaxCloud.asmx?wsdl', $config->getWsdlUrl());
    }

    /**
     * A blank stored value must reach the production endpoint rather than the
     * SoapClient, where it would surface as an opaque WSDL fetch failure.
     *
     * @dataProvider blankWsdlUrlProvider
     */
    #[DataProvider('blankWsdlUrlProvider')]
    public function testWsdlUrlFallsBackToProductionWhenBlank(array $map, string $message)
    {
        $this->assertSame(TaxcloudConfig::DEFAULT_WSDL_URL, $this->config($map)->getWsdlUrl(), $message);
    }

    public static function blankWsdlUrlProvider(): array
    {
        return [
            'unset' => [[], 'no stored value falls back to production'],
            'empty string' => [
                [self::value(TaxcloudConfig::XML_PATH_WSDL_URL, '')],
                'cleared field falls back to production',
            ],
            'whitespace only' => [
                [self::value(TaxcloudConfig::XML_PATH_WSDL_URL, '   ')],
                'whitespace-only field falls back to production',
            ],
        ];
    }

    /**
     * The etc/config.xml default is what a fresh install actually gets; it must
     * agree with the constant the code falls back to.
     */
    public function testConfigXmlDefaultMatchesTheCodeDefault()
    {
        $configXml = simplexml_load_file(__DIR__ . '/../../../../etc/config.xml');
        $this->assertNotFalse($configXml, 'etc/config.xml must be parseable');

        $node = $configXml->xpath('/config/default/tax/taxcloud_settings/wsdl_url');

        $this->assertCount(1, $node, 'etc/config.xml must declare a wsdl_url default');
        $this->assertSame(
            TaxcloudConfig::DEFAULT_WSDL_URL,
            (string) $node[0],
            'config.xml wsdl_url must match TaxcloudConfig::DEFAULT_WSDL_URL'
        );
    }

    /**
     * Acceptance criterion: the field must actually be reachable in the admin,
     * under the TaxCloud group and bound to the path the reader queries.
     */
    public function testAdminFieldIsWiredToTheSameConfigPath()
    {
        $systemXml = simplexml_load_file(__DIR__ . '/../../../../etc/adminhtml/system.xml');
        $this->assertNotFalse($systemXml, 'etc/adminhtml/system.xml must be parseable');

        $field = $systemXml->xpath('//section[@id="tax"]/group[@id="taxcloud"]/field[@id="wsdl_url"]');

        $this->assertCount(1, $field, 'wsdl_url must appear once under the TaxCloud settings group');
        $this->assertSame(
            TaxcloudConfig::XML_PATH_WSDL_URL,
            (string) $field[0]->config_path,
            'admin field config_path must match the path TaxcloudConfig reads'
        );
    }
}
