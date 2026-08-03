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
 * The v1→v3 credential exchange failed.
 *
 * Callers branch on the outcome: REJECTED means TaxCloud refused the
 * credential pair (fix the credentials); UNREACHABLE means the exchange could
 * not be completed (network, timeout, malformed response — fix connectivity
 * or retry). Messages never contain credential values.
 */
class TokenExchangeException extends \RuntimeException
{
    public const REJECTED = 'rejected';
    public const UNREACHABLE = 'unreachable';

    /**
     * @var string
     */
    private $outcome;

    /**
     * @param string $outcome One of the class constants
     * @param string $message Credential-free detail
     */
    public function __construct(string $outcome, string $message)
    {
        parent::__construct($message);
        $this->outcome = $outcome;
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
    public function isRejected(): bool
    {
        return $this->outcome === self::REJECTED;
    }
}
