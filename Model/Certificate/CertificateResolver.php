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

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Decides which of a customer's certificates exempts an order — and refuses
 * every certificate that is not theirs.
 *
 * THIS IS THE OWNERSHIP BOUNDARY. It reads like belt-and-braces validation and
 * is not: TaxCloud applies any certificate on the account to any cart that
 * names it, with no check of whose it is. Verified against the live API — a
 * cart for `someone-else-99` carrying customer 2's certificate came back
 * exempt. So a certificate identifier that reaches TaxCloud has already been
 * trusted; the only place that can refuse it is here.
 *
 * Which is why the check lives in the resolver rather than at each entry point.
 * Spread across controllers, the next entry point added is one omission away
 * from letting a customer claim another's exemption. Here, an entry point that
 * forgets simply cannot apply a certificate at all — it fails inert instead of
 * exploitable.
 *
 * Precedence, among certificates that are eligible at all:
 *
 *   1. one explicitly attached to this order or customer
 *   2. one auto-applied because the store treats this customer as exempt
 *   3. none
 *
 * Eligibility is narrower than "the customer holds it": the certificate must
 * cover the destination state, must not be disabled, and must not be
 * single-purchase — the module cannot tell which order a single-purchase
 * certificate was meant for, so it never picks one.
 */
class CertificateResolver
{
    /**
     * Customer attribute naming a specific certificate to apply.
     *
     * Replaces the legacy `taxcloud_cert`, whose values are carried across by
     * {@see \Taxcloud\Magento2\Setup\Patch\Data\MigrateLegacyCertificateAttribute}.
     * Deliberately a new code rather than a reuse: a rename in place would give
     * the migration nothing to read from, and no way to tell a migrated value
     * from an un-migrated one if it were interrupted.
     */
    public const ATTACHED_ATTRIBUTE = 'taxcloud_certificate_id';

    /**
     * @var CertificateRepository
     */
    private $repository;

    /**
     * @var TaxCloudCustomerIdentity
     */
    private $identity;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CertificateRepository $repository
     * @param TaxCloudCustomerIdentity $identity
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        CertificateRepository $repository,
        TaxCloudCustomerIdentity $identity,
        ?LoggerInterface $logger = null
    ) {
        $this->repository = $repository;
        $this->identity = $identity;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * The certificate that exempts this order, or null to tax it.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer Null for a guest
     * @param string $destinationState Two-letter destination state
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return Certificate|null
     */
    public function resolve($customer, $destinationState, $store = null)
    {
        // Guests hold no certificates. Bail before the API call rather than
        // querying under an empty identity, which would match whatever
        // TaxCloud happens to file under the empty string.
        $customerIdentity = $this->identity->resolve($customer);
        if ($customerIdentity === '' || $destinationState === '') {
            return null;
        }

        // An exemption belongs to a customer and is put in force by attaching a
        // certificate to them, so with nothing attached there is no arrangement
        // that could exempt this order — and no reason to spend an API call
        // discovering that.
        $explicit = $this->attachedCertificateId($customer);

        if ($explicit === '') {
            return null;
        }

        try {
            $certificates = $this->repository->forCustomer($customerIdentity, $store);
        } catch (\Throwable $e) {
            // Fail closed. "Could not ask" is not "no certificate restrictions":
            // reading it that way would exempt an order on a transient outage.
            $this->logger->error(
                'Could not resolve certificates for identity ' . $customerIdentity
                . '; taxing the order: ' . $e->getMessage()
            );
            return null;
        }

        $eligible = [];
        foreach ($certificates as $certificate) {
            if ($certificate->covers($destinationState)) {
                $eligible[] = $certificate;
            }
        }

        $attached = $this->claimed($eligible, $explicit, $customerIdentity);

        if ($attached !== null) {
            // Exemption is a financial decision, and the log recorded only
            // refusals — an exempted order left no trace of why.
            $this->logger->info(
                'Certificate ' . $attached->getCertificateId() . ' applied to a ' . $destinationState
                . ' order for identity ' . $customerIdentity
            );
        }

        return $attached;
    }

    /**
     * The certificate attached to a customer, if any.
     *
     * Read here rather than in each lookup path so the precedence rule exists
     * once, and treated
     * exactly like any other externally supplied identifier: verified against
     * the customer's own certificates before it can be applied. A pasted id
     * that was never theirs has never been honoured, and still is not.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer
     * @return string
     */
    public function attachedCertificateId($customer)
    {
        if ($customer === null) {
            return '';
        }

        $attribute = $customer->getCustomAttribute(self::ATTACHED_ATTRIBUTE);
        if ($attribute === null) {
            return '';
        }

        $value = $attribute->getValue();

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Whether a certificate belongs to this customer — the question every
     * write path has to answer before deleting or displaying one.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer
     * @param string $certificateId
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return bool
     */
    public function belongsToCustomer($customer, $certificateId, $store = null)
    {
        $customerIdentity = $this->identity->resolve($customer);
        if ($customerIdentity === '' || $certificateId === '') {
            return false;
        }

        try {
            $certificates = $this->repository->forCustomer($customerIdentity, $store);
        } catch (\Throwable $e) {
            // Fail closed here too: an unverifiable claim of ownership is not
            // ownership.
            return false;
        }

        foreach ($certificates as $certificate) {
            if ($certificate->getCertificateId() === $certificateId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Honour an externally supplied identifier only if it is genuinely one of
     * this customer's eligible certificates.
     *
     * A miss is not an error worth surfacing to the shopper — it happens
     * legitimately when a certificate was deleted in TaxCloud, or when the
     * customer's identity changed. It is logged because the other reason it
     * happens is someone trying an identifier that is not theirs.
     *
     * @param Certificate[] $eligible
     * @param string $attachedCertificateId
     * @param string $customerIdentity
     * @return Certificate|null
     */
    private function claimed(array $eligible, $attachedCertificateId, $customerIdentity)
    {
        foreach ($eligible as $certificate) {
            if ($certificate->getCertificateId() === $attachedCertificateId) {
                return $certificate;
            }
        }

        $this->logger->info(
            'Certificate ' . $attachedCertificateId . ' is not an eligible certificate of identity '
            . $customerIdentity . '; taxing the order'
        );

        return null;
    }
}
