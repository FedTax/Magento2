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
 * A short-lived v3 Bearer token exchanged from V1 credentials.
 *
 * Deliberately opaque in dumps and string context: the raw token authorizes
 * live API calls and must never leak into logs or error messages.
 */
class BearerToken
{
    /**
     * @var string
     */
    private $token;

    /**
     * Unix timestamp the token is valid to (from access_token_validTo).
     *
     * @var int
     */
    private $validTo;

    /**
     * @param string $token
     * @param int $validTo Unix timestamp
     */
    public function __construct(string $token, int $validTo)
    {
        $this->token = $token;
        $this->validTo = $validTo;
    }

    /**
     * @return string
     */
    public function getToken(): string
    {
        return $this->token;
    }

    /**
     * @return int
     */
    public function getValidTo(): int
    {
        return $this->validTo;
    }

    /**
     * Whether the token is still usable at $now, honoring a safety margin.
     *
     * @param int $now Unix timestamp
     * @param int $marginSeconds Treat the token as expired this long before validTo
     * @return bool
     */
    public function isValidAt(int $now, int $marginSeconds = 0): bool
    {
        return $now < ($this->validTo - $marginSeconds);
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return '[taxcloud bearer token, validTo=' . $this->validTo . ']';
    }

    /**
     * Keep the raw token out of var_dump / print_r output.
     *
     * @return array
     */
    public function __debugInfo(): array
    {
        return ['token' => '***', 'validTo' => $this->validTo];
    }
}
