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
 * @copyright  2021 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\Diagnostics;

use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Remembers which collector conflict an admin has dismissed.
 *
 * Core's tax notification stores a bare `1` in `tax/notification/ignore_*`:
 * once ignored, ignored forever, whatever happens to the setting afterwards.
 * Copying that here would put back the exact silence this feature exists to
 * remove — dismiss "Avalara owns the tax collector" during a planned migration,
 * and months later a different module takes the slot with the banner still
 * hidden.
 *
 * So what gets stored is a fingerprint of the conflict itself
 * ({@see CollectorVerdict::fingerprint()}). The notification stays hidden only
 * while the verdict still produces that fingerprint; a different responsible
 * class, or the same conflict reaching a new store, re-raises it unprompted.
 *
 * Written at default scope only. The conflict is global — DI preferences and
 * the merged sales.xml are not store-scoped — and the affected store ids are
 * folded into the fingerprint instead, which is what makes a conflict spreading
 * to another store view visible without a per-store dismissal UI.
 *
 * There is no system.xml field for this path, exactly as core has none for its
 * ignore flags: it is written by the dismissal controller and nothing else.
 */
class Acknowledgement
{
    /**
     * Config path holding the fingerprint of the acknowledged conflict.
     */
    public const XML_PATH_ACKNOWLEDGED = 'taxcloud/notification/acknowledged_collector_state';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ConfigResource
     */
    private $configResource;

    /**
     * @var TypeListInterface
     */
    private $cacheTypeList;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ConfigResource       $configResource
     * @param TypeListInterface    $cacheTypeList
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ConfigResource $configResource,
        TypeListInterface $cacheTypeList
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->configResource = $configResource;
        $this->cacheTypeList = $cacheTypeList;
    }

    /**
     * Fingerprint of the currently acknowledged conflict, or '' if none.
     *
     * @return string
     */
    public function get(): string
    {
        return (string) $this->scopeConfig->getValue(self::XML_PATH_ACKNOWLEDGED);
    }

    /**
     * Whether a fingerprint is the one that was dismissed.
     *
     * An empty fingerprint never matches: a healthy verdict has no conflict to
     * have been acknowledged.
     *
     * @param string $fingerprint
     * @return bool
     */
    public function matches(string $fingerprint): bool
    {
        if ($fingerprint === '') {
            return false;
        }

        return hash_equals($this->get(), $fingerprint);
    }

    /**
     * Record a dismissal.
     *
     * Cleans the config cache both to make the new value readable and because
     * the collector verdict is cached in that same type — the dismissal and the
     * state it acknowledges are invalidated together, never separately.
     *
     * @param string $fingerprint
     * @return void
     */
    public function acknowledge(string $fingerprint): void
    {
        if ($fingerprint === '') {
            return;
        }

        $this->configResource->saveConfig(
            self::XML_PATH_ACKNOWLEDGED,
            $fingerprint,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            0
        );
        $this->cacheTypeList->cleanType('config');
    }

    /**
     * Forget any dismissal.
     *
     * Called when the verdict goes healthy, so that a conflict which is fixed
     * and later returns identically raises the notification again rather than
     * being suppressed by an acknowledgement that outlived its subject.
     *
     * No-ops when nothing is stored, which is what keeps this off the hot path:
     * the write happens once, at the moment a dismissed conflict is resolved.
     *
     * @return void
     */
    public function clear(): void
    {
        if ($this->get() === '') {
            return;
        }

        $this->configResource->deleteConfig(
            self::XML_PATH_ACKNOWLEDGED,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            0
        );
        $this->cacheTypeList->cleanType('config');
    }
}
