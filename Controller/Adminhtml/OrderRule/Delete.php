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

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Taxcloud\Magento2\Api\OrderRuleRepositoryInterface;

/**
 * Deletes a rule. Orders it already decided keep their stored outcome and
 * the rule's name.
 */
class Delete extends AbstractAction implements HttpPostActionInterface
{
    /**
     * @var OrderRuleRepositoryInterface
     */
    private $repository;

    /**
     * @param Context $context
     * @param OrderRuleRepositoryInterface $repository
     */
    public function __construct(Context $context, OrderRuleRepositoryInterface $repository)
    {
        parent::__construct($context);
        $this->repository = $repository;
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        try {
            $this->repository->deleteById((int) $this->getRequest()->getParam('rule_id'));
            $this->messageManager->addSuccessMessage(__('The order rule has been deleted.'));
        } catch (NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/');
    }
}
