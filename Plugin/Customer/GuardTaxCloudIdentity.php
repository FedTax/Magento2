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
use Taxcloud\Magento2\Model\Certificate\CustomerIdentityGuard;
use Taxcloud\Magento2\Model\Certificate\TaxCloudCustomerIdentity;
use Taxcloud\Magento2\Model\Logging\GatewayLogger;

/**
 * Keeps a customer's TaxCloud identity out of reach of anything but a
 * permitted administrator, and records it when one changes it.
 *
 * Sits on the repository rather than on a controller because every write path
 * ends here — the account form, the admin form, REST, GraphQL, an import.
 * Guarding controllers instead would mean the next write path added is one
 * omission away from letting a customer claim someone else's exemptions.
 *
 * A refused write is reverted silently to the stored value rather than raising:
 * the attribute rides along with the rest of a customer save, and rejecting the
 * whole save would turn an ignorable field into a broken account update. The
 * caller wanted to save a customer, and the customer is saved — just not that
 * field.
 */
class GuardTaxCloudIdentity
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
     * @var GatewayLogger
     */
    private $logger;

    /**
     * @param CustomerFactory $customerFactory
     * @param CustomerIdentityGuard $guard
     * @param GatewayLogger $logger
     */
    public function __construct(
        CustomerFactory $customerFactory,
        CustomerIdentityGuard $guard,
        GatewayLogger $logger
    ) {
        $this->customerFactory = $customerFactory;
        $this->guard = $guard;
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
        $submitted = $this->submittedValue($customer);
        $stored = $this->storedValue($customer);

        if ($submitted === $stored) {
            return [$customer, $passwordHash];
        }

        if (!$this->guard->isWriteAllowed()) {
            $this->restore($customer, $stored);

            $this->logger->warning(
                'Refused a TaxCloud identity change for customer ' . (string) $customer->getId()
                . ' — the request is not a permitted administrator'
            );

            return [$customer, $passwordHash];
        }

        // A financial control: this grants the customer whatever exemptions are
        // filed under the new identity. Worth being able to answer, years
        // later, who did it and when.
        $this->logger->info(
            'TaxCloud identity for customer ' . (string) $customer->getId()
            . ' changed from ' . ($stored === '' ? '(default)' : $stored)
            . ' to ' . ($submitted === '' ? '(default)' : $submitted)
        );

        return [$customer, $passwordHash];
    }

    /**
     * @param CustomerInterface $customer
     * @return string
     */
    private function submittedValue(CustomerInterface $customer)
    {
        $attribute = $customer->getCustomAttribute(TaxCloudCustomerIdentity::ATTRIBUTE);
        if ($attribute === null) {
            return '';
        }

        $value = $attribute->getValue();

        return is_string($value) ? trim($value) : '';
    }

    /**
     * The value currently PERSISTED, or '' for a customer being created.
     *
     * Read straight from the database rather than through the customer
     * repository, for two reasons. The repository is the very object this
     * plugin decorates, so asking it during a save re-enters the plugin chain;
     * and it answers from an in-memory registry that may already hold the
     * incoming, unsaved changes — which would make the comparison below always
     * report "unchanged" and quietly disable the guard entirely.
     *
     * A freshly created model loaded by id reads from the database and shares
     * nothing with either.
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

        $stored = $persisted->getData(TaxCloudCustomerIdentity::ATTRIBUTE);

        return is_string($stored) ? trim($stored) : '';
    }

    /**
     * @param CustomerInterface $customer
     * @param string $stored
     * @return void
     */
    private function restore(CustomerInterface $customer, $stored)
    {
        $customer->setCustomAttribute(TaxCloudCustomerIdentity::ATTRIBUTE, $stored);
    }
}
