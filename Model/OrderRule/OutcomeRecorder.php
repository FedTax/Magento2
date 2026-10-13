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

use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Decides an order's processing outcome once, when it is placed, and stores
 * it on the order with the rule that decided it.
 *
 * Everything that later records or reverses the sale reads the stored value
 * (see ReportingPolicy), so editing or reordering rules afterwards never
 * changes what happens to an order already placed.
 */
class OutcomeRecorder
{
    /**#@+
     * sales_order columns.
     */
    public const FIELD_OUTCOME = 'taxcloud_outcome';
    public const FIELD_RULE_ID = 'taxcloud_outcome_rule_id';
    public const FIELD_RULE_NAME = 'taxcloud_outcome_rule_name';
    /**#@-*/

    /**
     * @var Evaluator
     */
    private $evaluator;

    /**
     * @var QuoteSkipResolver
     */
    private $skipResolver;

    /**
     * @var OutcomeCommentBuilder
     */
    private $commentBuilder;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var SubjectReader
     */
    private $subjectReader;

    /**
     * @param Evaluator $evaluator
     * @param QuoteSkipResolver $skipResolver
     * @param OutcomeCommentBuilder $commentBuilder
     * @param TaxcloudConfig $config
     * @param SubjectReader $subjectReader
     */
    public function __construct(
        Evaluator $evaluator,
        QuoteSkipResolver $skipResolver,
        OutcomeCommentBuilder $commentBuilder,
        TaxcloudConfig $config,
        SubjectReader $subjectReader
    ) {
        $this->evaluator = $evaluator;
        $this->skipResolver = $skipResolver;
        $this->commentBuilder = $commentBuilder;
        $this->config = $config;
        $this->subjectReader = $subjectReader;
    }

    /**
     * Decide and store the order's outcome, unless it already has one.
     *
     * A quote skipped at checkout makes the order Skip, whatever its number
     * would now match: the tax already charged is Magento's, and the stored
     * outcome must agree with it. Without a quote, or when the quote was not
     * skipped, the placed order is evaluated with every filter available.
     *
     * Does not save the order; callers run before the order's own save.
     *
     * @param Order $order
     * @param Quote|null $quote The quote the order came from, when in hand
     * @return Decision|null The decision recorded now, or null when nothing was recorded
     */
    public function record(Order $order, ?Quote $quote = null): ?Decision
    {
        if ($order->getData(self::FIELD_OUTCOME)) {
            return null;
        }

        // Rules have no effect on a store view with TaxCloud off, and such an
        // order is left without an outcome — like one placed before rules.
        if (!$this->config->isEnabled($order->getStoreId())) {
            return null;
        }

        $decision = $quote ? $this->skipResolver->resolve($quote) : null;
        if ($decision === null) {
            $decision = $this->evaluator->evaluate($this->subjectReader->fromOrder($order));
        }

        $order->setData(self::FIELD_OUTCOME, $decision->getAction());
        $order->setData(self::FIELD_RULE_ID, $decision->getRuleId());
        $order->setData(self::FIELD_RULE_NAME, $decision->getRuleName());

        $comment = $this->commentBuilder->build($decision, $order);
        if ($comment !== null) {
            $order->addCommentToStatusHistory($comment, false, false)->setIsCustomerNotified(0);
        }

        return $decision;
    }
}
