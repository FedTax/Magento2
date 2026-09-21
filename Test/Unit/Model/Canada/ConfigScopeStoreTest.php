<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Canada;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Canada\ConfigScopeStore;

/**
 * The Canada check must run against the scope being edited — never the
 * ambient store — or a store view with its own TaxCloud connection would be
 * checked with the default scope's.
 */
#[AllowMockObjectsWithoutExpectations]
class ConfigScopeStoreTest extends TestCase
{
    public function testStoreScopeIsUsedDirectly()
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects($this->never())->method('getWebsite');

        $this->assertSame('4', (new ConfigScopeStore($storeManager))->resolve('2', '4'));
    }

    public function testWebsiteScopeResolvesThroughTheWebsitesDefaultStore()
    {
        $defaultStore = $this->createMock(Store::class);
        $defaultStore->method('getId')->willReturn(9);
        $website = $this->createMock(Website::class);
        $website->method('getDefaultStore')->willReturn($defaultStore);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->with('2')->willReturn($website);

        $this->assertSame($defaultStore, (new ConfigScopeStore($storeManager))->resolve('2', ''));
    }

    public function testDefaultScopeAndUnknownWebsiteResolveToNull()
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willThrowException(new \RuntimeException('no such website'));
        $resolver = new ConfigScopeStore($storeManager);

        $this->assertNull($resolver->resolve(null, null));
        $this->assertNull($resolver->resolve('', ''));
        $this->assertNull($resolver->resolve('99', null));
    }
}
