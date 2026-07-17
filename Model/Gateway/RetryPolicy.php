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

namespace Taxcloud\Magento2\Model\Gateway;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Retry discipline for gateway calls.
 *
 * Transient faults are retried after a short backoff; timeouts are never
 * retried (a second stall would only compound the wait) and are rethrown
 * immediately, as is the final exception once retries are exhausted — so each
 * call site's existing error handling still applies.
 */
class RetryPolicy
{
    /**
     * Default backoff between retry attempts, in microseconds.
     */
    public const DEFAULT_BACKOFF_US = 250000;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var int
     */
    private $backoffUs;

    /**
     * @param LoggerInterface|null $logger
     * @param int                  $backoffUs Backoff between attempts, in microseconds
     */
    public function __construct(?LoggerInterface $logger = null, $backoffUs = self::DEFAULT_BACKOFF_US)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->backoffUs = (int) $backoffUs;
    }

    /**
     * Execute $call, retrying up to $maxRetries times on transient faults.
     *
     * Timeouts are rethrown immediately and never retried. Any other fault is
     * retried after a short backoff until $maxRetries is exhausted, then the
     * final exception is rethrown.
     *
     * @param callable $call
     * @param int      $maxRetries Retries after the initial attempt (default 1)
     * @return mixed
     */
    public function execute(callable $call, $maxRetries = 1)
    {
        $attempt = 0;
        while (true) {
            try {
                return $call();
            } catch (Throwable $e) {
                if ($this->isTimeoutError($e) || $attempt >= $maxRetries) {
                    throw $e;
                }
                $attempt++;
                $this->logger->info(
                    'SOAP call failed, retrying (' . $attempt . '/' . $maxRetries
                    . ') after backoff: ' . $e->getMessage()
                );
                if ($this->backoffUs > 0) {
                    usleep($this->backoffUs);
                }
            }
        }
    }

    /**
     * Return whether a failure represents a connection or read timeout, based
     * on its fault code and message.
     *
     * @param Throwable $e
     * @return bool
     */
    public function isTimeoutError(Throwable $e)
    {
        if ($e instanceof \SoapFault && isset($e->faultcode)
            && stripos((string) $e->faultcode, 'HTTP') !== false) {
            return true;
        }
        return (bool) preg_match(
            '/timed out|timeout|Error Fetching http headers|Could not connect|failed to open/i',
            $e->getMessage()
        );
    }
}
