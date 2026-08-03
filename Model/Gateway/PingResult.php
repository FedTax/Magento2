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

/**
 * Outcome of a credential verification ping, transport-neutral.
 *
 * Normalizes both the v3 REST ping (401 → AUTH_FAILED, 404 →
 * UNKNOWN_CONNECTION) and the V1 SOAP Ping (non-OK envelope → AUTH_FAILED)
 * to the failure modes the admin form distinguishes. The reason never
 * contains credential values.
 */
class PingResult
{
    public const OK = 'ok';
    public const AUTH_FAILED = 'auth_failed';
    public const UNKNOWN_CONNECTION = 'unknown_connection';
    public const TRANSPORT_ERROR = 'transport_error';

    /**
     * @var string
     */
    private $outcome;

    /**
     * @var string
     */
    private $reason;

    /**
     * @param string $outcome One of the class constants
     * @param string $reason  Underlying detail for failures; never credential values
     */
    public function __construct(string $outcome, string $reason = '')
    {
        $this->outcome = $outcome;
        $this->reason = $reason;
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->outcome === self::OK;
    }

    /**
     * @return string
     */
    public function getOutcome(): string
    {
        return $this->outcome;
    }

    /**
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
