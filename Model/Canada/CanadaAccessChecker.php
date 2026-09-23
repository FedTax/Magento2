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

namespace Taxcloud\Magento2\Model\Canada;

use Taxcloud\Magento2\Model\Config\Source\ApiType;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\PingResult;
use Taxcloud\Magento2\Model\Gateway\RequestBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestRequestBuilder;
use Taxcloud\Magento2\Model\Gateway\Rest\RestResponse;

/**
 * Finds out whether a store's TaxCloud account can price Canadian sales.
 *
 * Canada is an account add-on that TaxCloud support switches on, and no API
 * exposes account features, so the only observable signal is a real lookup:
 * one general-goods line shipped from the store's origin to a fixed Toronto
 * address. Every Canadian province taxes general goods, so an account with
 * Canada answers with a non-zero rate; a refusal or a zero rate means it does
 * not have it.
 *
 * The connection is pinged first, so a refusal of the sample lookup can be
 * read as "Canada is not enabled" rather than confused with bad credentials
 * or an unknown connection — which would send the merchant to support for
 * the wrong reason.
 *
 * The sample cart always uses the same cart id, so repeated checks update one
 * cart in the merchant's TaxCloud account, and nothing is ever filed. Nothing
 * is retried: this backs an admin button and a config save, which should
 * answer fast.
 *
 * Reads only saved configuration, resolved against the given store.
 */
class CanadaAccessChecker
{
    /**
     * Cart and customer id of the sample lookup, recognisable in TaxCloud.
     */
    public const SAMPLE_CART_ID = 'taxcloud-canada-access-check';

    /**
     * Toronto City Hall: an Ontario address, 13% HST on general goods.
     */
    public const SAMPLE_DESTINATION = [
        'Address1' => '100 Queen St W',
        'Address2' => '',
        'City' => 'Toronto',
        'State' => 'ON',
        'Zip5' => '',
        'Zip4' => '',
        'Country' => RequestBuilder::COUNTRY_CANADA,
        'PostalCode' => 'M5H 2N2',
    ];

    /**
     * General tangible personal property.
     */
    private const SAMPLE_TIC = 0;

    private const SAMPLE_PRICE = 100.0;

    /**
     * @var TaxcloudConfig
     */
    private $config;

    /**
     * @var RestClient
     */
    private $restClient;

    /**
     * @var RequestBuilder
     */
    private $requestBuilder;

    /**
     * @var RestRequestBuilder
     */
    private $restRequestBuilder;

    /**
     * @param TaxcloudConfig $config
     * @param RestClient $restClient
     * @param RequestBuilder $requestBuilder
     * @param RestRequestBuilder $restRequestBuilder
     */
    public function __construct(
        TaxcloudConfig $config,
        RestClient $restClient,
        RequestBuilder $requestBuilder,
        RestRequestBuilder $restRequestBuilder
    ) {
        $this->config = $config;
        $this->restClient = $restClient;
        $this->requestBuilder = $requestBuilder;
        $this->restRequestBuilder = $restRequestBuilder;
    }

    /**
     * Run the check for a store's saved configuration.
     *
     * @param int|string|\Magento\Store\Api\Data\StoreInterface|null $store Store whose settings apply
     * @return CanadaAccessResult
     */
    public function check($store = null): CanadaAccessResult
    {
        if ($this->config->getApiType($store) !== ApiType::REST) {
            return $this->unavailable(__(
                'Canadian tax requires the V3 REST API. Set API Type to V3 REST and save before checking.'
            ));
        }

        if ((string) $this->config->getRestConnectionId($store) === '') {
            return $this->unavailable(__('Enter and save a Connection ID before checking Canada access.'));
        }

        $origin = $this->requestBuilder->buildOrigin($store);
        if ($origin === null) {
            return $this->unavailable(__(
                'The shipping origin is not a valid US address. Set it under Stores → Configuration → Sales →'
                . ' Delivery Methods → Origin and save before checking Canada access.'
            ));
        }

        $start = microtime(true);

        try {
            $ping = $this->restClient->pingForScope($store);
        } catch (\Throwable $e) {
            return $this->unavailable(
                __('Could not check Canada access: %1', $e->getMessage()),
                null,
                $this->elapsedMs($start)
            );
        }
        if ($ping->getOutcome() !== PingResult::OK) {
            return $this->unavailable(
                $this->pingFailureMessage($ping),
                null,
                $this->elapsedMs($start)
            );
        }

        try {
            $response = $this->restClient->request('POST', '/carts', $this->samplePayload($origin), $store);
        } catch (\Throwable $e) {
            return $this->unavailable(
                __('Could not reach TaxCloud to check Canada access: %1', $e->getMessage()),
                null,
                $this->elapsedMs($start)
            );
        }

        return $this->classify($response, $this->elapsedMs($start));
    }

