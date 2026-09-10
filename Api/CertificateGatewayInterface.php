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

namespace Taxcloud\Magento2\Api;

use Taxcloud\Magento2\Model\Certificate\Certificate;

/**
 * Reading and writing a customer's TaxCloud exemption certificates, without
 * the caller knowing which API answered.
 *
 * Retrieval is BY CUSTOMER IDENTITY and only by customer identity. That is not
 * a simplification: v1 offers no way to fetch a certificate by its id at all,
 * so listing under an identity is the entire surface the two transports share.
 * A design built on v3's fetch-by-id would work on one transport only.
 *
 * The identity is TaxCloud's, not Magento's — see
 * {@see \Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity}. It
 * defaults to the Magento entity id but need not equal it, because certificates
 * created in the TaxCloud portal are filed under whatever a person typed there.
 *
 * These methods answer "what does TaxCloud hold", nothing more. Whether a
 * certificate may be APPLIED to a given customer's order is decided above this
 * seam, by {@see \Taxcloud\Magento2\Model\Certificate\CertificateResolver} —
 * which matters, because TaxCloud itself enforces no ownership whatsoever: it
 * will apply any certificate on the account to any cart that names it.
 */
interface CertificateGatewayInterface
{
    /**
     * Every certificate filed under a TaxCloud customer identity.
     *
     * Returning [] means the identity genuinely holds none. A retrieval that
     * FAILED must throw rather than return [], because the two are opposite
     * answers to "is this customer exempt" and collapsing them would tax an
     * exempt customer, or exempt a taxable one, on a transient error.
     *
     * @param string $customerIdentity TaxCloud customer identity
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store Store whose TaxCloud account applies
     * @return Certificate[]
     * @throws \Throwable When the certificates could not be retrieved
     */
    public function listCertificates($customerIdentity, $store = null);

    /**
     * File a new certificate under a TaxCloud customer identity.
     *
     * Certificates created here always apply to all of the customer's orders:
     * v3 cannot create a single-purchase certificate, and offering one only on
     * SOAP would make a customer-visible feature appear and disappear with the
     * store's API type.
     *
     * @param string $customerIdentity TaxCloud customer identity to file under
     * @param array<string, mixed> $data Purchaser detail, exemption reason,
     *        business type and covered states
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string The new certificate's id
     * @throws \Throwable When the certificate could not be created
     */
    public function createCertificate($customerIdentity, array $data, $store = null);

    /**
     * Delete a certificate.
     *
     * Callers must have established that the certificate belongs to the
     * customer concerned before calling this; the gateway cannot tell, and
     * TaxCloud will not refuse.
     *
     * @param string $certificateId
     * @param string $customerIdentity TaxCloud customer identity it is filed under
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return void
     * @throws \Throwable When the certificate could not be deleted
     */
    public function deleteCertificate($certificateId, $customerIdentity, $store = null);
}
