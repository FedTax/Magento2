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

namespace Taxcloud\Magento2\Plugin\Customer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\CustomerFactory;
use Taxcloud\Magento2\Model\Certificate\AttachmentWriteScope;
use Taxcloud\Magento2\Model\Certificate\CertificateResolver;
use Taxcloud\Magento2\Model\Certificate\CustomerIdentityGuard;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;

/**
 * Keeps a customer's attached certificate out of reach of every write path
 * except the ones that check who is asking.
 *
 * The attachment decides which certificate exempts a customer's orders. It is
 * changed through CertificateAttachment, whose callers establish first that the
 * request may change it — an administrator with the certificate permission, or
 * a customer the store has nominated for self-service — and that the
 * certificate is the customer's. But the value is an ordinary customer
 * attribute, so the customer REST and GraphQL APIs accept it in
 * custom_attributes, and the admin customer form carries it for administrators
 * without the permission. Any of those would change it without either check.
 *
 * So, like the identity guard beside it, this sits on the repository: a change
 * is let through when CertificateAttachment is making it, or when the request
 * is not customer-facing and (in the admin) holds the permission. Anything else
 * is reverted to the stored value, silently, so the rest of the customer save
 * still happens.
 */
class GuardCertificateAttachment
{
    /**
     * @var CustomerFactory
     */
    private $customerFactory;

    /**
     * @var CustomerIdentityGuard
     */
    private $guard;

    /**
     * @var AttachmentWriteScope
     */
    private $writeScope;

    /**
     * @var GatewayLogger
     */
    private $logger;

    /**
     * @param CustomerFactory $customerFactory
     * @param CustomerIdentityGuard $guard
     * @param AttachmentWriteScope $writeScope
     * @param GatewayLogger $logger
     */
    public function __construct(
        CustomerFactory $customerFactory,
        CustomerIdentityGuard $guard,
        AttachmentWriteScope $writeScope,
        GatewayLogger $logger
    ) {
        $this->customerFactory = $customerFactory;
        $this->guard = $guard;
        $this->writeScope = $writeScope;
        $this->logger = $logger;
    }

    /**
     * @param CustomerRepositoryInterface $subject
     * @param CustomerInterface $customer
     * @param string|null $passwordHash
     * @return array{0: CustomerInterface, 1: string|null}
     */
    public function beforeSave(
        CustomerRepositoryInterface $subject,
        CustomerInterface $customer,
        $passwordHash = null
    ) {
        if ($this->writeScope->isOpen()) {
            return [$customer, $passwordHash];
        }

        $submitted = $this->submittedValue($customer);
        if ($submitted === null) {
            // The save does not mention the attribute, so the stored value is
            // kept by Magento as it is. Nothing to compare, and no reason to
            // pay for a load.
            return [$customer, $passwordHash];
        }

        $stored = $this->storedValue($customer);
        if ($submitted === $stored || $this->guard->isWriteAllowed()) {
            return [$customer, $passwordHash];
        }

        $customer->setCustomAttribute(CertificateResolver::ATTACHED_ATTRIBUTE, $stored);

        $this->logger->warning(
            'Refused a change to the TaxCloud certificate attached to customer ' . (string) $customer->getId()
            . ' — it did not come through certificate management'
        );

        return [$customer, $passwordHash];
    }

    /**
     * The value this save carries, or null when it carries none.
     *
     * @param CustomerInterface $customer
     * @return string|null
     */
    private function submittedValue(CustomerInterface $customer)
    {
        $attribute = $customer->getCustomAttribute(CertificateResolver::ATTACHED_ATTRIBUTE);
        if ($attribute === null) {
            return null;
        }

        $value = $attribute->getValue();

        return is_string($value) ? trim($value) : '';
    }

    /**
     * The value currently PERSISTED, or '' for a customer being created.
     *
     * Read from the database, not through the repository this plugin
     * decorates — see GuardTaxCloudIdentity::storedValue() for why.
     *
     * @param CustomerInterface $customer
     * @return string
     */
    private function storedValue(CustomerInterface $customer)
    {
        $customerId = $customer->getId();
        if ($customerId === null) {
            return '';
        }

        $persisted = $this->customerFactory->create()->load((int) $customerId);
        if (!$persisted->getId()) {
            return '';
        }

        $stored = $persisted->getData(CertificateResolver::ATTACHED_ATTRIBUTE);

        return is_string($stored) ? trim($stored) : '';
    }
}
