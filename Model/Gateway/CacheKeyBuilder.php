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

namespace Taxcloud\Magento2\Model\Gateway;

/**
 * Builds the cache keys used across the TaxCloud gateway.
 *
 * Centralizing key construction keeps the discipline in one place: response
 * caches key on a hash of the exact request payload sent to TaxCloud, and the
 * exemption-certificate cache keys per (customer, certificate) so one customer
 * cannot reuse another's cached exempt-state list.
 */
class CacheKeyBuilder
{
    /**
     * Prefix for cached Lookup (tax rate) responses.
     */
    public const PREFIX_RATES = 'taxcloud_rates_';

    /**
     * Prefix for cached VerifyAddress responses.
     */
    public const PREFIX_ADDRESS = 'taxcloud_address_';

    /**
     * Prefix for cached exemption-certificate state lists.
     */
    public const PREFIX_EXEMPT_CERT_STATES = 'taxcloud_cert_states_';

    /**
     * Cache key for a Lookup response, derived from the exact request payload.
     *
     * @param array $params The Lookup request params, post-observer
     * @return string
     */
    public function forLookup(array $params)
    {
        return self::PREFIX_RATES . hash('sha256', json_encode($params));
    }

    /**
     * Cache key for a VerifyAddress response, derived from the request payload.
     *
     * @param array $params The VerifyAddress request params
     * @return string
     */
    public function forAddress(array $params)
    {
        return self::PREFIX_ADDRESS . hash('sha256', json_encode($params));
    }

    /**
     * Cache key for an exemption certificate's exempt-state list.
     *
     * Keyed per (customer, certificate) so a customer who pastes another
     * customer's certificate UUID into their own profile cannot reuse the
     * other customer's cached state list — and per TaxCloud account (API ID),
     * so under multi-store setups with different TaxCloud accounts a state
     * list fetched under one account is never served to a lookup running
     * under another. The API ID is hashed so the key stays cache-id-safe and
     * the credential does not appear in cache storage keys.
     *
     * @param string $customerId
     * @param string $certificateId
     * @param string $apiId TaxCloud API login ID the states were fetched under
     * @return string
     */
    public function forExemptCertStates($customerId, $certificateId, $apiId = '')
    {
        return self::PREFIX_EXEMPT_CERT_STATES . $customerId . '_' . $certificateId
            . '_' . hash('sha256', (string) $apiId);
    }
}
