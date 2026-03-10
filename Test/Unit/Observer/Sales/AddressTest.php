<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Observer\Sales;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Observer\Sales\Address;

class AddressTest extends TestCase
{
    /**
     * When verify_address is disabled, observer returns early and does not call verifyAddress.
     */
    public function testExecuteDoesNothingWhenVerifyAddressDisabled()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/verify_address', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '0']
            ]);

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->expects($this->never())->method('verifyAddress');

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $observer = new Address($scopeConfig, $tcapi, $logger);

        $event = $this->createMock(\Magento\Framework\Event::class);
        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        $observer->execute($observerObj);
    }

    /**
     * When verifyAddress returns a result with empty Address1, observer preserves original Address1/Address2.
     */
    public function testExecutePreservesStreetWhenVerifiedResultHasEmptyAddress1()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/verify_address', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1']
            ]);

        $originalDestination = [
            'Address1' => '405 Victorian Ln',
            'Address2' => 'Apt 2',
            'City' => 'Duluth',
            'State' => 'GA',
            'Zip5' => '30097',
            'Zip4' => '',
        ];

        $verifiedResult = [
            'Address1' => '',
            'Address2' => '',
            'City' => 'Duluth',
            'State' => 'GA',
            'Zip5' => '30097',
            'Zip4' => '',
        ];

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->method('verifyAddress')->with($originalDestination)->willReturn($verifiedResult);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $params = [
            'destination' => $originalDestination,
            'origin' => [],
        ];

        $obj = $this->getMockBuilder(\Magento\Framework\DataObject::class)
            ->disableOriginalConstructor()
            ->addMethods(['setParams', 'getParams'])
            ->getMock();
        $obj->method('getParams')->willReturn($params);
        $obj->expects($this->once())->method('setParams')->with($this->callback(function ($updated) use ($originalDestination) {
            return isset($updated['destination']['Address1'])
                && $updated['destination']['Address1'] === $originalDestination['Address1']
                && isset($updated['destination']['Address2'])
                && $updated['destination']['Address2'] === $originalDestination['Address2'];
        }))->willReturnSelf();

        $event = $this->createMock(\Magento\Framework\Event::class);
        $event->method('getObj')->willReturn($obj);

        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        $observer = new Address($scopeConfig, $tcapi, $logger);
        $observer->execute($observerObj);
    }

    /**
     * When verifyAddress returns a result with non-empty Address1, observer uses verified result as-is.
     */
    public function testExecuteUsesVerifiedDestinationWhenAddress1IsPresent()
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnMap([
                ['tax/taxcloud_settings/enabled', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1'],
                ['tax/taxcloud_settings/verify_address', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, null, '1']
            ]);

        $originalDestination = [
            'Address1' => '405 Victorian Ln',
            'Address2' => '',
            'City' => 'Duluth',
            'State' => 'GA',
            'Zip5' => '30097',
            'Zip4' => '',
        ];

        $verifiedResult = [
            'Address1' => '405 Victorian Ln',
            'Address2' => 'Unit B',
            'City' => 'Duluth',
            'State' => 'GA',
            'Zip5' => '30097',
            'Zip4' => '1234',
        ];

        $tcapi = $this->createMock(\Taxcloud\Magento2\Model\Api::class);
        $tcapi->method('verifyAddress')->with($originalDestination)->willReturn($verifiedResult);

        $logger = $this->createMock(\Taxcloud\Magento2\Logger\Logger::class);

        $params = ['destination' => $originalDestination];

        $obj = $this->getMockBuilder(\Magento\Framework\DataObject::class)
            ->disableOriginalConstructor()
            ->addMethods(['setParams', 'getParams'])
            ->getMock();
        $obj->method('getParams')->willReturn($params);
        $obj->expects($this->once())->method('setParams')->with($this->callback(function ($updated) use ($verifiedResult) {
            return $updated['destination'] === $verifiedResult;
        }))->willReturnSelf();

        $event = $this->createMock(\Magento\Framework\Event::class);
        $event->method('getObj')->willReturn($obj);

        $observerObj = $this->createMock(\Magento\Framework\Event\Observer::class);
        $observerObj->method('getEvent')->willReturn($event);

        $observer = new Address($scopeConfig, $tcapi, $logger);
        $observer->execute($observerObj);
    }
}
