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
        $this->inner->log($level, $message, $context);
    }
}
