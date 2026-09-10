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
 * TaxCloud v3 REST credential pair.
 *
 * Passed into the REST client rather than read inside it, so the client stays
 * scope-agnostic and callers control where the values come from — saved config
 * for gateway traffic, unsaved form input for the admin connection test.
 */
class RestCredentials
{
    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string
     */
    private $connectionId;

    /**
     * @param string $apiKey
     * @param string $connectionId
     */
    public function __construct(string $apiKey, string $connectionId)
    {
        $this->apiKey = $apiKey;
        $this->connectionId = $connectionId;
    }

    /**
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * @return string
     */
    public function getConnectionId(): string
    {
        return $this->connectionId;
    }
}
