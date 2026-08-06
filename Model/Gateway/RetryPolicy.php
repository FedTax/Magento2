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
 * A retryability predicate decides which failures are worth another attempt;
 * those are retried after a short backoff, the rest are rethrown immediately.
 * The final exception is rethrown once retries are exhausted, so each call
 * site's existing error handling still applies either way.
 *
 * The predicate can be bound per gateway at construction or overridden per
 * call. Without one, {@see self::isRetryableByDefault()} retries everything
 * except timeouts, since a second stall would only compound the wait.
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
     * Default retryability test for this instance, or null to use
     * {@see self::isRetryableByDefault()}.
     *
     * @var callable|null
     */
    private $isRetryable;

    /**
     * A transport binds its own retryability rule here — SOAP by fault code and
     * message, REST by status class (retry 5xx, never 4xx). An invokable object
     * satisfies the callable hint, so this is configurable per gateway in di.xml.
     *
     * @param LoggerInterface|null $logger
     * @param int                  $backoffUs   Backoff between attempts, in microseconds
     * @param callable|null        $isRetryable fn(Throwable): bool
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        $backoffUs = self::DEFAULT_BACKOFF_US,
        ?callable $isRetryable = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->backoffUs = (int) $backoffUs;
        $this->isRetryable = $isRetryable;
    }

    /**
     * Execute $call, retrying up to $maxRetries times while the failure is
     * retryable.
     *
     * A fault the predicate rejects is rethrown immediately; a retryable one is
     * retried after a short backoff until $maxRetries is exhausted, then the
     * final exception is rethrown. Either way the exception reaches the call
     * site, so existing error handling still applies.
     *
     * @param callable      $call
     * @param int           $maxRetries  Retries after the initial attempt (default 1)
     * @param callable|null $isRetryable fn(Throwable): bool, overriding the
     *                                   instance default for this call only
     * @return mixed
     */
    public function execute(callable $call, $maxRetries = 1, ?callable $isRetryable = null)
    {
        $retryable = $isRetryable ?? $this->isRetryable ?? [$this, 'isRetryableByDefault'];

        $attempt = 0;
        while (true) {
            try {
                return $call();
            } catch (Throwable $e) {
                if (!$retryable($e) || $attempt >= $maxRetries) {
                    throw $e;
                }
                $attempt++;
                $this->logger->warning(
                    'Gateway call failed, retrying (' . $attempt . '/' . $maxRetries
                    . ') after backoff: ' . get_class($e) . ': ' . $e->getMessage()
                );
                if ($this->backoffUs > 0) {
                    usleep($this->backoffUs);
                }
            }
        }
    }

    /**
     * Execute $call returning a {@see Rest\RestResponse}, retrying while the
     * outcome is retryable.
     *
     * REST failures come in two forms with different retry evidence: a thrown
     * {@see Rest\RestTransportException} (no HTTP response — judged by the
     * throwable predicate, exactly like {@see execute()}) and a returned
     * response whose status marks it retryable (429/5xx —
     * {@see Rest\RestResponse::isRetryable()}). Both consume the same retry
     * budget. The final response is returned even when still failing, so call
     * sites keep their own status handling; a terminal response (2xx or other
     * 4xx) is returned immediately.
     *
     * Non-idempotent operations (order creation, refunds) pass
     * {@see isRetryableForNonIdempotent} as the throwable predicate and — by
     * that same never-reached-TaxCloud logic — must not retry on any HTTP
     * response, so they use $retryResponses = false: a status in hand proves
     * the request was processed enough to be dangerous to repeat.
     *
     * @param callable      $call            fn(): Rest\RestResponse
     * @param int           $maxRetries      Retries after the initial attempt (default 1)
     * @param callable|null $isRetryable     fn(Throwable): bool, overriding the
     *                                       instance default for this call only
     * @param bool          $retryResponses  Whether retryable HTTP responses may be retried
     * @return Rest\RestResponse
     */
    public function executeForResponse(
        callable $call,
        $maxRetries = 1,
        ?callable $isRetryable = null,
        bool $retryResponses = true
    ) {
        $retryable = $isRetryable ?? $this->isRetryable ?? [$this, 'isRetryableByDefault'];

        $attempt = 0;
        while (true) {
            try {
                $response = $call();
            } catch (Throwable $e) {
                if (!$retryable($e) || $attempt >= $maxRetries) {
                    throw $e;
                }
                $attempt++;
                $this->logger->warning(
                    'Gateway call failed, retrying (' . $attempt . '/' . $maxRetries
                    . ') after backoff: ' . get_class($e) . ': ' . $e->getMessage()
                );
                if ($this->backoffUs > 0) {
                    usleep($this->backoffUs);
                }
                continue;
            }

            if (!$retryResponses || !$response->isRetryable() || $attempt >= $maxRetries) {
                return $response;
            }
            $attempt++;
            $this->logger->warning(
                'Gateway call returned retryable status ' . $response->getStatus()
                . ', retrying (' . $attempt . '/' . $maxRetries . ') after backoff'
            );
            if ($this->backoffUs > 0) {
                usleep($this->backoffUs);
            }
        }
    }

    /**
     * Retryability rule used when no predicate is supplied: retry anything
     * except a timeout, since a second stall would only compound the wait.
     *
     * @param Throwable $e
     * @return bool
     */
    public function isRetryableByDefault(Throwable $e)
    {
        return !$this->isTimeoutError($e);
    }

    /**
     * Retryability rule for operations TaxCloud does not deduplicate — SOAP v1
     * Returned above all, where a repeated call books a second refund rather
     * than being recognized as the same one. (AuthorizedWithCapture is safe
     * without this: TaxCloud answers a repeat with "already been marked as
     * authorized", which the caller treats as success.)
     *
     * Only a failure that proves the request never left the client is retried.
     * Anything that happened after it was sent — an application fault, an
     * HTTP-level fault, a truncated or unparseable response — may correspond to
     * a return TaxCloud has already recorded, and retrying would double it.
     *
     * @param Throwable $e
     * @return bool
     */
    public function isRetryableForNonIdempotent(Throwable $e)
    {
        return $this->isConnectionFailure($e);
    }

    /**
     * Return whether a failure happened before the request reached TaxCloud —
     * no socket, no name resolution, no TLS — so nothing can have been
     * processed on the other end.
     *
     * Deliberately narrow: an ambiguous failure (a read timeout, "Error
     * Fetching http headers") means the request may well have been processed,
     * and must not be reported as undelivered.
     *
     * @param Throwable $e
     * @return bool
     */
    public function isConnectionFailure(Throwable $e)
    {
        // First alternation group: SOAP-extension wording. Second group: curl
        // wording (the REST transport) — all connect-stage only. "Operation
        // timed out" (curl's total-time limit) is deliberately absent: by then
        // the request may have been sent and processed.
        return (bool) preg_match(
            '/Could not connect to host|failed to open|Connection refused'
            . '|Name or service not known|Temporary failure in name resolution'
            . '|Could not resolve host|Couldn\'t connect to server|Failed to connect to'
            . '|Connection timed out/i',
            $e->getMessage()
        );
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
