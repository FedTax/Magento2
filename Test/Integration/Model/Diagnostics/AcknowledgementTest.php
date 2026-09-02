<?php
/**
 * Taxcloud_Magento2
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @package    Taxcloud_Magento2
 * @author     TaxCloud <service@taxcloud.net>
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Model\Diagnostics;

use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Taxcloud\Magento2\Model\Diagnostics\Acknowledgement;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Dismissal against real core_config_data.
 *
 * The unit tests mock the config resource, so they prove the calls are made,
 * not that the value comes back. That matters here more than usual: the whole
 * reason this stores a fingerprint instead of core's permanent `1` is so a
 * dismissal stops applying when the conflict changes — and that only works if
 * the round trip through the database and the config cache actually returns
 * what was written.
 */
class AcknowledgementTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        // Written outside setScopedConfig()'s snapshotting, so cleaned by hand.
        $this->get(ConfigResource::class)->deleteConfig(
            Acknowledgement::XML_PATH_ACKNOWLEDGED,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            0
        );
        $this->get(ReinitableConfigInterface::class)->reinit();
        parent::tearDown();
    }

    private function acknowledgement(): Acknowledgement
    {
        // Config is re-read and the instance rebuilt each time: ScopeConfig
        // caches by path, so without this a write would not be visible to the
        // read that follows it.
        $this->get(ReinitableConfigInterface::class)->reinit();
        $this->mutateSharedInstances([Acknowledgement::class]);

        return $this->get(Acknowledgement::class);
    }

    public function testAcknowledgedFingerprintSurvivesTheRoundTrip(): void
    {
        $this->acknowledgement()->acknowledge('fingerprint-one');

        $this->assertTrue($this->acknowledgement()->matches('fingerprint-one'));
    }

    /**
     * The scenario core's permanent flag gets wrong.
     */
    public function testADifferentConflictIsNotCoveredByTheStoredAcknowledgement(): void
    {
        $this->acknowledgement()->acknowledge('fingerprint-one');

        $this->assertFalse($this->acknowledgement()->matches('fingerprint-two'));
    }

    public function testClearRemovesTheStoredAcknowledgement(): void
    {
        $this->acknowledgement()->acknowledge('fingerprint-one');
        $this->acknowledgement()->clear();

        $this->assertSame('', $this->acknowledgement()->get());
        $this->assertFalse($this->acknowledgement()->matches('fingerprint-one'));
    }

    /**
     * A healthy verdict fingerprints as '', which must never read as an
     * acknowledgement of anything.
     */
    public function testEmptyFingerprintIsNeverStoredOrMatched(): void
    {
        $this->acknowledgement()->acknowledge('');

        $this->assertSame('', $this->acknowledgement()->get());
        $this->assertFalse($this->acknowledgement()->matches(''));
    }
}
