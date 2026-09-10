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

namespace Taxcloud\Magento2\Model\Gateway\Rest;

/**
 * Immutable outcome of a single v3 REST call.
 *
 * Carries the HTTP status, the JSON-decoded body, and the raw body (for
 * advanced-mode logging), and centralizes the two judgments every operation
 * makes about a response: whether it is retryable ({@see isRetryable()},
 * 429/5xx per the module's retry semantics — other 4xx are terminal) and how
 * to describe a failure ({@see errorDetail()}, folding the v3 ErrorModel's
 * title/detail/errors[] into one log-friendly line).
 */
class RestResponse
{
    /**
     * @var int
     */
    private $status;

    /**
     * @var array<string, mixed>|null
     */
    private $body;

    /**
     * @var string
     */
    private $rawBody;

    /**
     * @param int $status HTTP status code
     * @param string $rawBody Raw response body (may be empty)
     */
    public function __construct(int $status, string $rawBody = '')
    {
        $this->status = $status;
        $this->rawBody = $rawBody;
        $decoded = json_decode($rawBody, true);
        $this->body = is_array($decoded) ? $decoded : null;
    }

    /**
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * JSON-decoded body, or null when the body is empty or not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    public function getBody(): ?array
    {
        return $this->body;
    }

    /**
     * @return string
     */
    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Retryable per the module's REST semantics: rate limiting and server-side
     * failures may be retried (for idempotent operations); every other client
     * error is terminal.
     *
     * @return bool
     */
    public function isRetryable(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }

    /**
     * @return bool
     */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /**
     * @return bool
     */
    public function isUnauthorized(): bool
    {
        return $this->status === 401;
    }

    /**
     * One-line failure description from the v3 ErrorModel
     * (title/detail/errors[].location+message), falling back to "HTTP <status>"
     * when the body carries no usable model. Never includes credentials: the
     * v3 API does not echo auth headers into error bodies.
     *
     * @return string
     */
    public function errorDetail(): string
    {
        $parts = [];
        if (is_array($this->body)) {
            foreach (['title', 'detail'] as $key) {
                if (!empty($this->body[$key]) && is_string($this->body[$key])) {
                    $parts[] = $this->body[$key];
                }
            }
            if (!empty($this->body['errors']) && is_array($this->body['errors'])) {
                $itemized = [];
                foreach ($this->body['errors'] as $error) {
                    if (!is_array($error)) {
                        continue;
                    }
                    $location = isset($error['location']) && is_string($error['location'])
                        ? $error['location'] . ': '
                        : '';
                    $message = isset($error['message']) && is_string($error['message'])
                        ? $error['message']
                        : '';
                    if ($location !== '' || $message !== '') {
                        $itemized[] = $location . $message;
                    }
                }
                if ($itemized !== []) {
                    $parts[] = '(' . implode('; ', $itemized) . ')';
                }
            }
        }

        if ($parts === []) {
            return 'HTTP ' . $this->status;
        }

        return 'HTTP ' . $this->status . ' ' . implode(' - ', $parts);
    }
}
