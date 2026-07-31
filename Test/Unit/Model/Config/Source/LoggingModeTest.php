<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\Source\LoggingMode;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Pins the stored option values to the TaxcloudConfig mode constants — the
 * backward-compatibility contract: 1 must remain Basic (the old "Enable"),
 * 0 must remain Disable, so upgraded installs keep their behavior without a
 * data migration.
 */
class LoggingModeTest extends TestCase
{
    public function testOptionValuesMatchTheModeConstants()
    {
        $values = array_map(
            static fn ($option) => (int) $option['value'],
            (new LoggingMode())->toOptionArray()
        );

        $this->assertSame(
            [
                TaxcloudConfig::LOGGING_BASIC,
                TaxcloudConfig::LOGGING_ADVANCED,
                TaxcloudConfig::LOGGING_DISABLED,
            ],
            $values
        );
    }

    public function testLegacyEnableValueMapsToBasic()
    {
        $this->assertSame(1, TaxcloudConfig::LOGGING_BASIC, 'the old "Enable" (1) must stay Basic');
        $this->assertSame(0, TaxcloudConfig::LOGGING_DISABLED, 'the old "Disable" (0) must stay Disable');
    }

    /**
     * Acceptance criterion: the admin field actually offers the three modes,
     * on the same config path the reader queries.
     */
    public function testAdminFieldUsesThisSourceModelOnTheLoggingPath()
    {
        $systemXml = simplexml_load_file(__DIR__ . '/../../../../../etc/adminhtml/system.xml');
        $this->assertNotFalse($systemXml, 'etc/adminhtml/system.xml must be parseable');

        $field = $systemXml->xpath('//section[@id="tax"]/group[@id="taxcloud"]/field[@id="logging"]');

        $this->assertCount(1, $field, 'logging must appear once under the TaxCloud settings group');
        $this->assertSame(LoggingMode::class, (string) $field[0]->source_model);
        $this->assertSame(TaxcloudConfig::XML_PATH_LOGGING, (string) $field[0]->config_path);
    }

    /**
     * The etc/config.xml default is what a fresh install gets; it must be one
     * of the declared modes.
     */
    public function testConfigXmlDefaultIsAValidMode()
    {
        $configXml = simplexml_load_file(__DIR__ . '/../../../../../etc/config.xml');
        $this->assertNotFalse($configXml, 'etc/config.xml must be parseable');

        $node = $configXml->xpath('/config/default/tax/taxcloud_settings/logging');

        $this->assertCount(1, $node, 'etc/config.xml must declare a logging default');
        $this->assertContains(
            (int) $node[0],
            [
                TaxcloudConfig::LOGGING_DISABLED,
                TaxcloudConfig::LOGGING_BASIC,
                TaxcloudConfig::LOGGING_ADVANCED,
            ]
        );
    }
}
