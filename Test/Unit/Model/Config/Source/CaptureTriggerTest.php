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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Taxcloud\Magento2\Model\Config\Source\CaptureTrigger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Section A2: cover the CaptureTrigger source model and the admin/config wiring
 * around it — the option list is only meaningful if the field that renders it
 * points at the path TaxcloudConfig reads.
 */
#[AllowMockObjectsWithoutExpectations]
class CaptureTriggerTest extends TestCase
{
    public function testToOptionArrayReturnsThreeChoicesInOrder()
    {
        $options = (new CaptureTrigger())->toOptionArray();

        $this->assertCount(3, $options);

        $this->assertSame(CaptureTrigger::ORDER_CREATION, $options[0]['value']);
        $this->assertSame(CaptureTrigger::PAYMENT, $options[1]['value']);
        $this->assertSame(CaptureTrigger::SHIPMENT, $options[2]['value']);

        // The labels are wrapped in Magento's __(), which returns a Phrase
        // object — cast to string to compare the rendered text.
        $this->assertSame('On order creation', (string) $options[0]['label']);
        $this->assertSame('On payment', (string) $options[1]['label']);
        $this->assertSame('On shipment', (string) $options[2]['label']);
    }

    /**
     * The admin field must be wired to this source model on the same config path
     * TaxcloudConfig::getCaptureTrigger() reads.
     */
    public function testAdminFieldUsesThisSourceModelOnTheCaptureTriggerPath()
    {
        $systemXml = simplexml_load_file(__DIR__ . '/../../../../../etc/adminhtml/system.xml');
        $this->assertNotFalse($systemXml, 'etc/adminhtml/system.xml must be parseable');

        $field = $systemXml->xpath('//section[@id="tax"]/group[@id="taxcloud"]/field[@id="capture_trigger"]');

        $this->assertCount(1, $field, 'capture_trigger must appear once under the TaxCloud settings group');
        $this->assertSame(CaptureTrigger::class, (string) $field[0]->source_model);
        $this->assertSame(TaxcloudConfig::XML_PATH_CAPTURE_TRIGGER, (string) $field[0]->config_path);
    }

    /**
     * The field is meaningless in calculations-only mode (orders are never sent
     * to TaxCloud, so there is no capture to schedule) and meaningless with the
     * module off. Both dependencies must stay declared or the admin offers a
     * setting that does nothing.
     */
    public function testAdminFieldIsHiddenWhenDisabledOrCalculationsOnly()
    {
        $systemXml = simplexml_load_file(__DIR__ . '/../../../../../etc/adminhtml/system.xml');
        $field = $systemXml->xpath('//section[@id="tax"]/group[@id="taxcloud"]/field[@id="capture_trigger"]');

        $depends = [];
        foreach ($field[0]->depends->field as $dependency) {
            $depends[(string) $dependency['id']] = (string) $dependency;
        }

        $this->assertSame(['enabled' => '1', 'calculations_only' => '0'], $depends);
    }

    /**
     * The etc/config.xml default is what a fresh install gets; it must be one of
     * the declared options, and specifically the historical order-creation
     * behavior so upgrades do not silently move when merchants capture.
     */
    public function testConfigXmlDefaultIsOrderCreation()
    {
        $configXml = simplexml_load_file(__DIR__ . '/../../../../../etc/config.xml');
        $this->assertNotFalse($configXml, 'etc/config.xml must be parseable');

        $node = $configXml->xpath('/config/default/tax/taxcloud_settings/capture_trigger');

        $this->assertCount(1, $node, 'etc/config.xml must declare a capture_trigger default');
        $this->assertSame(CaptureTrigger::ORDER_CREATION, (string) $node[0]);
    }
}