    /**
     * @param RestResponse $response
     * @param int $durationMs
     * @return CanadaAccessResult
     */
    private function classify(RestResponse $response, int $durationMs): CanadaAccessResult
    {
        $status = $response->getStatus();

        if ($response->isSuccess()) {
            $rate = $this->sampleRate($response->getBody());
            if ($rate !== null && $rate > 0) {
                return new CanadaAccessResult(
                    CanadaAccessResult::ENABLED,
                    __(
                        'Canada access confirmed — TaxCloud calculated %1 tax on a sample sale to Toronto, Ontario.',
                        $this->formatRate($rate)
                    ),
                    $rate,
                    $status,
                    $durationMs
                );
            }

            return new CanadaAccessResult(
                CanadaAccessResult::NOT_ENABLED,
                __(
                    'TaxCloud calculated no tax on a sample sale to Toronto, Ontario, so Canadian tax does not'
                    . ' appear to be enabled on your TaxCloud account. Contact TaxCloud support to enable it.'
                ),
                $rate,
                $status,
                $durationMs
            );
        }

        // The connection answered a ping moments ago, so an auth failure or a
        // missing connection here is transient or a misconfiguration — not an
        // account without Canada. Same for throttling and server errors.
        if ($response->isUnauthorized() || $response->isNotFound() || $response->isRetryable()) {
            return $this->unavailable(
                __('Could not check Canada access: TaxCloud answered %1. Try again shortly.', $response->errorDetail()),
                $status,
                $durationMs
            );
        }

        return new CanadaAccessResult(
            CanadaAccessResult::NOT_ENABLED,
            __(
                'TaxCloud refused a sample sale to Canada (%1). Canadian tax does not appear to be enabled on your'
                . ' TaxCloud account. Contact TaxCloud support to enable it.',
                $response->errorDetail()
            ),
            null,
            $status,
            $durationMs
        );
    }

    /**
     * @param PingResult $ping
     * @return \Magento\Framework\Phrase
     */
    private function pingFailureMessage(PingResult $ping)
    {
        switch ($ping->getOutcome()) {
            case PingResult::AUTH_FAILED:
                return __(
                    'Could not check Canada access: TaxCloud rejected this scope\'s credentials.'
                    . ' Fix them first (use Verify Credentials).'
                );
            case PingResult::UNKNOWN_CONNECTION:
                return __(
                    'Could not check Canada access: TaxCloud does not know this Connection ID.'
                    . ' Fix it first (use Verify Credentials).'
                );
            default:
                return __('Could not reach TaxCloud to check Canada access: %1', $ping->getReason());
        }
    }

    /**
     * The v3 cart payload for the sample lookup.
     *
     * @param array $origin v1-shaped store origin
     * @return array
     */
    private function samplePayload(array $origin): array
    {
        return ['items' => [[
            'cartId' => self::SAMPLE_CART_ID,
            'customerId' => self::SAMPLE_CART_ID,
            'currency' => ['currencyCode' => 'CAD'],
            'origin' => $this->restRequestBuilder->toV3Address($origin),
            'destination' => $this->restRequestBuilder->toV3Address(self::SAMPLE_DESTINATION),
            'deliveredBySeller' => false,
            'lineItems' => [[
                'index' => 0,
                'itemId' => self::SAMPLE_CART_ID,
                'tic' => self::SAMPLE_TIC,
                'price' => self::SAMPLE_PRICE,
                'quantity' => 1.0,
            ]],
        ]]];
    }

    /**
     * Tax rate of the sample line, or null when the response carries none.
     *
     * @param array|null $body
     * @return float|null
     */
    private function sampleRate(?array $body): ?float
    {
        $line = $body['items'][0]['lineItems'][0] ?? null;
        if (!is_array($line) || !isset($line['tax']) || !is_array($line['tax'])) {
            return null;
        }
        if (isset($line['tax']['rate']) && is_numeric($line['tax']['rate'])) {
            return (float) $line['tax']['rate'];
        }
        if (isset($line['tax']['amount']) && is_numeric($line['tax']['amount'])) {
            return (float) $line['tax']['amount'] / self::SAMPLE_PRICE;
        }

        return null;
    }

    /**
     * 0.14975 → "14.975%", 0.13 → "13%".
     *
     * @param float $rate
     * @return string
     */
    private function formatRate(float $rate): string
    {
        return rtrim(rtrim(sprintf('%.3f', $rate * 100), '0'), '.') . '%';
    }

    /**
     * @param \Magento\Framework\Phrase|string $message
     * @param int|null $httpStatus
     * @param int $durationMs
     * @return CanadaAccessResult
     */
    private function unavailable($message, ?int $httpStatus = null, int $durationMs = 0): CanadaAccessResult
    {
        return new CanadaAccessResult(CanadaAccessResult::UNAVAILABLE, $message, null, $httpStatus, $durationMs);
    }

    /**
     * @param float $start
     * @return int
     */
    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
