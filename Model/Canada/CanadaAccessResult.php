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

namespace Taxcloud\Magento2\Model\Canada;

/**
 * Outcome of a Canada access check.
 *
 * Three outcomes, deliberately kept apart: only NOT_ENABLED means "ask
 * TaxCloud support to enable Canada". UNAVAILABLE means the check could not
 * tell (credentials, connection, network, configuration), and sending a
 * merchant to support about Canada in that case would send them for the
 * wrong reason.
 */
class CanadaAccessResult
{
    public const ENABLED = 'enabled';
    public const NOT_ENABLED = 'not_enabled';
    public const UNAVAILABLE = 'unavailable';

    /**
     * @var string
     */
    private $outcome;

    /**
     * @var \Magento\Framework\Phrase|string
     */
    private $message;

    /**
     * @var float|null
     */
    private $rate;

    /**
     * @var int|null
     */
    private $httpStatus;

    /**
     * @var int
     */
    private $durationMs;

    /**
     * @param string $outcome One of the class constants
     * @param \Magento\Framework\Phrase|string $message Merchant-facing explanation
     * @param float|null $rate Sample tax rate (fraction) when TaxCloud returned one
     * @param int|null $httpStatus Status of the sample lookup, when one was sent
     * @param int $durationMs Time spent talking to TaxCloud
     */
    public function __construct(
        string $outcome,
        $message,
        ?float $rate = null,
        ?int $httpStatus = null,
        int $durationMs = 0
    ) {
        $this->outcome = $outcome;
        $this->message = $message;
        $this->rate = $rate;
        $this->httpStatus = $httpStatus;
        $this->durationMs = $durationMs;
    }

    /**
     * @return string
     */
    public function getOutcome(): string
    {
        return $this->outcome;
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->outcome === self::ENABLED;
    }

    /**
     * @return \Magento\Framework\Phrase|string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return float|null
     */
    public function getRate(): ?float
    {
        return $this->rate;
    }

    /**
     * @return int|null
     */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * @return int
     */
    public function getDurationMs(): int
    {
        return $this->durationMs;
    }
}
