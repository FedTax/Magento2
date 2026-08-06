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

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\Api;

/**
 * Maps v3 REST responses back onto the shapes the rest of the module
 * consumes.
 *
 * The output contracts are the transport-unaware ones the SOAP path
 * established: the lookup result array (product tax keyed by item code,
 * shipping tax accumulated), the verified-address array
 * (Address1/…/Zip5/Zip4), and the OrderDetailsResult-keyed array
 * {@see \Taxcloud\Magento2\Model\Order\CancellationProcessor} reads — so no
 * caller changes when a store switches transport.
 */
class RestResponseMapper
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LoggerInterface|null $logger
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Apply a v3 cart response's per-line tax onto the lookup result array.
     *
     * Mirrors the SOAP CartItemResponseHandler: shipping lines accumulate into
     * the shipping bucket, product lines land keyed by the quote item code the
     * line's index maps to. Lines with an unknown index are skipped and
     * logged — silently dropping one would zero a product's tax.
     *
     * @param array $responseLineItems v3 CartResponse lineItems
     * @param array $indexedItems index => quote item code map from the request builder
     * @param array $result Mutated in place (Api::ITEM_TYPE_* buckets)
     * @return void
     */
    public function applyCartTax(array $responseLineItems, array $indexedItems, array &$result)
    {
        foreach ($responseLineItems as $line) {
            if (!is_array($line) || !isset($line['index']) || !isset($line['tax']['amount'])) {
                $this->logger->warning('v3 cart response line without index/tax skipped');
                continue;
            }
            $index = (int) $line['index'];
            $taxAmount = (float) $line['tax']['amount'];

            if (($line['itemId'] ?? '') === 'shipping') {
                $result[Api::ITEM_TYPE_SHIPPING] += $taxAmount;
            } elseif (array_key_exists($index, $indexedItems)) {
                $result[Api::ITEM_TYPE_PRODUCT][$indexedItems[$index]] = $taxAmount;
            } else {
                $this->logger->warning('v3 cart response line with unknown index ' . $index . ' skipped');
            }
        }
    }

    /**
     * Extract the first cart from a POST /carts response body.
     *
     * @param array|null $body CreateCartsResponse
     * @return array|null CartResponse, or null when the shape is unusable
     */
    public function extractCart(?array $body)
    {
        $cart = $body['items'][0] ?? null;
        return is_array($cart) ? $cart : null;
    }

    /**
     * Map a v3 verified address to the v1-shaped contract array callers of
     * verifyAddress() consume. A combined ZIP+4 splits into Zip5/Zip4.
     *
     * @param array|null $body v3 address (line1/line2/city/state/zip)
     * @return array|false v1 shape (Address1/Address2/City/State/Zip5/Zip4), false when unusable
     */
    public function mapVerifiedAddress(?array $body)
    {
        if (!is_array($body) || empty($body['city']) || empty($body['state']) || empty($body['zip'])) {
            return false;
        }

        $zip5 = (string) $body['zip'];
        $zip4 = '';
        if (strpos($zip5, '-') !== false) {
            [$zip5, $zip4] = explode('-', $zip5, 2);
        }

        return [
            'Address1' => (string) ($body['line1'] ?? ''),
            'Address2' => (string) ($body['line2'] ?? ''),
            'City' => (string) $body['city'],
            'State' => (string) $body['state'],
            'Zip5' => $zip5,
            'Zip4' => $zip4,
        ];
    }

    /**
     * Map a v3 order resource (with refunds expanded) onto the
     * OrderDetailsResult-keyed array the SOAP OrderDetails call produced.
     *
     * The v1 result reported lifecycle dates; the v3 order resource's
     * completedDate covers the authorized/captured pair (v3 has no separate
     * authorize step — capture is whole-order), and the latest refund's dates
     * fill ReturnedDate.
     *
     * @param array|null $body v3 OrderResponse
     * @return array|null OrderDetailsResult-shaped array, or null when unusable
     */
    public function mapOrderDetails(?array $body)
    {
        if (!is_array($body) || empty($body['orderId'])) {
            return null;
        }

        $completedDate = isset($body['completedDate']) ? (string) $body['completedDate'] : '';
        $transactionDate = isset($body['transactionDate']) ? (string) $body['transactionDate'] : '';

        $returnedDate = '';
        if (!empty($body['refunds']) && is_array($body['refunds'])) {
            foreach ($body['refunds'] as $refund) {
                if (!is_array($refund)) {
                    continue;
                }
                $date = (string) ($refund['returnedDate'] ?? $refund['createdDate'] ?? '');
                if ($date !== '' && ($returnedDate === '' || $date > $returnedDate)) {
                    $returnedDate = $date;
                }
            }
        }

        return [
            'ResponseType' => 'OK',
            'OrderID' => (string) $body['orderId'],
            'LookupDate' => $transactionDate,
            'AuthorizedDate' => $completedDate,
            'CapturedDate' => $completedDate,
            'ReturnedDate' => $returnedDate,
        ];
    }
}
