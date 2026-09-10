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

use Taxcloud\Magento2\Model\Gateway\PingResult;
use Throwable;

/**
 * Credential verification against the V1 SOAP Ping operation.
 *
 * A deliberate one-off next to the main gateway flow: the admin Test
 * Connection button verifies the credentials it is handed (possibly unsaved
 * form input), so this bypasses the gateway's cache and retry policy and
 * takes the credential pair as arguments instead of reading config.
 */
class SoapPing
{
    /**
     * @var SoapClientProviderInterface
     */
    private $clientProvider;

    /**
     * @param SoapClientProviderInterface $clientProvider
     */
    public function __construct(SoapClientProviderInterface $clientProvider)
    {
        $this->clientProvider = $clientProvider;
    }

    /**
     * Verify a credential pair against the V1 SOAP Ping operation.
     *
     * The store argument scopes the transport (WSDL endpoint, timeout), not
     * the credentials: per project convention it must be the store/scope
     * being processed, never the ambient store.
     *
     * @param string $apiLoginID
     * @param string $apiKey
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return PingResult
     */
    public function ping(string $apiLoginID, string $apiKey, $store = null): PingResult
    {
        $client = $this->clientProvider->getClient($store);
        if ($client === null) {
            return new PingResult(PingResult::TRANSPORT_ERROR, 'SOAP client unavailable (WSDL unreachable?)');
        }

        try {
            $response = $client->Ping([
                'apiLoginID' => $apiLoginID,
                'apiKey' => $apiKey,
            ]);
        } catch (Throwable $e) {
            return new PingResult(
                PingResult::TRANSPORT_ERROR,
                str_replace(array_filter([$apiLoginID, $apiKey]), '***', $e->getMessage())
            );
        }

        $result = $response->PingResult ?? null;
        if (($result->ResponseType ?? '') === 'OK') {
            return new PingResult(PingResult::OK);
        }

        // Any non-OK PingRsp means TaxCloud rejected the credential pair.
        return new PingResult(PingResult::AUTH_FAILED);
    }
}
