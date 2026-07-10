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
use Taxcloud\Magento2\Logger\Logger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * A logger that forwards to the TaxCloud channel only when logging is enabled.
 *
 * Centralizes the "log to taxcloud.log iff the store setting is on" rule that
 * every gateway class previously reimplemented with an inline null-object,
 * so collaborators can just depend on a logger and call it unconditionally.
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
     * @param Logger         $inner
     * @param TaxcloudConfig $config
     */
    public function __construct(Logger $inner, TaxcloudConfig $config)
    {
        $this->inner = $inner;
        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($this->config->isLoggingEnabled()) {
            $this->inner->log($level, $message, $context);
        }
    }
}
