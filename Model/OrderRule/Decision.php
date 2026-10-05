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

use Taxcloud\Magento2\Api\Data\OrderRuleInterface;

/**
 * The result of evaluating the rules for one quote or order: what to do, and
 * why — the deciding rule and the order values that matched its filters, or
 * the store view default when no rule matched.
 */
class Decision
{
    /**#@+
     * Keys of getMatched(), one per filter.
     */
    public const MATCHED_STORE = 'store';
    public const MATCHED_CUSTOMER_GROUP = 'customer_group';
    public const MATCHED_PAYMENT_METHOD = 'payment_method';
    public const MATCHED_SHIPPING_METHOD = 'shipping_method';
    public const MATCHED_ORDER_PREFIX = 'order_prefix';
    /**#@-*/

    /**
     * @var string
     */
    private $action;

    /**
     * @var int|null
     */
    private $ruleId;

    /**
     * @var string|null
     */
    private $ruleName;

    /**
     * @var array<string, int|string>
     */
    private $matched;

    /**
     * @param string $action One of OrderRuleInterface::ACTION_*
     * @param int|null $ruleId Null when the store view default decided
     * @param string|null $ruleName
     * @param array<string, int|string> $matched Filter key => the order's value that matched it
     */
    public function __construct(string $action, ?int $ruleId = null, ?string $ruleName = null, array $matched = [])
    {
        $this->action = $action;
        $this->ruleId = $ruleId;
        $this->ruleName = $ruleName;
        $this->matched = $matched;
    }

    /**
     * @return string
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * @return int|null
     */
    public function getRuleId(): ?int
    {
        return $this->ruleId;
    }

    /**
     * @return string|null
     */
    public function getRuleName(): ?string
    {
        return $this->ruleName;
    }

    /**
     * @return array<string, int|string>
     */
    public function getMatched(): array
    {
        return $this->matched;
    }

    /**
     * Whether no rule matched and the store view default decided.
     *
     * @return bool
     */
    public function isStoreDefault(): bool
    {
        return $this->ruleId === null;
    }

    /**
     * @return bool
     */
    public function isSkip(): bool
    {
        return $this->action === OrderRuleInterface::ACTION_SKIP;
    }
}
