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
 * @copyright  2025 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Taxcloud\Magento2\Model\Tic;

use Taxcloud\Magento2\Api\TicLookupInterface;
use Taxcloud\Magento2\Model\Config\Source\ApiType;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;

/**
 * Store-aware dispatch for TIC lookup.
 *
 * The same shape as {@see \Taxcloud\Magento2\Model\Gateway\Router}, and
 * deliberately so: api_type decides which API a store talks to for everything
 * else, and a TIC picker that quietly ignored it would be the exception that
 * erodes the rule. A REST-selected store searches v3; a SOAP-selected store
 * searches its cached v1 catalogue.
 *
 * The store must be the one whose value is being edited — the store view for
 * the category and configuration fields, the default scope for the globally
 * scoped product attribute — never the ambient one.
 */
class TicLookupRouter implements TicLookupInterface
{
    /**
     * @var SoapTicLookup
     */
    private $soap;

    /**
     * @var RestTicLookup
     */
    private $rest;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @param SoapTicLookup $soap Proxied in di.xml so a REST-only fleet never builds the SOAP stack
     * @param RestTicLookup $rest Proxied in di.xml so a SOAP-only fleet never builds the REST stack
     * @param TaxcloudConfig $config
     */
    public function __construct(SoapTicLookup $soap, RestTicLookup $rest, TaxcloudConfig $config)
    {
        $this->soap = $soap;
        $this->rest = $rest;
        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function search(string $query, $store = null): TicSearchResult
    {
        return $this->target($store)->search($query, $store);
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $code, $store = null): TicSearchResult
    {
        return $this->target($store)->resolve($code, $store);
    }

    /**
     * Pick the backend for the store's api_type.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return TicLookupInterface
     */
    private function target($store): TicLookupInterface
    {
        if ($this->config->getApiType($store) === ApiType::REST) {
            return $this->rest;
        }

        return $this->soap;
    }
}
