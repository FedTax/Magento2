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

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;

/**
 * Writes the certificate attached to a customer.
 *
 * The resolver has always preferred this attachment and the foundation built
 * the storage, but until now nothing wrote it: a store that nominates no exempt
 * groups — the default — could hold a covering certificate for a customer and
 * still tax them, with nothing in the log, because resolution reaches that
 * conclusion before any API call.
 *
 * Ownership is NOT checked here. Callers differ in what they can prove: the
 * attach controller has an identifier from a browser and must re-resolve it,
 * while creation already knows the certificate was filed under this customer's
 * own identity a moment ago. Putting the guard here would either duplicate that
 * work or invite a caller to skip it; keeping it out makes each caller state
 * which it is.
 */
class CertificateAttachment
{
    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var CertificateResolver
     */
    private $resolver;

    /**
     * @var GatewayLogger
     */
    private $logger;

    /**
     * @param CustomerRepositoryInterface $customerRepository
     * @param CertificateResolver $resolver
     * @param GatewayLogger $logger
     */
    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        CertificateResolver $resolver,
        GatewayLogger $logger
    ) {
        $this->customerRepository = $customerRepository;
        $this->resolver = $resolver;
        $this->logger = $logger;
    }

    /**
     * Attach a certificate to a customer, or clear the attachment with ''.
     *
     * @param CustomerInterface $customer
     * @param string $certificateId
     * @param string $administrator Who is responsible, for the log
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool Whether anything changed
     */
    public function set(CustomerInterface $customer, $certificateId, $administrator = '', $store = null)
    {
        $certificateId = trim((string) $certificateId);
        $previous = $this->resolver->attachedCertificateId($customer);

        if ($previous === $certificateId) {
            return false;
        }

        $customer->setCustomAttribute(CertificateResolver::ATTACHED_ATTRIBUTE, $certificateId);
        $this->customerRepository->save($customer);

        // Logged against the customer's own store, not the ambient one: which
        // TaxCloud account this matters to depends on where the customer sits.
        $this->logger->setStore($store);

        // A financial control, like the identity it sits beside: this decides
        // which certificate a customer's orders are filed against. Worth being
        // able to answer, years later, who did it and when.
        $this->logger->info(
            'TaxCloud certificate attached to customer ' . (string) $customer->getId()
            . ' changed from ' . ($previous === '' ? '(none)' : $previous)
            . ' to ' . ($certificateId === '' ? '(none)' : $certificateId)
            . ($administrator === '' ? '' : ' by ' . $administrator)
        );

        return true;
    }

    /**
     * Attach only when nothing is attached yet.
     *
     * Used when an administrator creates a certificate for a customer: they
     * have already expressed the intent, and requiring a second click on a
     * control they have not yet noticed is precisely the gap this closes.
     *
     * Confined to the empty case on purpose — displacing an existing
     * attachment would silently re-file a customer against a different
     * certificate, which is the opposite of what adding a second one usually
     * means.
     *
     * @param CustomerInterface $customer
     * @param string $certificateId
     * @param string $administrator
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool Whether it was attached
     */
    public function setIfUnattached(CustomerInterface $customer, $certificateId, $administrator = '', $store = null)
    {
        if ($this->resolver->attachedCertificateId($customer) !== '') {
            return false;
        }

        return $this->set($customer, $certificateId, $administrator, $store);
    }
}
