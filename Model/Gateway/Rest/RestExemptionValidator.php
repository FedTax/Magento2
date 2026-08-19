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

namespace Taxcloud\Magento2\Model\Gateway\Rest;

use Magento\Framework\Cache\FrontendInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\CacheKeyBuilder;
use Throwable;

/**
 * Validates a customer's exemption certificate against the v3
 * GET /tax/exemption-certificates endpoint: the certificate ID is returned
 * only when the certificate exists for the customer, is not disabled, and
 * lists the destination state among its covered states.
 *
 * The exempt-state list is cached exactly like the SOAP validator's — keyed
 * per (customer, certificate) so a pasted foreign certificate UUID cannot
 * reuse another customer's cached list, and per account (connection ID here,
 * API login ID on SOAP) so stores on different TaxCloud accounts never share
 * entries. Fails closed: any fetch problem validates to null rather than
 * applying an unverified exemption.
 */
class RestExemptionValidator
{
    /**
     * Path of the account-level certificate listing, relative to the base URL.
     */
    private const CERTIFICATES_PATH = '/tax/exemption-certificates';

    /**
     * Seconds to cache a certificate's exempt-state list (matches the SOAP
     * validator).
     */
    public const STATE_CACHE_TTL = 3600;

    /**
     * Page size for the certificate listing; pages are followed by cursor
     * until the certificate is found.
     */
    private const PAGE_LIMIT = 100;

    /**
     * @var RestClient
     */
    private $restClient;

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
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param RestClient        $restClient
     * @param TaxcloudConfig    $config
     * @param CacheKeyBuilder   $cacheKeyBuilder
     * @param FrontendInterface $cacheType
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        RestClient $restClient,
        TaxcloudConfig $config,
        CacheKeyBuilder $cacheKeyBuilder,
        FrontendInterface $cacheType,
        ?LoggerInterface $logger = null
    ) {
        $this->restClient = $restClient;
        $this->config = $config;
        $this->cacheKeyBuilder = $cacheKeyBuilder;
        $this->cacheType = $cacheType;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Return the certificate ID only when it covers the destination state,
     * otherwise null.
     *
     * @param string $certificateID
     * @param string $customerID
     * @param string $destinationState Two-letter state abbreviation
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store Store whose TaxCloud account applies
     * @return string|null
     */
    public function validate($certificateID, $customerID, $destinationState, $store = null)
    {
        if (empty($certificateID) || empty($customerID) || empty($destinationState)) {
            return null;
        }

        // Same key discipline as the SOAP validator; the account component is
        // the connection ID, so a store migrated between accounts (or running
        // SOAP and REST side by side) never reuses the wrong list.
        $cacheKey = $this->cacheKeyBuilder->forExemptCertStates(
            $customerID,
            $certificateID,
            (string) $this->config->getRestConnectionId($store)
        );
        $cached = $this->cacheType->load($cacheKey);
        if ($cached) {
            $exemptStates = json_decode($cached, true);
            if (is_array($exemptStates)) {
                return $this->matchState($certificateID, $exemptStates, $destinationState);
            }
        }

        $exemptStates = $this->fetchExemptStates($certificateID, $customerID, $store);
        if ($exemptStates === null) {
            // Fetch failed — fail closed without caching, so a transient
            // problem doesn't pin an empty list for an hour.
            return null;
        }

        $this->cacheType->save(json_encode($exemptStates), $cacheKey, [], self::STATE_CACHE_TTL);

        return $this->matchState($certificateID, $exemptStates, $destinationState);
    }

    /**
     * Fetch the certificate's exempt states from the v3 API, following cursor
     * pagination until the certificate is found.
     *
     * @param string $certificateID
     * @param string $customerID
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string[]|null Two-letter state abbreviations, taken from each
     *                       state entry's `abbreviation`; [] when the
     *                       certificate is missing, disabled, or covers no
     *                       state; null when the fetch failed
     */
    private function fetchExemptStates($certificateID, $customerID, $store)
    {
        $this->logger->debug('v3 exemption-certificates lookup for customer ' . $customerID);

        $cursor = null;
        do {
            $query = 'customerId=' . rawurlencode((string) $customerID) . '&limit=' . self::PAGE_LIMIT;
            if ($cursor !== null) {
                $query .= '&cursor=' . rawurlencode($cursor);
            }

            try {
                $response = $this->restClient->request(
                    'GET',
                    self::CERTIFICATES_PATH . '?' . $query,
                    null,
                    $store,
                    false
                );
            } catch (Throwable $e) {
                // Fail closed — don't apply an unverified exemption.
                $this->logger->error('v3 exemption-certificates fetch error: ' . $e->getMessage());
                return null;
            }

            if (!$response->isSuccess()) {
                $this->logger->error('v3 exemption-certificates fetch failed: ' . $response->errorDetail());
                return null;
            }

            $body = $response->getBody();
            $items = is_array($body['items'] ?? null) ? $body['items'] : [];
            foreach ($items as $certificate) {
                if (!is_array($certificate) || ($certificate['certificateId'] ?? '') !== $certificateID) {
                    continue;
                }
                if (!empty($certificate['disabledAt'])) {
                    $this->logger->warning('Exemption cert ' . $certificateID . ' is disabled');
                    return [];
                }
                $states = $certificate['states'] ?? [];
                return is_array($states) ? $this->toStateAbbreviations($states) : [];
            }

            $cursor = isset($body['nextCursor']) && is_string($body['nextCursor']) && $body['nextCursor'] !== ''
                ? $body['nextCursor']
                : null;
        } while ($cursor !== null && $items !== []);

        $this->logger->warning('Certificate ' . $certificateID . ' not found for customer ' . $customerID);
        return [];
    }

    /**
     * Reduce a v3 certificate's state entries to their two-letter
     * abbreviations.
     *
     * v3 states are objects — {"abbreviation": "NY"} per the
     * ExemptionCertificateExemptStatesResponse schema — not the bare strings
     * the SOAP mapper produces from StateAbbr. Entries carrying no usable
     * abbreviation are dropped individually rather than voiding the whole
     * certificate, so one malformed entry cannot silently un-exempt a customer
     * whose remaining states are fine.
     *
     * @param array $states Raw state entries from the v3 response
     * @return string[] State abbreviations (e.g. ['NY', 'NJ'])
     */
    private function toStateAbbreviations(array $states)
    {
        $abbreviations = [];
        foreach ($states as $state) {
            if (!is_array($state)) {
                continue;
            }
            $abbr = $state['abbreviation'] ?? null;
            if (is_string($abbr) && strlen($abbr) === 2) {
                $abbreviations[] = $abbr;
            }
        }

        return $abbreviations;
    }

    /**
     * @param string $certificateID
     * @param string[] $exemptStates
     * @param string $destinationState
     * @return string|null
     */
    private function matchState($certificateID, array $exemptStates, $destinationState)
    {
        $match = in_array($destinationState, $exemptStates, true);
        $this->logger->info(
            'Exemption cert ' . $certificateID . ' covers [' . implode(', ', $exemptStates) . ']'
            . ' — destination ' . $destinationState . ($match ? ' MATCHES' : ' does NOT match')
        );
        return $match ? $certificateID : null;
    }
}
