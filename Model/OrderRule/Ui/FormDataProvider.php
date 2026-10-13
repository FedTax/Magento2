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

namespace Taxcloud\Magento2\Model\OrderRule\Ui;

use Magento\Backend\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Taxcloud\Magento2\Api\Data\OrderRuleInterface;
use Taxcloud\Magento2\Model\OrderRule\ResourceModel\Rule\CollectionFactory;

/**
 * Feeds the rule form: the rule being edited, defaults for a new one, or what
 * the administrator entered when a save was refused.
 */
class FormDataProvider extends AbstractDataProvider
{
    /**
     * Backend session key the Save controller leaves a refused submission in.
     */
    public const SESSION_KEY = 'taxcloud_order_rule_form';

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var Session
     */
    private $session;

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param CollectionFactory $collectionFactory
     * @param RequestInterface $request
     * @param Session $session
     * @param array $meta
     * @param array $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        RequestInterface $request,
        Session $session,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
        $this->request = $request;
        $this->session = $session;
    }

    /**
     * @return array
     */
    public function getData()
    {
        $ruleId = (int) $this->request->getParam($this->getRequestFieldName());

        $refused = $this->session->getData(self::SESSION_KEY, true);
        if (is_array($refused)) {
            $refused[OrderRuleInterface::RULE_ID] = $ruleId ?: null;
            return [$ruleId ?: '' => $refused];
        }

        if (!$ruleId) {
            return ['' => [
                OrderRuleInterface::IS_ACTIVE => '1',
                OrderRuleInterface::ACTION => OrderRuleInterface::ACTION_CALCULATE_ONLY,
            ]];
        }

        $this->collection->addFieldToFilter(OrderRuleInterface::RULE_ID, ['eq' => $ruleId]);
        $data = [];
        /** @var OrderRuleInterface $rule */
        foreach ($this->collection->getItems() as $rule) {
            $data[$rule->getId()] = $this->toFormData($rule);
        }
        return $data;
    }

    /**
     * @param OrderRuleInterface $rule
     * @return array
     */
    private function toFormData(OrderRuleInterface $rule): array
    {
        return [
            OrderRuleInterface::RULE_ID => $rule->getId(),
            OrderRuleInterface::NAME => $rule->getName(),
            OrderRuleInterface::IS_ACTIVE => $rule->isActive() ? '1' : '0',
            OrderRuleInterface::ACTION => $rule->getAction(),
            OrderRuleInterface::STORE_IDS => array_map('strval', $rule->getStoreIds()),
            OrderRuleInterface::CUSTOMER_GROUP_IDS => array_map('strval', $rule->getCustomerGroupIds()),
            OrderRuleInterface::PAYMENT_METHODS => $rule->getPaymentMethods(),
            OrderRuleInterface::SHIPPING_METHODS => $rule->getShippingMethods(),
            OrderRuleInterface::ORDER_PREFIXES => implode("\n", $rule->getOrderPrefixes()),
        ];
    }
}
