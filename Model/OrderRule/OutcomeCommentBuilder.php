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

namespace Taxcloud\Magento2\Model\OrderRule;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Taxcloud\Magento2\Api\Data\OrderRuleInterface;

/**
 * Writes the order history comment that explains why a rule kept an order
 * from TaxCloud: which rule, what it matched on, and what that means.
 */
class OutcomeCommentBuilder
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var GroupRepositoryInterface
     */
    private $groupRepository;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param StoreManagerInterface $storeManager
     * @param GroupRepositoryInterface $groupRepository
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        GroupRepositoryInterface $groupRepository,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->storeManager = $storeManager;
        $this->groupRepository = $groupRepository;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * The comment for a decision, or null when none is due: an order that is
     * reported, or whose outcome came from the store view default, gets none.
     *
     * @param Decision $decision
     * @param Order $order
     * @return string|null
     */
    public function build(Decision $decision, Order $order): ?string
    {
        if ($decision->isStoreDefault() || $decision->getAction() === OrderRuleInterface::ACTION_REPORT) {
            return null;
        }

        $meaning = $decision->isSkip()
            ? __('TaxCloud was not used for this order: its tax was calculated by the store\'s own tax rules, '
                . 'and the order is not reported to TaxCloud.')
            : __('Tax was calculated by TaxCloud, but this order is not reported to TaxCloud.');

        $matched = $this->describeMatched($decision, $order);
        $reason = $matched === []
            ? __('Order rule "%1" applies to all orders.', $decision->getRuleName())
            : __('Order rule "%1" matched %2.', $decision->getRuleName(), implode(', ', $matched));

        return 'TaxCloud: ' . $meaning . ' ' . $reason;
    }

    /**
     * @param Decision $decision
     * @param Order $order
     * @return string[]
     */
    private function describeMatched(Decision $decision, Order $order): array
    {
        $parts = [];
        foreach ($decision->getMatched() as $filter => $value) {
            switch ($filter) {
                case Decision::MATCHED_STORE:
                    $parts[] = (string) __('store view %1', $this->storeLabel((int) $value));
                    break;
                case Decision::MATCHED_CUSTOMER_GROUP:
                    $parts[] = (string) __('customer group %1', $this->groupLabel((int) $value));
                    break;
                case Decision::MATCHED_PAYMENT_METHOD:
                    $title = $this->paymentTitle((string) $value, (int) $order->getStoreId());
                    $parts[] = (string) __('payment method %1', $this->withCode($title, (string) $value));
                    break;
                case Decision::MATCHED_SHIPPING_METHOD:
                    $parts[] = (string) __(
                        'shipping method %1',
                        $this->withCode((string) $order->getShippingDescription(), (string) $value)
                    );
                    break;
                case Decision::MATCHED_ORDER_PREFIX:
                    $parts[] = (string) __('order number prefix "%1"', $value);
                    break;
            }
        }
        return $parts;
    }

    /**
     * @param int $storeId
     * @return string
     */
    private function storeLabel(int $storeId): string
    {
        try {
            return (string) $this->storeManager->getStore($storeId)->getName();
        } catch (\Throwable $e) {
            return '#' . $storeId;
        }
    }

    /**
     * @param int $groupId
     * @return string
     */
    private function groupLabel(int $groupId): string
    {
        try {
            return (string) $this->groupRepository->getById($groupId)->getCode();
        } catch (\Throwable $e) {
            return '#' . $groupId;
        }
    }

    /**
     * The configured title, read from config rather than the method instance:
     * marketplace importers' methods may not resolve to an instance at all.
     *
     * @param string $code
     * @param int $storeId
     * @return string
     */
    private function paymentTitle(string $code, int $storeId): string
    {
        return (string) $this->scopeConfig->getValue(
            'payment/' . $code . '/title',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * "Label (code)", or just the code when there is no label.
     *
     * @param string $label
     * @param string $code
     * @return string
     */
    private function withCode(string $label, string $code): string
    {
        $label = trim($label);
        return $label === '' || $label === $code ? $code : $label . ' (' . $code . ')';
    }
}
