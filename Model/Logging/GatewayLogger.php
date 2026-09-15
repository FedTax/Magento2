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

namespace Taxcloud\Magento2\Model\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Taxcloud\Magento2\Logger\Logger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * A logger that forwards to the TaxCloud channel according to the logging mode.
 *
 * Centralizes the "log to taxcloud.log iff the store setting is on" rule that
 * every gateway class previously reimplemented with an inline null-object,
 * so collaborators can just depend on a logger and call it unconditionally.
 *
 * The Basic/Advanced split rides on PSR log levels: call sites emit payload
 * dumps and wire traces at debug, everything else at info and above. Basic
 * mode forwards info+ only; Advanced forwards debug too.
 *
 * The logging mode is re-read on every call, against the store set via
 * setStore(). Operation entry points (the tax collector, the sales observers,
 * the gateway's public methods) set the current order's/quote's store there;
 * with no store set, the mode falls back to the ambient request store —
 * which in admin/cron contexts is the default store view, not the store of
 * the entity being processed.
 *
 * The same entry points bind a correlation context via beginOperation(): a
 * per-operation correlation id plus the quote id and order increment id when
 * known. Every record forwarded while a context is bound carries those values
 * in its context array, which the channel's line formatter renders as JSON at
 * the end of the line — so "every line for order 100000123" is one grep, and
 * the diagnostics bundle can extract an order's lines mechanically. Binding is
 * optional: with nothing bound, records pass through unchanged.
 */
class GatewayLogger extends AbstractLogger
{
    /**
     * @var Logger
     */
    private $inner;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * Store the logging mode is resolved against (id, code, or store object).
     *
     * @var int|string|\Magento\Store\Api\Data\StoreInterface|null
     */
    private $store = null;

    /**
     * Correlation context bound by the current operation; empty when none.
     *
     * @var array<string, string>
     */
    private $correlation = [];

    /**
     * @param Logger         $inner
     * @param TaxcloudConfig $config
     */
    public function __construct(Logger $inner, TaxcloudConfig $config)
    {
        $this->inner = $inner;
        $this->config = $config;
    }

    /**
     * Bind subsequent log calls to a store's logging configuration.
     *
     * This instance is shared (DI singleton), so setting the store at an
     * operation's entry point scopes every downstream collaborator's log call
     * to the same store without threading it through each logging statement.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store Null resets to the ambient store
     * @return void
     */
    public function setStore($store): void
    {
        $this->store = $store;
    }

    /**
     * Bind a correlation context for the operation starting now.
     *
     * Replaces whatever was bound before, so a long-running process (cron, a
     * queue consumer) never attributes one order's lines to the previous
     * order. The one exception is deliberate: when the context already bound
     * names the same order (or, with no order, the same quote), the
     * correlation id is kept — an observer and the gateway call it makes are
     * one operation, and splitting them across two ids would make the log
     * harder to read, not easier.
     *
     * @param string          $operation        lookup, verify_address, capture, refund, cancel, ...
     * @param int|string|null $quoteId
     * @param string|null     $orderIncrementId
     * @return string The correlation id now bound
     */
    public function beginOperation(string $operation, $quoteId = null, $orderIncrementId = null): string
    {
        $quoteId = ($quoteId === null || $quoteId === '') ? null : (string) $quoteId;
        $orderIncrementId = ($orderIncrementId === null || $orderIncrementId === '')
            ? null
            : (string) $orderIncrementId;

        $sameEntity = $this->correlation !== [] && (
            ($orderIncrementId !== null && ($this->correlation['order_increment_id'] ?? null) === $orderIncrementId)
            || ($orderIncrementId === null && $quoteId !== null
                && ($this->correlation['quote_id'] ?? null) === $quoteId)
        );

        $context = [
            'correlation_id' => $sameEntity ? $this->correlation['correlation_id'] : $this->newCorrelationId(),
            'operation' => $operation,
        ];
        if ($quoteId !== null) {
            $context['quote_id'] = $quoteId;
        } elseif ($sameEntity && isset($this->correlation['quote_id'])) {
            $context['quote_id'] = $this->correlation['quote_id'];
        }
        if ($orderIncrementId !== null) {
            $context['order_increment_id'] = $orderIncrementId;
        } elseif ($sameEntity && isset($this->correlation['order_increment_id'])) {
            $context['order_increment_id'] = $this->correlation['order_increment_id'];
        }

        $this->correlation = $context;

        return $context['correlation_id'];
    }

    /**
     * Keep the bound context for a call nested inside a running operation
     * (address verification runs inside a lookup), or begin a fresh one when
     * nothing is bound.
     *
     * @param string $operation
     * @return string The correlation id in effect
     */
    public function continueOperation(string $operation): string
    {
        if ($this->correlation === []) {
            return $this->beginOperation($operation);
        }

        return $this->correlation['correlation_id'];
    }

    /**
     * The currently bound correlation context (empty when none).
     *
     * @return array<string, string>
     */
    public function getCorrelationContext(): array
    {
        return $this->correlation;
    }

    /**
     * Unbind the correlation context.
     *
     * @return void
     */
    public function clearCorrelation(): void
    {
        $this->correlation = [];
    }

    /**
     * @inheritDoc
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $mode = $this->config->getLoggingMode($this->store);
        if ($mode === TaxcloudConfig::LOGGING_DISABLED) {
            return;
        }
        if ($level === LogLevel::DEBUG && $mode !== TaxcloudConfig::LOGGING_ADVANCED) {
            return;
        }
        // Trailing line breaks (raw HTTP response bodies end with one) would push
        // the context onto a line of its own, where a line-oriented grep for the
        // order number no longer finds the record.
        if (is_string($message)) {
            $message = rtrim($message, "\r\n");
        }

        // Caller-supplied keys win: a call site that deliberately logs another
        // order's id is not overwritten by the ambient binding.
        $this->inner->log($level, $message, $context + $this->correlation);
    }

    /**
     * Short random id: unique enough to tell concurrent operations apart in
     * one log, short enough to read and grep.
     *
     * @return string
     */
    private function newCorrelationId(): string
    {
        try {
            return bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            return substr(sha1(uniqid('', true)), 0, 12);
        }
    }
}
