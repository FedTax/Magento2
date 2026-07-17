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
     * TaxCloud SOAP WSDL endpoint.
     */
    public const WSDL = 'https://api.taxcloud.net/1.0/TaxCloud.asmx?wsdl';

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
     * Lazily-built SoapClient, cached for the lifetime of this instance.
     *
     * @var \SoapClient|null
     */
    private $client = null;

    /**
     * @param ClientFactory        $soapClientFactory
     * @param TaxcloudConfig       $config
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        ClientFactory $soapClientFactory,
        TaxcloudConfig $config,
        ?LoggerInterface $logger = null
    ) {
        $this->soapClientFactory = $soapClientFactory;
        $this->config = $config;
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
     *
     * @return array
     */
    public function buildSoapOptions()
    {
        $timeout = $this->config->getSoapTimeout();

        return [
            'connection_timeout' => $timeout,
            'cache_wsdl'         => WSDL_CACHE_BOTH,
            'keep_alive'         => true,
            'stream_context'     => stream_context_create([
                'http' => ['timeout' => $timeout],
                'ssl'  => ['timeout' => $timeout],
            ]),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getClient()
    {
        if ($this->client === null) {
            try {
                $this->client = $this->soapClientFactory->create(self::WSDL, $this->buildSoapOptions());
            } catch (Throwable $e) {
                $this->logger->info('Cannot get SoapClient:');
                $this->logger->info($e->getMessage());
            }
        }
        return $this->client;
    }
}
