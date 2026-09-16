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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle\Log;

use Magento\Framework\App\Filesystem\DirectoryList;
use Monolog\Handler\StreamHandler;
use Taxcloud\Magento2\Logger\Handler;
use Taxcloud\Magento2\Logger\Logger;

/**
 * Where the TaxCloud log actually is.
 *
 * Read from the handler the object manager built for the TaxCloud channel —
 * i.e. from the DI-configured fileName argument — rather than assumed to be
 * var/log/taxcloud.log. The README tells operators how to relocate the log; a
 * merchant who did so must still get their log in the bundle.
 */
class LogPathResolver
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var DirectoryList
     */
    private $directoryList;

    /**
     * @param Logger        $logger
     * @param DirectoryList $directoryList
     */
    public function __construct(Logger $logger, DirectoryList $directoryList)
    {
        $this->logger = $logger;
        $this->directoryList = $directoryList;
    }

    /**
     * Absolute path of the TaxCloud log file.
     *
     * @return string
     */
    public function getTaxcloudLogPath(): string
    {
        $fallback = null;
        foreach ($this->logger->getHandlers() as $handler) {
            if (!$handler instanceof StreamHandler) {
                continue;
            }
            $url = $handler->getUrl();
            if ($url === null || $url === '' || strpos($url, 'php://') === 0) {
                continue;
            }
            if ($handler instanceof Handler) {
                return $url;
            }
            $fallback = $fallback ?? $url;
        }

        return $fallback ?? $this->directoryList->getRoot() . Handler::DEFAULT_FILE_NAME;
    }

    /**
     * Absolute path of a file in Magento's log directory (system.log, exception.log).
     *
     * @param string $fileName
     * @return string
     */
    public function getMagentoLogPath(string $fileName): string
    {
        return rtrim($this->directoryList->getPath(DirectoryList::LOG), '/') . '/' . $fileName;
    }
}
