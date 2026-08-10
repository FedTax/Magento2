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

namespace Taxcloud\Magento2\Model\Gateway\Soap;

use Magento\Framework\Webapi\Soap\ClientFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\UserAgent;
use Throwable;

/**
 * SOAP transport for the TaxCloud gateway.
 *
 * Owns everything SOAP-specific about reaching TaxCloud: the WSDL, the
 * connection/read timeout options, and the lazily-constructed, per-instance
 * cached SoapClient. The rest of the gateway depends on
 * {@see SoapClientProviderInterface} rather than on any of these details.
 */
class SoapGateway implements SoapClientProviderInterface
{
    /**
     * @var ClientFactory
     */
    private $soapClientFactory;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Lazily-built SoapClients, cached per store key for the lifetime of this
     * instance. Different stores can point at different WSDL endpoints or
     * timeouts, so a single shared client would leak one store's transport
     * configuration into another's calls.
     *
     * @var array<string, \SoapClient|null>
     */
    private $clients = [];

    /**
     * @var UserAgent
     */
    private $userAgent;

    /**
     * $userAgent precedes the optional $logger deliberately: an optional
     * constructor argument is never auto-wired by the object manager, so a
     * trailing optional dependency would silently keep its default in
     * production while tests that pass it explicitly still pass.
     *
     * @param ClientFactory        $soapClientFactory
     * @param TaxcloudConfig       $config
     * @param UserAgent            $userAgent
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        ClientFactory $soapClientFactory,
        TaxcloudConfig $config,
        UserAgent $userAgent,
        ?LoggerInterface $logger = null
    ) {
        $this->soapClientFactory = $soapClientFactory;
        $this->config = $config;
        $this->userAgent = $userAgent;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Build the option array passed to the SoapClient constructor.
     *
     * - connection_timeout: caps how long we wait to establish the connection.
     * - stream_context http/ssl timeout: caps the read so a slow response
     *   doesn't hang the checkout thread for default_socket_timeout (~60s).
     * - cache_wsdl => WSDL_CACHE_BOTH: cache the WSDL in memory and on disk so
     *   we don't refetch api.taxcloud.net's WSDL on every client construction.
     * - trace (Advanced logging only): buffer the raw request/response XML so
     *   call sites can log the actual wire traffic via __getLastRequest()/
     *   __getLastResponse(). Off otherwise — the buffers cost memory per call.
     * - user_agent, set twice: SoapClient sends it on the SOAP calls, and the
     *   WSDL fetch is a separate HTTP request that must be identified too.
     *   Measured on PHP 8.1–8.4, either placement alone already covers both
     *   (PHP propagates the option into the WSDL fetch context, and honors a
     *   supplied context for the SOAP call). Both are set anyway: they are
     *   independent code paths inside ext-soap, the cost is nil, and neither
     *   request then depends on that propagation staying true. They come from
     *   one source, so they cannot disagree.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return array
     */
    public function buildSoapOptions($store = null)
    {
        $timeout = $this->config->getSoapTimeout($store);
        $userAgent = $this->userAgent->get();

        $options = [
            'connection_timeout' => $timeout,
            'cache_wsdl'         => WSDL_CACHE_BOTH,
            'keep_alive'         => true,
            'user_agent'         => $userAgent,
            'stream_context'     => stream_context_create([
                'http' => ['timeout' => $timeout, 'user_agent' => $userAgent],
                'ssl'  => ['timeout' => $timeout],
            ]),
        ];

        if ($this->config->isAdvancedLoggingEnabled($store)) {
            $options['trace'] = true;
        }

        return $options;
    }

    /**
     * @inheritDoc
     */
    public function getClient($store = null)
    {
        $storeKey = $this->storeKey($store);
        // Only successful constructions are cached: a failed WSDL fetch is
        // retried on the next call, matching the previous single-client behavior.
        if (!isset($this->clients[$storeKey])) {
            try {
                $this->clients[$storeKey] = $this->soapClientFactory->create(
                    $this->config->getWsdlUrl($store),
                    $this->buildSoapOptions($store)
                );
                $this->logger->debug(
                    'SoapClient created: endpoint=' . $this->config->getWsdlUrl($store)
                    . ', timeout=' . $this->config->getSoapTimeout($store) . 's'
                    . ', trace=' . ($this->config->isAdvancedLoggingEnabled($store) ? 'on' : 'off')
                    . ', store=' . ($storeKey === '' ? '(ambient)' : $storeKey)
                );
            } catch (Throwable $e) {
                $this->logger->error('Cannot get SoapClient: ' . $e->getMessage());
                return null;
            }
        }
        return $this->clients[$storeKey];
    }

    /**
     * Normalize a store argument to a cache-array key.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return string
     */
    private function storeKey($store)
    {
        if ($store === null) {
            return '';
        }
        if ($store instanceof \Magento\Store\Api\Data\StoreInterface) {
            return (string) $store->getId();
        }
        return (string) $store;
    }
}
