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

namespace Taxcloud\Magento2\Logger\Processor;

/**
 * Stamps every TaxCloud log record with the request that wrote it.
 *
 * Concurrent requests (a checkout and an admin page, two shoppers) write to
 * the same file, and their records interleave by time. The correlation
 * context groups the records of one TaxCloud operation, but records written
 * outside an operation carry nothing — so without this, two requests' lines
 * cannot be told apart. Adds to the record's `extra`:
 *
 *  - `request`: a random id, fixed for this logger instance — one HTTP
 *    request, one CLI run, one cron or consumer process;
 *  - `pid`: the PHP process id, when the host allows reading it.
 *
 * Built to never break logging. Hardened hosts commonly list getmypid() in
 * disable_functions, where calling it is a fatal Error on PHP 8; it is only
 * called when it exists, and every step degrades to omitting the field. A
 * callable rather than a ProcessorInterface so one class serves Monolog 2
 * (array records, Magento 2.4.7) and Monolog 3 (LogRecord, 2.4.8+).
 */
class RequestIdentityProcessor
{
    /**
     * @var array<string, int|string>|null
     */
    private $identity;

    /**
     * @param array|\Monolog\LogRecord $record
     * @return array|\Monolog\LogRecord
     */
    public function __invoke($record)
    {
        try {
            $extra = $record['extra'] ?? [];
            $record['extra'] = (is_array($extra) ? $extra : []) + $this->identity();
        } catch (\Throwable $e) {
            // A record without request identity is still a record.
            return $record;
        }

        return $record;
    }

    /**
     * @return array<string, int|string>
     */
    private function identity(): array
    {
        if ($this->identity === null) {
            $identity = [];
            $request = $this->requestId();
            if ($request !== null) {
                $identity['request'] = $request;
            }
            $pid = $this->processId();
            if ($pid !== null) {
                $identity['pid'] = $pid;
            }
            $this->identity = $identity;
        }

        return $this->identity;
    }

    /**
     * @return string|null
     */
    private function requestId(): ?string
    {
        try {
            return bin2hex(random_bytes(4));
        } catch (\Throwable $e) {
            // No entropy source available: uniqid() needs none.
            try {
                return substr(hash('sha256', uniqid('', true)), 0, 8);
            } catch (\Throwable $e) {
                return null;
            }
        }
    }

    /**
     * @return int|null
     */
    private function processId(): ?int
    {
        // On PHP 8 a function in disable_functions does not exist at all, so
        // this check is the whole guard: calling it unchecked would be fatal.
        if (!function_exists('getmypid')) {
            return null;
        }
        $pid = getmypid();

        return is_int($pid) && $pid > 0 ? $pid : null;
    }
}
