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
use Magento\Framework\Controller\ResultFactory;
use Taxcloud\Magento2\Api\OrderRuleRepositoryInterface;

/**
 * Stores the evaluation order after a drag on the rules list (AJAX).
 */
class SaveOrder extends AbstractAction implements HttpPostActionInterface
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
        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        $ids = $this->getRequest()->getParam('rule_ids');
        if (!is_array($ids) || !$ids) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => (string) __('No rules to reorder.'),
            ]);
        }

        try {
            $this->repository->reorder(array_map('intval', $ids));
        } catch (\Throwable $e) {
            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => (string) __('The new order could not be saved. Reload the page and try again.'),
            ]);
        }

        return $result->setData(['success' => true]);
    }
}
