<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Cache\Manager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Taxcloud\Magento2\Model\Cache\Type\Taxcloud;
use Taxcloud\Magento2\Setup\Patch\Data\EnableTaxcloudCache;

/**
 * Covers the patch that switches the TaxCloud cache type on, including the
 * fail-soft path: a deploy where app/etc/env.php is not writable must still
 * complete setup:upgrade.
 */
#[AllowMockObjectsWithoutExpectations]
class EnableTaxcloudCacheTest extends TestCase
{
    private function patch(Manager $cacheManager, ?LoggerInterface $logger = null): EnableTaxcloudCache
    {
        return new EnableTaxcloudCache($cacheManager, $logger ?? $this->createMock(LoggerInterface::class));
    }

    public function testGetDependenciesIsEmpty()
    {
        $this->assertSame([], EnableTaxcloudCache::getDependencies());
    }

    public function testGetAliasesIsEmpty()
    {
        $this->assertSame([], $this->patch($this->createMock(Manager::class))->getAliases());
    }

    public function testApplyEnablesTheTaxcloudCacheType()
    {
        $cacheManager = $this->createMock(Manager::class);
        $cacheManager->expects($this->once())
            ->method('setEnabled')
            ->with([Taxcloud::TYPE_IDENTIFIER], true);

        $patch = $this->patch($cacheManager);

        $this->assertSame($patch, $patch->apply(), 'apply() returns $this per DataPatchInterface');
    }

    /**
     * Losing the cache costs performance; failing the upgrade costs the deploy.
     * The patch must swallow the error and say what to run by hand.
     */
    public function testApplyDoesNotFailTheUpgradeWhenEnablingThrows()
    {
        $cacheManager = $this->createMock(Manager::class);
        $cacheManager->method('setEnabled')
            ->willThrowException(new \RuntimeException('env.php is not writable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->logicalAnd(
                $this->stringContains('env.php is not writable'),
                $this->stringContains('cache:enable ' . Taxcloud::TYPE_IDENTIFIER)
            ));

        $patch = $this->patch($cacheManager, $logger);

        $this->assertSame($patch, $patch->apply());
    }
}
