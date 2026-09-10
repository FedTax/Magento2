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

/**
 * Resolves the identity a Magento customer's certificates are filed under in
 * TaxCloud.
 *
 * TaxCloud files every certificate under a customer identifier, and neither
 * API can find a certificate any other way. That identifier is chosen by
 * whoever created the certificate — and for the certificates merchants
 * actually have, that is a person typing into the TaxCloud portal, who has no
 * reason to type a Magento entity id. So "which customer is this in TaxCloud"
 * cannot be assumed; it has to be resolved.
 *
 * The default is the Magento entity id, which is exactly what the module
 * queried before this class existed. Every configuration that worked before
 * therefore keeps working, with no data migrated.
 *
 * ONE resolver, used by reads and writes alike. That is the point of the
 * class, not an implementation detail: the WooCommerce plugin sends the bare
 * user id when creating a certificate and `customer-<id>` when filing an
 * order, and spent two tickets reconciling identities its own code had split.
 * A single seam makes that class of bug unreachable rather than merely fixed.
 *
 * The stored value is administrator-only — see {@see CustomerIdentityGuard}.
 * Setting it hands a customer the exemptions filed under that identifier, so
 * it is a financial control, not a preference.
 */
class TaxCloudCustomerIdentity
{
    /**
     * Customer attribute holding an explicitly configured identity.
     */
    public const ATTRIBUTE = 'taxcloud_customer_id';

    /**
     * The identity a customer's certificates are filed under.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer
     * @return string Empty when there is no customer — guests hold no
     *                certificates, and an empty identity must never be used to
     *                query, since it would match whatever TaxCloud files under
     *                the empty string
     */
    public function resolve($customer)
    {
        if ($customer === null) {
            return '';
        }

        $configured = $this->configuredValue($customer);
        if ($configured !== '') {
            return $configured;
        }

        $entityId = $customer->getId();

        return $entityId === null ? '' : (string) $entityId;
    }

    /**
     * Whether this customer has an identity that differs from their entity id.
     *
     * Useful to an admin screen wanting to show that a customer's certificates
     * are being looked up somewhere other than the obvious place.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface|null $customer
     * @return bool
     */
    public function isOverridden($customer)
    {
        if ($customer === null) {
            return false;
        }

        return $this->configuredValue($customer) !== '';
    }

    /**
     * @param \Magento\Customer\Api\Data\CustomerInterface $customer
     * @return string
     */
    private function configuredValue($customer)
    {
        $attribute = $customer->getCustomAttribute(self::ATTRIBUTE);
        if ($attribute === null) {
            return '';
        }

        $value = $attribute->getValue();

        return is_string($value) ? trim($value) : '';
    }
}
