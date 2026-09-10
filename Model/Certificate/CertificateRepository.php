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

namespace Taxcloud\Magento2\Model\Certificate;

use Magento\Framework\Cache\FrontendInterface;
use Taxcloud\Magento2\Api\CertificateGatewayInterface;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\CacheKeyBuilder;

/**
 * A customer's certificates, read through the store's transport and cached.
 *
 * The cached unit is the whole SET for one TaxCloud identity, not one
 * certificate's states as before. That follows from a customer now being able
 * to hold several: the question asked on every tax calculation is "what does
 * this customer have", and answering it one certificate at a time would mean a
 * request per certificate.
 *
 * Two rules here are load-bearing rather than housekeeping:
 *
 * A FAILED READ IS NEVER CACHED. "This customer holds none" and "we could not
 * ask" are opposite answers to whether an order should be taxed. Caching the
 * second as the first would tax an exempt customer for the whole TTL over one
 * transient outage — so failures propagate and the caller fails closed each
 * time, rather than once and then silently.
 *
 * WRITES INVALIDATE IMMEDIATELY. A merchant who adds a certificate expects the
 * next order to be exempt, not the first order after the TTL expires.
 */
class CertificateRepository
{
    /**
     * Seconds to cache a customer's certificate set. Matches the TTL the
     * per-certificate cache used: certificates change rarely, and a merchant
     * who changes one in the TaxCloud portal has an explicit refresh.
     */
    public const CACHE_TTL = 3600;

    /**
     * Routed by store api_type. Injected as a proxy in di.xml: the router owns
     * the SOAP gateway, which owns the resolver, which owns this — so eager
     * construction here would close a dependency cycle.
     *
     * @var CertificateGatewayInterface
     */
    private $gateway;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var CacheKeyBuilder
     */
    private $cacheKeyBuilder;

    /**
     * @var FrontendInterface
     */
    private $cacheType;

    /**
     * @param CertificateGatewayInterface $gateway
     * @param TaxcloudConfig $config
     * @param CacheKeyBuilder $cacheKeyBuilder
     * @param FrontendInterface $cacheType
     */
    public function __construct(
        CertificateGatewayInterface $gateway,
        TaxcloudConfig $config,
        CacheKeyBuilder $cacheKeyBuilder,
        FrontendInterface $cacheType
    ) {
        $this->gateway = $gateway;
        $this->config = $config;
        $this->cacheKeyBuilder = $cacheKeyBuilder;
        $this->cacheType = $cacheType;
    }

    /**
     * Every certificate filed under a TaxCloud identity.
     *
     * @param string $customerIdentity
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return Certificate[]
     * @throws \Throwable When the certificates could not be retrieved
     */
    public function forCustomer($customerIdentity, $store = null)
    {
        $cacheKey = $this->cacheKey($customerIdentity, $store);

        $cached = $this->cacheType->load($cacheKey);
        if ($cached !== false && $cached !== null) {
            $decoded = json_decode((string) $cached, true);
            if (is_array($decoded)) {
                return $this->fromCache($decoded);
            }
        }

        // Propagates on failure by design — see the class docblock.
        $certificates = $this->gateway->listCertificates($customerIdentity, $store);

        $this->cacheType->save($this->toCache($certificates), $cacheKey, [], self::CACHE_TTL);

        return $certificates;
    }

    /**
     * File a new certificate and refresh the customer's set.
     *
     * @param string $customerIdentity
     * @param array<string, mixed> $data
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string New certificate id
     * @throws \Throwable
     */
    public function create($customerIdentity, array $data, $store = null)
    {
        $certificateId = $this->gateway->createCertificate($customerIdentity, $data, $store);
        $this->invalidate($customerIdentity, $store);

        return $certificateId;
    }

    /**
     * Delete a certificate and refresh the customer's set.
     *
     * Callers must have established ownership first — see
     * {@see CertificateResolver::belongsToCustomer()}. Neither this class nor
     * TaxCloud can tell whose certificate an identifier names.
     *
     * @param string $certificateId
     * @param string $customerIdentity
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return void
     * @throws \Throwable
     */
    public function delete($certificateId, $customerIdentity, $store = null)
    {
        $this->gateway->deleteCertificate($certificateId, $customerIdentity, $store);
        $this->invalidate($customerIdentity, $store);
    }

    /**
     * Drop a customer's cached set, so the next read goes to TaxCloud.
     *
     * @param string $customerIdentity
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return void
     */
    public function invalidate($customerIdentity, $store = null)
    {
        $this->cacheType->remove($this->cacheKey($customerIdentity, $store));
    }

    /**
     * @param string $customerIdentity
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string
     */
    private function cacheKey($customerIdentity, $store)
    {
        // Whichever transport this store uses, the account discriminator is
        // whatever identifies the TaxCloud account it talks to.
        $account = (string) $this->config->getRestConnectionId($store);
        if ($account === '') {
            $account = (string) $this->config->getApiId($store);
        }

        return $this->cacheKeyBuilder->forCustomerCertificates($customerIdentity, $account);
    }

    /**
     * @param Certificate[] $certificates
     * @return string
     */
    private function toCache(array $certificates)
    {
        $rows = [];
        foreach ($certificates as $certificate) {
            $rows[] = [
                'id' => $certificate->getCertificateId(),
                'customer' => $certificate->getCustomerId(),
                'states' => $certificate->getStates(),
                'disabled' => $certificate->isDisabled(),
                'single' => $certificate->isSinglePurchase(),
                'detail' => $certificate->getDetail(),
            ];
        }

        return (string) json_encode($rows);
    }

    /**
     * @param array<int, mixed> $rows
     * @return Certificate[]
     */
    private function fromCache(array $rows)
    {
        $certificates = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $certificates[] = new Certificate(
                (string) $row['id'],
                (string) ($row['customer'] ?? ''),
                is_array($row['states'] ?? null) ? $row['states'] : [],
                !empty($row['disabled']),
                !empty($row['single']),
                is_array($row['detail'] ?? null) ? $row['detail'] : []
            );
        }

        return $certificates;
    }
}
