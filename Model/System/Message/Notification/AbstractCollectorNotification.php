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

namespace Taxcloud\Magento2\Model\System\Message\Notification;

use Magento\Framework\Escaper;
use Taxcloud\Magento2\Model\Diagnostics\StoreVerdict;
use Taxcloud\Magento2\Model\System\Message\NotificationInterface;

/**
 * Shared rendering for the collector findings.
 *
 * Follows the layout of core's tax notifications — bold headline, then a
 * "Store(s) affected:" line — so the banner reads like the one merchants
 * already know from Stores → Configuration → Sales → Tax.
 */
abstract class AbstractCollectorNotification implements NotificationInterface
{
    /**
     * @var Escaper
     */
    protected $escaper;

    /**
     * @param Escaper $escaper
     */
    public function __construct(Escaper $escaper)
    {
        $this->escaper = $escaper;
    }

    /**
     * "Website (Store View)"-style list of the stores a finding applies to.
     *
     * @param StoreVerdict[] $verdicts
     * @return string
     */
    protected function formatStores(array $verdicts): string
    {
        $names = [];
        foreach ($verdicts as $verdict) {
            $names[] = $this->escaper->escapeHtml($verdict->getStoreName());
        }

        return implode(', ', array_unique($names));
    }

    /**
     * @param string[] $classes
     * @return string
     */
    protected function formatClasses(array $classes): string
    {
        $escaped = [];
        foreach ($classes as $class) {
            $escaped[] = $this->escaper->escapeHtml($class);
        }

        return implode(', ', $escaped);
    }

    /**
     * @param string         $headline
     * @param StoreVerdict[] $verdicts
     * @param string         $detail
     * @return string
     */
    protected function render(string $headline, array $verdicts, string $detail): string
    {
        return '<strong>' . $headline . '</strong><p>'
            . __('Store(s) affected: ') . $this->formatStores($verdicts)
            . '</p><p>' . $detail . '</p>';
    }
}
