<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Test\Unit\Model\Diagnostics;

use PHPUnit\Framework\TestCase;
use Taxcloud\Magento2\Model\Diagnostics\CollectorVerdict;
use Taxcloud\Magento2\Model\Diagnostics\StoreVerdict;

/**
 * The fingerprint is the whole reason dismissal is safe here.
 *
 * Core's tax notification stores a permanent boolean, so an acknowledgement
 * outlives the thing it acknowledged. These tests pin the property that
 * replaces it: the fingerprint must be stable for one conflict and must change
 * the moment the conflict does — otherwise a dismissed banner goes quiet
 * forever and the silent failure this feature exists to end comes straight back.
 */
class CollectorVerdictTest extends TestCase
{
    private function healthy(int $storeId = 1): StoreVerdict
    {
        return new StoreVerdict($storeId, 'Store ' . $storeId, \Taxcloud\Magento2\Model\Tax::class, true);
    }

    private function overridden(int $storeId = 1, string $winner = 'Competitor\\Tax\\Model\\Total'): StoreVerdict
    {
        return new StoreVerdict($storeId, 'Store ' . $storeId, $winner, false);
    }

    public function testHealthyWhenEveryStoreIsHealthy()
    {
        $verdict = new CollectorVerdict([$this->healthy(1), $this->healthy(2)]);

        $this->assertTrue($verdict->isHealthy());
        $this->assertSame([], $verdict->getUnhealthyStoreVerdicts());
    }

    /**
     * An install with TaxCloud switched off everywhere has no store whose tax
     * we were expected to calculate, so there is nothing to warn about.
     */
    public function testEmptyVerdictIsHealthy()
    {
        $this->assertTrue((new CollectorVerdict([]))->isHealthy());
    }

    public function testUnhealthyWhenAnyStoreIsUnhealthy()
    {
        $verdict = new CollectorVerdict([$this->healthy(1), $this->overridden(2)]);

        $this->assertFalse($verdict->isHealthy());
        $this->assertCount(1, $verdict->getUnhealthyStoreVerdicts());
        $this->assertSame(2, $verdict->getUnhealthyStoreVerdicts()[0]->getStoreId());
    }

    public function testHealthyVerdictHasEmptyFingerprint()
    {
        $this->assertSame('', (new CollectorVerdict([$this->healthy()]))->fingerprint());
    }

    public function testFingerprintIsStableForTheSameConflict()
    {
        $a = new CollectorVerdict([$this->overridden(1), $this->healthy(2)]);
        $b = new CollectorVerdict([$this->overridden(1), $this->healthy(2)]);

        $this->assertNotSame('', $a->fingerprint());
        $this->assertSame($a->fingerprint(), $b->fingerprint());
    }

    /**
     * Store order must not matter: the collector list is assembled by iterating
     * StoreManager, and a store added or reordered elsewhere would otherwise
     * re-raise a banner nobody's configuration actually changed.
     */
    public function testFingerprintIgnoresStoreOrdering()
    {
        $a = new CollectorVerdict([$this->overridden(1), $this->overridden(2)]);
        $b = new CollectorVerdict([$this->overridden(2), $this->overridden(1)]);

        $this->assertSame($a->fingerprint(), $b->fingerprint());
    }

    /**
     * The scenario core's boolean gets wrong: dismissed for one module, then a
     * different module takes the slot.
     */
    public function testFingerprintChangesWhenTheResponsibleClassChanges()
    {
        $a = new CollectorVerdict([$this->overridden(1, 'Avalara\\Excise\\Model\\Total')]);
        $b = new CollectorVerdict([$this->overridden(1, 'Vertex\\Tax\\Model\\Total')]);

        $this->assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    /**
     * Store awareness without a per-store dismissal UI: the same conflict
     * reaching a store view it did not affect before is a new conflict.
     */
    public function testFingerprintChangesWhenTheConflictSpreadsToAnotherStore()
    {
        $a = new CollectorVerdict([$this->overridden(1)]);
        $b = new CollectorVerdict([$this->overridden(1), $this->overridden(2)]);

        $this->assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    /**
     * Healthy stores are not part of the conflict, so enabling TaxCloud on a
     * store that then works must not re-raise a dismissed banner.
     */
    public function testFingerprintIgnoresHealthyStores()
    {
        $a = new CollectorVerdict([$this->overridden(1)]);
        $b = new CollectorVerdict([$this->overridden(1), $this->healthy(2)]);

        $this->assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function testFingerprintDistinguishesInterceptionFromOverride()
    {
        $override = new CollectorVerdict([$this->overridden(1, 'Competitor\\Plugin')]);
        $intercepted = new CollectorVerdict([
            new StoreVerdict(1, 'Store 1', \Taxcloud\Magento2\Model\Tax::class, true, ['Competitor\\Plugin']),
        ]);

        $this->assertNotSame($override->fingerprint(), $intercepted->fingerprint());
    }

    public function testFailureIsUnhealthyAndFingerprinted()
    {
        $verdict = new CollectorVerdict([
            new StoreVerdict(1, 'Store 1', null, false, [], [], 'boom'),
        ]);

        $this->assertFalse($verdict->isHealthy());
        $this->assertTrue($verdict->hasFailures());
        $this->assertNotSame('', $verdict->fingerprint());
    }

    public function testForStoreReturnsMatchingVerdictOrNull()
    {
        $verdict = new CollectorVerdict([$this->healthy(1), $this->overridden(7)]);

        $this->assertSame(7, $verdict->forStore(7)->getStoreId());
        $this->assertNull($verdict->forStore(99));
        $this->assertSame([1, 7], $verdict->getStoreIds());
    }

    /**
     * Later collectors are advisory: loyalty and fee modules sit after tax on
     * healthy installs, and treating that as a fault would train admins to
     * dismiss a banner that sometimes carries a real finding.
     */
    public function testLaterCollectorsAloneDoNotMakeAStoreUnhealthy()
    {
        $verdict = new CollectorVerdict([
            new StoreVerdict(1, 'Store 1', \Taxcloud\Magento2\Model\Tax::class, true, [], ['Loyalty\\Total']),
        ]);

        $this->assertTrue($verdict->isHealthy());
        $this->assertSame('', $verdict->fingerprint());
    }
}
