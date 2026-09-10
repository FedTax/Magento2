<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics;

use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Diagnostics\Acknowledgement;

/**
 * Storage for a dismissed conflict.
 *
 * The writes here are rare and the reads are on every admin page render, so the
 * guards matter: clearing when nothing is stored would flush the config cache
 * on every page, and acknowledging an empty fingerprint would store a value
 * that matches a healthy verdict.
 */
#[AllowMockObjectsWithoutExpectations]
class AcknowledgementTest extends TestCase
{
    private function build(string $stored, ?ConfigResource $resource = null, ?TypeListInterface $cache = null): Acknowledgement
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($stored);

        return new Acknowledgement(
            $scopeConfig,
            $resource ?? $this->createMock(ConfigResource::class),
            $cache ?? $this->createMock(TypeListInterface::class)
        );
    }

    public function testMatchesTheStoredFingerprint()
    {
        $this->assertTrue($this->build('abc123')->matches('abc123'));
        $this->assertFalse($this->build('abc123')->matches('def456'));
    }

    /**
     * A healthy verdict fingerprints as '', which must never be treated as an
     * acknowledgement of anything.
     */
    public function testEmptyFingerprintNeverMatches()
    {
        $this->assertFalse($this->build('')->matches(''));
        $this->assertFalse($this->build('abc123')->matches(''));
    }

    public function testAcknowledgeStoresAtDefaultScopeAndFlushesConfigCache()
    {
        $resource = $this->createMock(ConfigResource::class);
        $resource->expects($this->once())
            ->method('saveConfig')
            ->with(Acknowledgement::XML_PATH_ACKNOWLEDGED, 'abc123', 'default', 0);

        $cache = $this->createMock(TypeListInterface::class);
        $cache->expects($this->once())->method('cleanType')->with('config');

        $this->build('', $resource, $cache)->acknowledge('abc123');
    }

    public function testAcknowledgeIgnoresAnEmptyFingerprint()
    {
        $resource = $this->createMock(ConfigResource::class);
        $resource->expects($this->never())->method('saveConfig');

        $this->build('', $resource)->acknowledge('');
    }

    public function testClearDeletesTheStoredValue()
    {
        $resource = $this->createMock(ConfigResource::class);
        $resource->expects($this->once())
            ->method('deleteConfig')
            ->with(Acknowledgement::XML_PATH_ACKNOWLEDGED, 'default', 0);

        $this->build('abc123', $resource)->clear();
    }

    /**
     * isDisplayed() calls clear() on every healthy admin page render, so this
     * guard is what keeps a config write and a cache flush off the hot path.
     */
    public function testClearIsANoOpWhenNothingIsStored()
    {
        $resource = $this->createMock(ConfigResource::class);
        $resource->expects($this->never())->method('deleteConfig');

        $cache = $this->createMock(TypeListInterface::class);
        $cache->expects($this->never())->method('cleanType');

        $this->build('', $resource, $cache)->clear();
    }
}
