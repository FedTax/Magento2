<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\OrderRule\Source;

use Magento\Payment\Model\Config\Source\Allmethods;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\OrderRule\Source\PaymentMethods;

/**
 * Core's grouped payment list keys each group's methods by code; the admin
 * multiselect renders only plain lists, so the groups must be flattened or
 * their methods cannot be selected (observed in the e2e rules form).
 */
#[AllowMockObjectsWithoutExpectations]
class PaymentMethodsTest extends TestCase
{
    public function testGroupsBecomePlainListsAndEmptyGroupsAreDropped()
    {
        $core = $this->createMock(Allmethods::class);
        $core->method('toOptionArray')->willReturn([
            'offline' => ['label' => 'Offline Payment Methods', 'value' => [
                'checkmo' => ['value' => 'checkmo', 'label' => 'Check / Money order'],
                'banktransfer' => ['value' => 'banktransfer', 'label' => 'Bank Transfer Payment'],
            ]],
            'braintree_group' => ['label' => 'Braintree', 'value' => null],
            'm2epropayment' => ['value' => 'm2epropayment', 'label' => 'M2E Pro Payment'],
        ]);

        $this->assertSame([
            ['label' => 'Offline Payment Methods', 'value' => [
                ['value' => 'checkmo', 'label' => 'Check / Money order'],
                ['value' => 'banktransfer', 'label' => 'Bank Transfer Payment'],
            ]],
            ['value' => 'm2epropayment', 'label' => 'M2E Pro Payment'],
        ], (new PaymentMethods($core))->toOptionArray());
    }
}
