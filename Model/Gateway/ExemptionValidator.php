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

use Magento\Framework\Cache\FrontendInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapClientProviderInterface;
use Taxcloud\Magento2\Model\Logging\LogRedactor;
use Throwable;

/**
 * Validates a customer's exemption certificate against a destination state.
 *
 * Calls GetExemptCertificates, caches the certificate's exempt-state list
 * (scoped per customer+certificate so one customer cannot reuse another's), and
 * returns the certificate ID only when the destination state is actually
 * covered. Fails closed — any error resolves to "no exemption" rather than
 * applying an unverified one.
 */
class ExemptionValidator
{
    /**
     * Exempt-state list cache lifetime, in seconds (1 hour).
     */
    public const STATE_CACHE_TTL = 3600;

    /**
     * @var SoapClientProviderInterface
     */
    private $soapClientProvider;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var FrontendInterface
     */
    private $cacheType;

    /**
     * @var CacheKeyBuilder
     */
    private $cacheKeyBuilder;

    /**
     * @var ResponseMapper
     */
    private $responseMapper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param SoapClientProviderInterface $soapClientProvider
     * @param TaxcloudConfig              $config
     * @param FrontendInterface           $cacheType Bound to the TaxCloud cache type in di.xml
     * @param CacheKeyBuilder             $cacheKeyBuilder
     * @param ResponseMapper              $responseMapper
     * @param LoggerInterface|null        $logger
     */
    public function __construct(
        SoapClientProviderInterface $soapClientProvider,
        TaxcloudConfig $config,
        FrontendInterface $cacheType,
        CacheKeyBuilder $cacheKeyBuilder,
        ResponseMapper $responseMapper,
        ?LoggerInterface $logger = null
    ) {
        $this->soapClientProvider = $soapClientProvider;
        $this->config = $config;
        $this->cacheType = $cacheType;
        $this->cacheKeyBuilder = $cacheKeyBuilder;
        $this->responseMapper = $responseMapper;
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

        // Keyed per (customer, certificate) so a customer who pastes another
        // customer's certificate UUID into their own profile cannot reuse the
        // other customer's cached state list — and per TaxCloud account, so
        // stores configured with different accounts never share entries.
        $cacheKey = $this->cacheKeyBuilder->forExemptCertStates(
            $customerID,
            $certificateID,
            (string) $this->config->getApiId($store)
        );
        $cached = $this->cacheType->load($cacheKey);
        if ($cached) {
            $exemptStates = json_decode($cached, true);
            if (is_array($exemptStates)) {
                $match = in_array($destinationState, $exemptStates, true);
                $this->logger->info(
                    'Exemption cert ' . $certificateID . ' covers [' . implode(', ', $exemptStates) . ']'
                    . ' — destination ' . $destinationState . ($match ? ' MATCHES' : ' does NOT match')
                );
                return $match ? $certificateID : null;
            }
        }

        // Fetch certificate details from TaxCloud
        $client = $this->soapClientProvider->getClient($store);
        if (!$client) {
            $this->logger->error('Cannot validate exemption cert: no SOAP client');
            return null;
        }

        $this->logger->debug('GetExemptCertificates for customer ' . $customerID);

        try {
            $response = $client->GetExemptCertificates([
                'apiLoginID' => $this->config->getApiId($store),
                'apiKey'     => $this->config->getApiKey($store),
                'customerID' => $customerID,
            ]);
        } catch (Throwable $e) {
            // Fail closed — don't apply an unverified exemption
            $this->logger->error('GetExemptCertificates SOAP error: ' . $e->getMessage());
            $this->logSoapTrace($client, $store);
            return null;
        }

        $this->logSoapTrace($client, $store);

        $exemptStates = $this->responseMapper->extractExemptStates($response, $certificateID);

        // Cache for 1 hour so we don't hammer the SOAP endpoint on every page load
        $this->cacheType->save(
            json_encode($exemptStates),
            $cacheKey,
            [],
            self::STATE_CACHE_TTL
        );

        $match = in_array($destinationState, $exemptStates, true);
        $this->logger->info(
            'Exemption cert ' . $certificateID . ' covers [' . implode(', ', $exemptStates) . ']'
            . ' — destination ' . $destinationState . ($match ? ' MATCHES' : ' does NOT match')
        );
        return $match ? $certificateID : null;
    }

    /**
     * Log the raw SOAP wire traffic of the client's most recent call at debug
     * level (Advanced mode only), credentials redacted. The client only
     * buffers traffic when built with trace=true, which SoapGateway enables
     * under the same Advanced setting.
     *
     * @param \SoapClient|object $client
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return void
     */
    private function logSoapTrace($client, $store = null)
    {
        if (!$this->config->isAdvancedLoggingEnabled($store)) {
            return;
        }
        if (!$client instanceof \SoapClient) {
            return;
        }

        $request = $client->__getLastRequest();
        if ($request) {
            $this->logger->debug('GetExemptCertificates SOAP request XML: ' . LogRedactor::redactXml($request));
        }
        $response = $client->__getLastResponse();
        if ($response) {
            $this->logger->debug('GetExemptCertificates SOAP response XML: ' . LogRedactor::redactXml($response));
        }
    }
}
