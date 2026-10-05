<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Config\Source;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Config\Source\ReportOrders;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * "Report orders to TaxCloud" is stored inverted at calculations_only. The
 * options, the admin field and the config reader must agree on that, or a
 * merchant choosing "No" would start reporting.
 */
#[AllowMockObjectsWithoutExpectations]
class ReportOrdersTest extends TestCase
{
    public function testYesIsStoredAsZeroAndNoAsOne()
    {
        $options = [];
        foreach ((new ReportOrders())->toOptionArray() as $option) {
            $options[(string) $option['label']] = $option['value'];
        }

        $this->assertSame(['Yes' => '0', 'No' => '1'], $options);
    }

    /**
     * Each option, stored, reads back as what it says.
     */
    public function testEachOptionReadsBackAsItsLabel()
    {
        foreach ([ReportOrders::REPORT => true, ReportOrders::DO_NOT_REPORT => false] as $stored => $expected) {
            $scopeConfig = $this->createMock(ScopeConfigInterface::class);
            $scopeConfig->method('getValue')->willReturn((string) $stored);

            $this->assertSame($expected, (new TaxcloudConfig($scopeConfig))->isReportingByDefault());
        }
    }

    /**
     * The field keeps the historic path, so existing values carry over without
     * migration, and renders through the inverted source model.
     */
    public function testAdminFieldKeepsThePathAndUsesTheInvertedSource()
    {
        $systemXml = simplexml_load_file(__DIR__ . '/../../../../../etc/adminhtml/system.xml');
        $field = $systemXml->xpath('//section[@id="tax"]/group[@id="taxcloud"]/field[@id="calculations_only"]');

        $this->assertCount(1, $field);
        $this->assertSame(TaxcloudConfig::XML_PATH_CALCULATIONS_ONLY, (string) $field[0]->config_path);
        $this->assertSame(ReportOrders::class, ltrim((string) $field[0]->source_model, '\\'));
        $this->assertSame('Report orders to TaxCloud', (string) $field[0]->label);
    }

    /**
     * A fresh install reports: the config.xml default must be the stored 0.
     */
    public function testFreshInstallReports()
    {
        $configXml = simplexml_load_file(__DIR__ . '/../../../../../etc/config.xml');
        $node = $configXml->xpath('//default/tax/taxcloud_settings/calculations_only');

        $this->assertSame(ReportOrders::REPORT, (string) $node[0]);
    }
}
