<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Gateway;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Gateway\CacheKeyBuilder;

/**
 * Pins the exact cache-key formats extracted from Model\Api so the caching
 * discipline (key on the exact request payload; scope exempt-cert state lists
 * per customer+certificate) cannot silently drift.
 */
class CacheKeyBuilderTest extends TestCase
{
    private CacheKeyBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new CacheKeyBuilder();
    }

    public function testForLookupMatchesLegacyFormat()
    {
        $params = ['apiLoginID' => 'id', 'cartItems' => [['ItemID' => 'sku', 'Qty' => 2]]];

        $this->assertSame(
            'taxcloud_rates_' . hash('sha256', json_encode($params)),
            $this->builder->forLookup($params)
        );
    }

    public function testForAddressMatchesLegacyFormat()
    {
        $params = ['address1' => '1 Infinite Loop', 'zip5' => '95014'];

        $this->assertSame(
            'taxcloud_address_' . hash('sha256', json_encode($params)),
            $this->builder->forAddress($params)
        );
    }

    public function testForExemptCertStatesMatchesLegacyFormat()
    {
        $this->assertSame(
            'taxcloud_cert_states_42_aaaa-bbbb',
            $this->builder->forExemptCertStates('42', 'aaaa-bbbb')
        );
    }

    public function testLookupKeyIsSensitiveToPayload()
    {
        $this->assertNotSame(
            $this->builder->forLookup(['a' => 1]),
            $this->builder->forLookup(['a' => 2]),
            'a different request payload must produce a different cache key'
        );
    }

    public function testExemptCertKeyIsScopedPerCustomer()
    {
        $this->assertNotSame(
            $this->builder->forExemptCertStates('1', 'same-cert'),
            $this->builder->forExemptCertStates('2', 'same-cert'),
            'the same certificate under two customers must not collide'
        );
    }
}
