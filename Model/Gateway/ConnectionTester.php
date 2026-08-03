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

use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Taxcloud\Magento2\Model\Config\Source\ApiType;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestCredentials;
use Taxcloud\Magento2\Model\Gateway\Soap\SoapPing;

/**
 * Admin "Test Connection" workflow, transport-agnostic.
 *
 * Takes the credential values as currently entered in the config form
 * (possibly unsaved), fills blanks and obscured placeholders from the saved
 * configuration of the scope being edited, dispatches to the transport
 * matching the selected API type, and folds the outcome into one
 * success-flag-plus-message pair. Credential values never appear in messages.
 */
class ConnectionTester
{
    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var RestClient
     */
    private $restClient;

    /**
     * @var SoapPing
     */
    private $soapPing;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param TaxcloudConfig $config
     * @param RestClient $restClient
     * @param SoapPing $soapPing
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        TaxcloudConfig $config,
        RestClient $restClient,
        SoapPing $soapPing,
        StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->restClient = $restClient;
        $this->soapPing = $soapPing;
        $this->storeManager = $storeManager;
    }

    /**
     * Run the connection test.
     *
     * @param array $input Form values: api_type, api_id, api_key,
     *                     rest_api_key, rest_connection_id (all optional)
     * @param string|null $websiteParam website id from the config-edit URL
     * @param string|null $storeParam store id from the config-edit URL
     * @return array{success: bool, message: \Magento\Framework\Phrase|string}
     */
    public function test(array $input, $websiteParam = null, $storeParam = null): array
    {
        $store = $this->resolveScopeStore($websiteParam, $storeParam);

        $apiType = $input['api_type'] ?? '';
        if (!in_array($apiType, [ApiType::SOAP, ApiType::REST], true)) {
            $apiType = $this->config->getApiType($store);
        }

        return $apiType === ApiType::REST
            ? $this->testRest($input, $store)
            : $this->testSoap($input, $store);
    }

    /**
     * @param array $input
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return array{success: bool, message: \Magento\Framework\Phrase|string}
     */
    private function testRest(array $input, $store): array
    {
        $apiKey = $this->formValue($input, 'rest_api_key');
        // The Encrypted backend renders saved keys as an all-asterisk
        // placeholder; that means "unchanged", so fall back to the saved key.
        if ($apiKey === '' || preg_match('/^\*+$/', $apiKey)) {
            $apiKey = (string) $this->config->getRestApiKey($store);
        }
        $connectionId = $this->formValue($input, 'rest_connection_id');
        if ($connectionId === '') {
            $connectionId = (string) $this->config->getRestConnectionId($store);
        }

        if ($apiKey === '') {
            return $this->failure(__('Enter an API Key first (generate one under Developer → API in TaxCloud).'));
        }
        if ($connectionId === '') {
            return $this->failure(
                __('Enter a Connection ID first (find it under Integrations → Custom API in TaxCloud).')
            );
        }

        $result = $this->restClient->ping(new RestCredentials($apiKey, $connectionId), $store);

        switch ($result->getOutcome()) {
            case PingResult::OK:
                return $this->success(__('Connection successful — your V3 REST credentials are working.'));
            case PingResult::AUTH_FAILED:
                return $this->failure(
                    __('TaxCloud rejected the API Key (HTTP 401). Check the key generated under Developer → API.')
                );
            case PingResult::UNKNOWN_CONNECTION:
                return $this->failure(__(
                    'TaxCloud does not know this Connection ID (HTTP 404).'
                    . ' Check the ID under Integrations → Custom API and that it belongs to this account.'
                ));
            default:
                return $this->failure(__('Could not reach TaxCloud: %1', $result->getReason()));
        }
    }

    /**
     * @param array $input
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store
     * @return array{success: bool, message: \Magento\Framework\Phrase|string}
     */
    private function testSoap(array $input, $store): array
    {
        $apiId = $this->formValue($input, 'api_id');
        if ($apiId === '') {
            $apiId = (string) $this->config->getApiId($store);
        }
        $apiKey = $this->formValue($input, 'api_key');
        if ($apiKey === '') {
            $apiKey = (string) $this->config->getApiKey($store);
        }

        if ($apiId === '' || $apiKey === '') {
            return $this->failure(__('Enter both the API ID and API Key first.'));
        }

        $result = $this->soapPing->ping($apiId, $apiKey, $store);

        switch ($result->getOutcome()) {
            case PingResult::OK:
                return $this->success(__('Connection successful — your V1 SOAP credentials are working.'));
            case PingResult::AUTH_FAILED:
                return $this->failure(__('TaxCloud rejected this API ID / API Key pair. Check both values.'));
            default:
                return $this->failure(__('Could not reach TaxCloud: %1', $result->getReason()));
        }
    }

    /**
     * Effective store for the scope being edited in the config form: the store
     * itself, a website's default store (so website-scope values apply), or
     * null for the default scope. Never the ambient store.
     *
     * @param string|null $websiteParam
     * @param string|null $storeParam
     * @return string|\Magento\Store\Api\Data\StoreInterface|null
     */
    private function resolveScopeStore($websiteParam, $storeParam)
    {
        if ($storeParam !== null && $storeParam !== '') {
            return $storeParam;
        }
        if ($websiteParam !== null && $websiteParam !== '') {
            try {
                $website = $this->storeManager->getWebsite($websiteParam);
            } catch (\Throwable $e) {
                return null;
            }
            if ($website instanceof Website) {
                $defaultStore = $website->getDefaultStore();
                if ($defaultStore && $defaultStore->getId()) {
                    return $defaultStore;
                }
            }
        }
        return null;
    }

    /**
     * @param array $input
     * @param string $key
     * @return string
     */
    private function formValue(array $input, string $key): string
    {
        $value = $input[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param \Magento\Framework\Phrase|string $message
     * @return array{success: bool, message: \Magento\Framework\Phrase|string}
     */
    private function success($message): array
    {
        return ['success' => true, 'message' => $message];
    }

    /**
     * @param \Magento\Framework\Phrase|string $message
     * @return array{success: bool, message: \Magento\Framework\Phrase|string}
     */
    private function failure($message): array
    {
        return ['success' => false, 'message' => $message];
    }
}
