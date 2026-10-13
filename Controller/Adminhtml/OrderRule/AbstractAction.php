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
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Controller\Adminhtml\OrderRule;

use Magento\Backend\App\Action;

/**
 * Base for the order processing rules screens.
 */
abstract class AbstractAction extends Action
{
    public const ADMIN_RESOURCE = 'Taxcloud_Magento2::order_rules';

    public const MENU_ID = 'Taxcloud_Magento2::order_rules';
}
