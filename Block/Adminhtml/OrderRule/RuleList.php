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

namespace Taxcloud\Magento2\Block\Adminhtml\OrderRule;

use Magento\Backend\Block\Widget\Container;
use Magento\Backend\Block\Widget\Context;
use Taxcloud\Magento2\Api\Data\OrderRuleInterface;
use Taxcloud\Magento2\Api\OrderRuleRepositoryInterface;
use Taxcloud\Magento2\Model\Config\Source\OrderRuleAction;
use Taxcloud\Magento2\Model\OrderRule\RuleSummary;

/**
 * The rules list: every rule in evaluation order, reorderable by drag.
 */
class RuleList extends Container
{
    /**
     * @var string
     */
    protected $_template = 'Taxcloud_Magento2::order_rule/list.phtml';

    /**
     * @var OrderRuleRepositoryInterface
     */
    private $repository;

    /**
     * @var RuleSummary
     */
    private $summary;

    /**
     * @var OrderRuleAction
     */
    private $actions;

    /**
     * @param Context $context
     * @param OrderRuleRepositoryInterface $repository
     * @param RuleSummary $summary
     * @param OrderRuleAction $actions
     * @param array $data
     */
    public function __construct(
        Context $context,
        OrderRuleRepositoryInterface $repository,
        RuleSummary $summary,
        OrderRuleAction $actions,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->repository = $repository;
        $this->summary = $summary;
        $this->actions = $actions;
    }

    /**
     * @return $this
     */
    protected function _prepareLayout()
    {
        $this->addButton('add', [
            'label' => __('Add Rule'),
            'class' => 'primary',
            'onclick' => sprintf("setLocation('%s')", $this->escapeJs($this->getNewUrl())),
        ]);
        return parent::_prepareLayout();
    }

    /**
     * @return OrderRuleInterface[]
     */
    public function getRules(): array
    {
        return $this->repository->getList();
    }

    /**
     * @param OrderRuleInterface $rule
     * @return string[]
     */
    public function getSummary(OrderRuleInterface $rule): array
    {
        return $this->summary->describe($rule);
    }

    /**
     * @param OrderRuleInterface $rule
     * @return string
     */
    public function getActionLabel(OrderRuleInterface $rule): string
    {
        return $this->actions->getLabel($rule->getAction());
    }

    /**
     * @param OrderRuleInterface $rule
     * @return string
     */
    public function getEditUrl(OrderRuleInterface $rule): string
    {
        return $this->getUrl('*/*/edit', ['rule_id' => $rule->getId()]);
    }

    /**
     * @param OrderRuleInterface $rule
     * @return string
     */
    public function getDeleteUrl(OrderRuleInterface $rule): string
    {
        return $this->getUrl('*/*/delete', ['rule_id' => $rule->getId()]);
    }

    /**
     * @return string
     */
    public function getNewUrl(): string
    {
        return $this->getUrl('*/*/new');
    }

    /**
     * @return string
     */
    public function getSaveOrderUrl(): string
    {
        return $this->getUrl('*/*/saveOrder');
    }

    /**
     * Where the store view default ("Report orders to TaxCloud") is set.
     *
     * @return string
     */
    public function getSettingsUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'tax']);
    }
}
