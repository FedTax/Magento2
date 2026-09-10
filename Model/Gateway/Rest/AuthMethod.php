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
 * Resolved authentication for one v3 REST request: the header(s) to send and
 * whether they came from a (refreshable) Bearer exchange.
 */
class AuthMethod
{
    /**
     * @var array<string, string>
     */
    private $headers;

    /**
     * @var bool
     */
    private $bearer;

    /**
     * @param array<string, string> $headers
     * @param bool $bearer
     */
    public function __construct(array $headers, bool $bearer)
    {
        $this->headers = $headers;
        $this->bearer = $bearer;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Whether a 401 can be answered by refreshing the token and retrying.
     *
     * @return bool
     */
    public function isBearer(): bool
    {
        return $this->bearer;
    }
}
