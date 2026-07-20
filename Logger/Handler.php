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

namespace Taxcloud\Magento2\Logger;

use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Logger\Handler\Base;

class Handler extends Base
{
    /**
     * Log file this handler writes to when nothing is injected, relative to the
     * Magento base directory. etc/di.xml binds the same value explicitly, so the
     * default survives even if a deployment's di.xml is overridden wholesale.
     */
    public const DEFAULT_FILE_NAME = '/var/log/taxcloud.log';

    /**
     * Logging level
     * @var int
     */
    protected $loggerType = \Monolog\Logger::INFO;

    /**
     * Operators can point TaxCloud logging elsewhere by overriding the fileName
     * argument in their own di.xml — see README "Changing the log file location".
     *
     * @param DriverInterface $filesystem
     * @param string|null $filePath
     * @param string|null $fileName
     * @throws \Exception
     */
    public function __construct(
        DriverInterface $filesystem,
        $filePath = null,
        $fileName = null
    ) {
        // ?: not ?? — an empty string would otherwise reach the parent as a falsy
        // fileName, leaving the stream URL pointing at the base directory itself.
        parent::__construct($filesystem, $filePath, $fileName ?: self::DEFAULT_FILE_NAME);
    }
}
