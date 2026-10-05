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
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Whether a quote is to be taxed without TaxCloud ("Skip TaxCloud").
 *
 * The single answer every checkout touchpoint consults — the tax collector,
 * the Colorado Retail Delivery Fee collector, certificate recording and the
 * outcome recorder — so the tax charged and the outcome stored cannot
 * disagree. It follows the quote as it changes: choosing a payment or shipping
 * method a skip rule filters on flips the answer at the next collection.
 *
 * Only a Skip result matters here. Report and Calculate only both mean
 * "calculate with TaxCloud"; which of the two applies is decided on the placed
 * order, where the order number is known.
 */
class QuoteSkipResolver
{
    /**
     * Transient quote data holding [fingerprint, ?Decision]. No db_schema
     * column, so it is never persisted.
     */
    public const CACHE_KEY = 'taxcloud_skip_decision';

    /**
     * @var Evaluator
     */
    private $evaluator;

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
     * @param TaxcloudConfig $config
     * @param SubjectReader $subjectReader
     */
    public function __construct(Evaluator $evaluator, TaxcloudConfig $config, SubjectReader $subjectReader)
    {
        $this->evaluator = $evaluator;
        $this->config = $config;
        $this->subjectReader = $subjectReader;
    }

    /**
     * The skip decision for the quote as it stands, or null when TaxCloud
     * calculates it.
     *
     * @param Quote $quote
     * @return Decision|null
     */
    public function resolve(Quote $quote): ?Decision
    {
        // The quote's store: admin order creation and API checkouts run under
        // the default store view.
        if (!$this->config->isEnabled($quote->getStoreId())) {
            return null;
        }

        $subject = $this->subjectReader->fromQuote($quote);
        $fingerprint = $subject->fingerprint();

        $cached = $quote->getData(self::CACHE_KEY);
        if (is_array($cached) && ($cached[0] ?? null) === $fingerprint) {
            return $cached[1];
        }

        $decision = $this->evaluator->evaluate($subject);
        $result = $decision->isSkip() ? $decision : null;
        $quote->setData(self::CACHE_KEY, [$fingerprint, $result]);
        return $result;
    }
}
