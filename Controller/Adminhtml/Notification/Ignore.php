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

namespace Taxcloud\Magento2\Controller\Adminhtml\Notification;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Taxcloud\Magento2\Model\Diagnostics\Acknowledgement;
use Taxcloud\Magento2\Model\Diagnostics\TaxCollectorDiagnostics;

/**
 * Dismisses the tax collector notification, the way core's
 * \Magento\Tax\Controller\Adminhtml\Tax\IgnoreTaxNotification dismisses the tax
 * misconfiguration warning — with one difference that matters.
 *
 * Core stores a permanent boolean. This stores the fingerprint of the conflict
 * that was on screen, so the acknowledgement is scoped to that conflict: a
 * different module taking the slot later, or the same one spreading to another
 * store view, re-raises the banner without anyone having to notice.
 *
 * The submitted fingerprint is verified against the current verdict rather than
 * trusted, so a stale or hand-edited link cannot acknowledge a conflict the
 * admin never saw.
 */
class Ignore extends Action
{
    /**
     * Same resource that protects Stores → Configuration → Sales → Tax.
     */
    public const ADMIN_RESOURCE = 'Magento_Tax::config_tax';

    /**
     * @var Acknowledgement
     */
    private $acknowledgement;

    /**
     * @var TaxCollectorDiagnostics
     */
    private $diagnostics;

    /**
     * @param Context                 $context
     * @param Acknowledgement         $acknowledgement
     * @param TaxCollectorDiagnostics $diagnostics
     */
    public function __construct(
        Context $context,
        Acknowledgement $acknowledgement,
        TaxCollectorDiagnostics $diagnostics
    ) {
        parent::__construct($context);
        $this->acknowledgement = $acknowledgement;
        $this->diagnostics = $diagnostics;
    }

    /**
     * @return \Magento\Backend\Model\View\Result\Redirect
     */
    public function execute()
    {
        $submitted = (string) $this->getRequest()->getParam('state');
        $current = $this->diagnostics->verdict()->fingerprint();

        if ($submitted !== '' && $current !== '' && hash_equals($current, $submitted)) {
            try {
                $this->acknowledgement->acknowledge($current);
            } catch (\Exception $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }

        /** @var \Magento\Backend\Model\View\Result\Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setRefererUrl();
    }
}
