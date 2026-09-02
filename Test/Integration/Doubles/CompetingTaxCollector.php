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

namespace Taxcloud\Magento2\Test\Integration\Doubles;

use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;

/**
 * Stands in for a rival tax extension that has claimed Magento's tax total.
 *
 * Bound over the `Magento\Tax\Model\Sales\Total\Quote\Tax` preference at
 * runtime, this is what a store looks like when another module wins the slot:
 * something that is not TaxCloud's collector occupies it, and TaxCloud's
 * collect() is never called.
 *
 * Extends AbstractTotal because Collector::_initModelInstance() rejects
 * anything else, and calculates nothing — the point is that TaxCloud does not
 * run, not what the rival would have charged.
 *
 * It lives in the module's own test namespace so composer's PSR-4 mapping
 * autoloads it. That makes it read as "ours" to the probe's namespace filters,
 * which is irrelevant here: collector ownership is decided by an instanceof
 * check against Taxcloud\Magento2\Model\Tax, which this deliberately fails.
 */
class CompetingTaxCollector extends AbstractTotal
{
    /**
     * @param Quote                       $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param Total                       $total
     * @return $this
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function collect(
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ) {
        return $this;
    }
}
