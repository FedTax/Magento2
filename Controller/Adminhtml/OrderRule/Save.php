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
use Magento\Framework\Exception\LocalizedException;
use Taxcloud\Magento2\Api\Data\OrderRuleInterface;
use Taxcloud\Magento2\Api\Data\OrderRuleInterfaceFactory;
use Taxcloud\Magento2\Api\OrderRuleRepositoryInterface;
use Taxcloud\Magento2\Model\OrderRule\Ui\FormDataProvider;

/**
 * Saves the rule form. Validation lives in the repository; its message is
 * shown on the form, which keeps what the administrator entered.
 */
class Save extends AbstractAction implements HttpPostActionInterface
{
    /**
     * @var OrderRuleRepositoryInterface
     */
    private $repository;

    /**
     * @var OrderRuleInterfaceFactory
     */
    private $ruleFactory;

    /**
     * @param Context $context
     * @param OrderRuleRepositoryInterface $repository
     * @param OrderRuleInterfaceFactory $ruleFactory
     */
    public function __construct(
        Context $context,
        OrderRuleRepositoryInterface $repository,
        OrderRuleInterfaceFactory $ruleFactory
    ) {
        parent::__construct($context);
        $this->repository = $repository;
        $this->ruleFactory = $ruleFactory;
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = (array) $this->getRequest()->getPostValue();
        $ruleId = (int) ($data[OrderRuleInterface::RULE_ID] ?? 0);

        try {
            $rule = $ruleId ? $this->repository->getById($ruleId) : $this->ruleFactory->create();
            $this->apply($rule, $data);
            $rule = $this->repository->save($rule);
            $this->messageManager->addSuccessMessage(__('The order rule has been saved.'));
            $this->_getSession()->setData(FormDataProvider::SESSION_KEY, null);

            if ($this->getRequest()->getParam('back')) {
                return $redirect->setPath('*/*/edit', ['rule_id' => $rule->getId()]);
            }
            return $redirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->messageManager->addExceptionMessage($e, __('The order rule could not be saved.'));
        }

        $this->_getSession()->setData(FormDataProvider::SESSION_KEY, $data);
        return $redirect->setPath('*/*/edit', $ruleId ? ['rule_id' => $ruleId] : []);
    }

    /**
     * Copy the posted form onto the rule. Multiselects arrive as arrays, or
     * not at all when nothing is selected; prefixes arrive as free text.
     *
     * @param OrderRuleInterface $rule
     * @param array $data
     * @return void
     */
    private function apply(OrderRuleInterface $rule, array $data): void
    {
        $rule->setName((string) ($data[OrderRuleInterface::NAME] ?? ''));
        $rule->setIsActive((bool) ($data[OrderRuleInterface::IS_ACTIVE] ?? false));
        $rule->setAction((string) ($data[OrderRuleInterface::ACTION] ?? ''));
        $rule->setStoreIds(self::listOf($data[OrderRuleInterface::STORE_IDS] ?? []));
        $rule->setCustomerGroupIds(self::listOf($data[OrderRuleInterface::CUSTOMER_GROUP_IDS] ?? []));
        $rule->setPaymentMethods(self::listOf($data[OrderRuleInterface::PAYMENT_METHODS] ?? []));
        $rule->setShippingMethods(self::listOf($data[OrderRuleInterface::SHIPPING_METHODS] ?? []));
        $rule->setOrderPrefixes(self::splitPrefixes((string) ($data[OrderRuleInterface::ORDER_PREFIXES] ?? '')));
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function listOf($value): array
    {
        if (!is_array($value)) {
            $value = $value === '' || $value === null ? [] : [$value];
        }
        return array_values(array_filter(array_map('strval', $value), static function (string $v): bool {
            return $v !== '';
        }));
    }

    /**
     * One prefix per line, or comma-separated.
     *
     * @param string $text
     * @return string[]
     */
    public static function splitPrefixes(string $text): array
    {
        return preg_split('/[\r\n,]+/', $text) ?: [];
    }
}
