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

namespace Taxcloud\Magento2\Model\System\Message;

use Taxcloud\Magento2\Model\Diagnostics\CollectorVerdict;

/**
 * One finding within the TaxCloud collector notification.
 *
 * Mirrors \Magento\Tax\Model\System\Message\NotificationInterface, with the
 * verdict passed in rather than each finding resolving it: the aggregator
 * computes it once per render and hands the same value to every child, so the
 * findings can never describe different states of the same install.
 */
interface NotificationInterface
{
    /**
     * @return string
     */
    public function getIdentity();

    /**
     * @param CollectorVerdict $verdict
     * @return bool
     */
    public function isDisplayed(CollectorVerdict $verdict);

    /**
     * @param CollectorVerdict $verdict
     * @return string
     */
    public function getText(CollectorVerdict $verdict);
}
