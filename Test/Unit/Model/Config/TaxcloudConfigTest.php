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
     * calculations_only is opt-in: an install that has never seen the field
     * keeps the full integration.
     */
    public function testCalculationsOnlyDefaultsToFalse()
    {
        $this->assertFalse($this->config([])->isCalculationsOnly());
    }

    /**
     * @dataProvider calculationsOnlyProvider
     */
    #[DataProvider('calculationsOnlyProvider')]
    public function testCalculationsOnlyCoercesStoredValue($stored, bool $expected)
    {
        $config = $this->config([self::value(TaxcloudConfig::XML_PATH_CALCULATIONS_ONLY, $stored)]);

        $this->assertSame($expected, $config->isCalculationsOnly());
    }

    /**
     * @return array
     */
    public static function calculationsOnlyProvider(): array
    {
        return [
            'stored "1"' => ['1', true],
            'stored "0"' => ['0', false],
            'stored null' => [null, false],
            'stored empty' => ['', false],
        ];
    }

    /**
     * The store argument is forwarded as the scope code, so a store view can
     * run calculation-only while the default scope keeps the full integration.
     */
    public function testCalculationsOnlyIsResolvedPerStore()
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            [TaxcloudConfig::XML_PATH_CALCULATIONS_ONLY, ScopeInterface::SCOPE_STORE, null, '0'],
            [TaxcloudConfig::XML_PATH_CALCULATIONS_ONLY, ScopeInterface::SCOPE_STORE, 7, '1'],
        ]);
        $config = new TaxcloudConfig($scopeConfig);

        $this->assertFalse($config->isCalculationsOnly());
        $this->assertTrue($config->isCalculationsOnly(7));
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
     * Multi-store acceptance criterion: EVERY accessor must forward its $store
     * argument as the scope code. Each case stores a different value at store 7
     * than at the ambient (null) scope; the accessor called with store 7 must
     * return the store-7 value — an accessor that drops the store argument
     * resolves the ambient value instead and fails.
     *
     * @dataProvider storeForwardingProvider
     */
    #[DataProvider('storeForwardingProvider')]
    public function testAccessorForwardsStoreAsScopeCode(string $path, $storeValue, $ambientValue, \Closure $call, $expected)
    {
        $config = $this->config([
            [$path, ScopeInterface::SCOPE_STORE, 7, $storeValue],
            [$path, ScopeInterface::SCOPE_STORE, null, $ambientValue],
        ]);

        $this->assertSame($expected, $call($config));
    }

    public static function storeForwardingProvider(): array
    {
        return [
            'isEnabled' => [
                TaxcloudConfig::XML_PATH_ENABLED, '1', '0',
                static fn (TaxcloudConfig $c) => $c->isEnabled(7), true,
            ],
            'getLoggingMode' => [
                TaxcloudConfig::XML_PATH_LOGGING, '2', '0',
                static fn (TaxcloudConfig $c) => $c->getLoggingMode(7), TaxcloudConfig::LOGGING_ADVANCED,
            ],
            'isLoggingEnabled' => [
                TaxcloudConfig::XML_PATH_LOGGING, '1', '0',
                static fn (TaxcloudConfig $c) => $c->isLoggingEnabled(7), true,
            ],
            'isAdvancedLoggingEnabled' => [
                TaxcloudConfig::XML_PATH_LOGGING, '2', '1',
                static fn (TaxcloudConfig $c) => $c->isAdvancedLoggingEnabled(7), true,
            ],
            'getApiType' => [
                TaxcloudConfig::XML_PATH_API_TYPE, 'soap', 'rest',
                static fn (TaxcloudConfig $c) => $c->getApiType(7), 'soap',
            ],
            'getApiId' => [
                TaxcloudConfig::XML_PATH_API_ID, 'store-api-id', 'ambient-api-id',
                static fn (TaxcloudConfig $c) => $c->getApiId(7), 'store-api-id',
            ],
            'getApiKey' => [
                TaxcloudConfig::XML_PATH_API_KEY, 'store-api-key', 'ambient-api-key',
                static fn (TaxcloudConfig $c) => $c->getApiKey(7), 'store-api-key',
            ],
            'getRestApiKey (no encryptor: raw)' => [
                TaxcloudConfig::XML_PATH_REST_API_KEY, 'store-rest-key', 'ambient-rest-key',
                static fn (TaxcloudConfig $c) => $c->getRestApiKey(7), 'store-rest-key',
            ],
            'getRestConnectionId' => [
                TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, 'store-conn-id', 'ambient-conn-id',
                static fn (TaxcloudConfig $c) => $c->getRestConnectionId(7), 'store-conn-id',
            ],
            'getRestEndpoint' => [
                TaxcloudConfig::XML_PATH_REST_ENDPOINT, 'https://store.example', 'https://ambient.example',
                static fn (TaxcloudConfig $c) => $c->getRestEndpoint(7), 'https://store.example',
            ],
            'getRestAuthEndpoint' => [
                TaxcloudConfig::XML_PATH_REST_AUTH_ENDPOINT, 'https://store-auth.example', 'https://ambient-auth.example',
                static fn (TaxcloudConfig $c) => $c->getRestAuthEndpoint(7), 'https://store-auth.example',
            ],
            'getGuestCustomerId' => [
                TaxcloudConfig::XML_PATH_GUEST_CUSTOMER_ID, '42', '-1',
                static fn (TaxcloudConfig $c) => $c->getGuestCustomerId(7), '42',
            ],
            'getCacheLifetime' => [
                TaxcloudConfig::XML_PATH_CACHE_LIFETIME, '3600', '0',
                static fn (TaxcloudConfig $c) => $c->getCacheLifetime(7), 3600,
            ],
            'isFallbackToMagentoEnabled' => [
                TaxcloudConfig::XML_PATH_FALLBACK_TO_MAGENTO, '1', '0',
                static fn (TaxcloudConfig $c) => $c->isFallbackToMagentoEnabled(7), true,
            ],
            'getSoapTimeout' => [
                TaxcloudConfig::XML_PATH_API_TIMEOUT, '30', '5',
                static fn (TaxcloudConfig $c) => $c->getSoapTimeout(7), 30,
            ],
            'getWsdlUrl' => [
                TaxcloudConfig::XML_PATH_WSDL_URL, 'https://store.example/wsdl', 'https://ambient.example/wsdl',
                static fn (TaxcloudConfig $c) => $c->getWsdlUrl(7), 'https://store.example/wsdl',
            ],
            'isVerifyAddressEnabled' => [
                TaxcloudConfig::XML_PATH_VERIFY_ADDRESS, '1', '0',
                static fn (TaxcloudConfig $c) => $c->isVerifyAddressEnabled(7), true,
            ],
            'getCaptureTrigger' => [
                TaxcloudConfig::XML_PATH_CAPTURE_TRIGGER, 'shipment', 'order_creation',
                static fn (TaxcloudConfig $c) => $c->getCaptureTrigger(7), 'shipment',
            ],
            'getDefaultTic' => [
                TaxcloudConfig::XML_PATH_DEFAULT_TIC, '20010', '00000',
                static fn (TaxcloudConfig $c) => $c->getDefaultTic(7), '20010',
            ],
            'getShippingTic' => [
                TaxcloudConfig::XML_PATH_SHIPPING_TIC, '11000', '11010',
                static fn (TaxcloudConfig $c) => $c->getShippingTic(7), '11000',
            ],
        ];
    }

    /**
     * @dataProvider apiTypeProvider
     */
    #[DataProvider('apiTypeProvider')]
    public function testApiTypeReadsStoredValueAndCollapsesUnknownToRest($stored, string $expected, string $message)
    {
        $map = $stored === null ? [] : [self::value(TaxcloudConfig::XML_PATH_API_TYPE, $stored)];

        $this->assertSame($expected, $this->config($map)->getApiType(), $message);
    }

    public static function apiTypeProvider(): array
    {
        return [
            'stored soap' => ['soap', \Taxcloud\Magento2\Model\Config\Source\ApiType::SOAP,
                'a pinned legacy install must keep SOAP'],
            'stored rest' => ['rest', \Taxcloud\Magento2\Model\Config\Source\ApiType::REST,
                'an explicit REST choice must hold'],
            'unset' => [null, \Taxcloud\Magento2\Model\Config\Source\ApiType::REST,
                'no stored value must resolve to the shipped default'],
            'blank' => ['', \Taxcloud\Magento2\Model\Config\Source\ApiType::REST,
                'a cleared row must fall back to the current API'],
            'unknown value' => ['graphql', \Taxcloud\Magento2\Model\Config\Source\ApiType::REST,
                'a corrupted row must select the current API, not the legacy one'],
        ];
    }

    /**
     * The admin field's Encrypted backend stores ciphertext; the accessor must
     * hand callers the decrypted key.
     */
    public function testRestApiKeyIsDecryptedWhenEncryptorPresent()
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            [TaxcloudConfig::XML_PATH_REST_API_KEY, ScopeInterface::SCOPE_STORE, null, '0:3:ciphertext'],
        ]);
        $encryptor = $this->createMock(\Magento\Framework\Encryption\EncryptorInterface::class);
        $encryptor->method('decrypt')->with('0:3:ciphertext')->willReturn('plain-key');

        $config = new TaxcloudConfig($scopeConfig, $encryptor);

        $this->assertSame('plain-key', $config->getRestApiKey());
    }

    public function testRestApiKeyIsNullWhenUnsetAndDecryptIsNeverCalled()
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $encryptor = $this->createMock(\Magento\Framework\Encryption\EncryptorInterface::class);
        $encryptor->expects($this->never())->method('decrypt');

        $config = new TaxcloudConfig($scopeConfig, $encryptor);

        $this->assertNull($config->getRestApiKey());
    }

    public function testRestConnectionIdIsTrimmedAndBlankCollapsesToNull()
    {
        $config = $this->config([
            self::value(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, '  25eb9b97-5acb-492d-b720-c03e79cf715a  '),
        ]);
        $this->assertSame('25eb9b97-5acb-492d-b720-c03e79cf715a', $config->getRestConnectionId());

        $this->assertNull($this->config([])->getRestConnectionId());
        $this->assertNull(
            $this->config([self::value(TaxcloudConfig::XML_PATH_REST_CONNECTION_ID, '   ')])->getRestConnectionId()
        );
    }

    public function testRestEndpointFallsBackToProductionAndTrimsTrailingSlash()
    {
        $this->assertSame(TaxcloudConfig::DEFAULT_REST_ENDPOINT, $this->config([])->getRestEndpoint());
        $this->assertSame(
            'https://staging.example',
            $this->config([
                self::value(TaxcloudConfig::XML_PATH_REST_ENDPOINT, 'https://staging.example/'),
            ])->getRestEndpoint()
        );
    }

    public function testRestAuthEndpointFallsBackToProductionAndTrimsTrailingSlash()
    {
        $this->assertSame(TaxcloudConfig::DEFAULT_REST_AUTH_ENDPOINT, $this->config([])->getRestAuthEndpoint());
        $this->assertSame(
            'https://staging-auth.example',
            $this->config([
                self::value(TaxcloudConfig::XML_PATH_REST_AUTH_ENDPOINT, 'https://staging-auth.example/'),
            ])->getRestAuthEndpoint()
        );
    }

    /**
     * The etc/config.xml default must agree with the constant the code falls
     * back to, for both v3 hosts.
     */
    public function testRestEndpointConfigXmlDefaultsMatchTheCodeDefaults()
    {
        $configXml = simplexml_load_file(__DIR__ . '/../../../../etc/config.xml');

        $endpoint = $configXml->xpath('/config/default/tax/taxcloud_settings/rest_endpoint');
        $this->assertCount(1, $endpoint);
        $this->assertSame(TaxcloudConfig::DEFAULT_REST_ENDPOINT, (string) $endpoint[0]);

        $auth = $configXml->xpath('/config/default/tax/taxcloud_settings/rest_auth_endpoint');
        $this->assertCount(1, $auth, 'etc/config.xml must declare a rest_auth_endpoint default');
        $this->assertSame(TaxcloudConfig::DEFAULT_REST_AUTH_ENDPOINT, (string) $auth[0]);
    }

    public function testCaptureTriggerDefaultsToOrderCreationWhenUnset()
    {
        $config = $this->config([]);

        $this->assertSame(
            \Taxcloud\Magento2\Model\Config\Source\CaptureTrigger::ORDER_CREATION,
            $config->getCaptureTrigger()
        );
    }

    public function testTicAccessorsFallBackToDefaultsWhenUnset()
    {
        $config = $this->config([
            self::value(TaxcloudConfig::XML_PATH_DEFAULT_TIC, ''),
        ]);

        $this->assertSame(TaxcloudConfig::DEFAULT_TIC, $config->getDefaultTic());
        $this->assertSame(TaxcloudConfig::DEFAULT_SHIPPING_TIC, $config->getShippingTic());
    }

    public function testVerifyAddressIsFalseWhenUnset()
    {
        $this->assertFalse($this->config([])->isVerifyAddressEnabled());
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
