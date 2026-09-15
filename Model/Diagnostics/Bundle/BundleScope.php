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

namespace Taxcloud\Magento2\Model\Diagnostics\Bundle;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;

/**
 * The part of the installation one bundle describes.
 *
 * A bundle generated at default scope covers every store; at website scope,
 * that website's stores; at store scope (and for every per-order bundle), one
 * store. Every store-scoped value in the bundle is resolved against the stores
 * listed here, never against the ambient store of the request that built it.
 */
class BundleScope
{
    /**
     * @var string
     */
    private $type;

    /**
     * @var int|null
     */
    private $id;

    /**
     * @var string
     */
    private $code;

    /**
     * @var StoreInterface[]
     */
    private $stores;

    /**
     * @var WebsiteInterface[]
     */
    private $websites;

    /**
     * @param string             $type     BundleRequest::SCOPE_*
     * @param int|null           $id       Website or store id; null at default scope
     * @param string             $code     Website or store code; 'default' at default scope
     * @param StoreInterface[]   $stores   Stores covered, keyed by id
     * @param WebsiteInterface[] $websites Websites those stores belong to, keyed by id
     */
    public function __construct(string $type, ?int $id, string $code, array $stores, array $websites)
    {
        $this->type = $type;
        $this->id = $id;
        $this->code = $code;
        $this->stores = $stores;
        $this->websites = $websites;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @return StoreInterface[]
     */
    public function getStores(): array
    {
        return $this->stores;
    }

    /**
     * @return WebsiteInterface[]
     */
    public function getWebsites(): array
    {
        return $this->websites;
    }

    /**
     * Short machine label, e.g. "default", "websites/base", "stores/default".
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->type === BundleRequest::SCOPE_DEFAULT ? 'default' : $this->type . '/' . $this->code;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $stores = [];
        foreach ($this->stores as $store) {
            $stores[] = [
                'id' => (int) $store->getId(),
                'code' => (string) $store->getCode(),
                'name' => (string) $store->getName(),
                'website_id' => (int) $store->getWebsiteId(),
            ];
        }

        return [
            'type' => $this->type,
            'id' => $this->id,
            'code' => $this->code,
            'stores' => $stores,
        ];
    }
}
