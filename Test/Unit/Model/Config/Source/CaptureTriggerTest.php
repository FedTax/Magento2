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

/**
 * Section A2: cover the only public method on the CaptureTrigger source.
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
}
