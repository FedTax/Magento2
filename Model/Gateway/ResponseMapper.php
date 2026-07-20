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

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Taxcloud\Magento2\Model\CartItemResponseHandler;

/**
 * Converts TaxCloud wire responses into plain PHP arrays and pulls the
 * domain-relevant pieces out of them.
 *
 * SOAP returns nested \stdClass graphs (and collapses single-element lists to a
 * bare object); normalizing to arrays up front lets the rest of the gateway
 * treat every response uniformly. It also maps a Lookup's per-cart-item tax
 * responses back onto the tax result.
 */
class ResponseMapper
{
    /**
     * @var CartItemResponseHandler
     */
    private $cartItemResponseHandler;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CartItemResponseHandler $cartItemResponseHandler
     * @param LoggerInterface|null    $logger
     */
    public function __construct(
        CartItemResponseHandler $cartItemResponseHandler,
        ?LoggerInterface $logger = null
    ) {
        $this->cartItemResponseHandler = $cartItemResponseHandler;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Apply a Lookup's per-cart-item tax responses onto the tax result array
     * (product tax keyed by item code, shipping tax accumulated).
     *
     * @param mixed $cartItemResponse
     * @param array $cartItems
     * @param array $indexedItems
     * @param array $result Mutated in place
     * @return void
     */
    public function applyCartItemResponses($cartItemResponse, $cartItems, $indexedItems, array &$result)
    {
        $this->cartItemResponseHandler->processAndApplyCartItemResponses(
            $cartItemResponse,
            $cartItems,
            $indexedItems,
            $result
        );
    }

    /**
     * Normalize a raw SOAP response (\stdClass graph) into a nested array.
     *
     * Walks the graph directly instead of round-tripping through
     * json_encode()/json_decode(). Besides being materially faster (~48% on a
     * single-item Lookup, ~62% on a 25-item cart), this drops two quirks of the
     * round-trip:
     *
     * - A single byte of invalid UTF-8 anywhere in the response (a latin-1
     *   character in a customer name or address) made json_encode() return
     *   false, collapsing the *entire* response to null — which the gateway then
     *   read as a failed call. Such values now pass through untouched.
     * - json_encode() drops a zero fraction, so a whole-number TaxAmount (2.0,
     *   or 0.0 on a non-taxable item) came back as int rather than float.
     *   Amounts now keep their wire type; downstream only does arithmetic and
     *   (float) casts on them, so totals are unaffected.
     *
     * SoapClient returns trees rather than cyclic graphs, so no cycle guard.
     *
     * @param mixed $response
     * @return mixed Nested array for object/array responses; scalars unchanged
     */
    public function toArray($response)
    {
        if (is_object($response)) {
            // Called from outside the object's scope, so this yields public
            // properties only — the same set json_encode() would have seen.
            $response = get_object_vars($response);
        }

        if (!is_array($response)) {
            return $response;
        }

        foreach ($response as $key => $value) {
            if (is_object($value) || is_array($value)) {
                $response[$key] = $this->toArray($value);
            }
        }

        return $response;
    }

    /**
     * Extract the list of exempt state abbreviations for a specific certificate
     * from a raw GetExemptCertificates SOAP response.
     *
     * @param object $response      Raw SOAP response
     * @param string $certificateId
     * @return string[] State abbreviations (e.g. ['NY', 'NJ'])
     */
    public function extractExemptStates($response, $certificateId)
    {
        $result = $response->GetExemptCertificatesResult ?? null;
        if (!$result || ($result->ResponseType ?? '') !== 'OK') {
            $this->logger->info('GetExemptCertificates returned non-OK response');
            return [];
        }

        $certs = $result->ExemptCertificates->ExemptionCertificate ?? [];
        // SOAP may return a single object instead of an array when there is only one cert
        if (!is_array($certs)) {
            $certs = [$certs];
        }

        foreach ($certs as $cert) {
            if (($cert->CertificateID ?? '') !== $certificateId) {
                continue;
            }

            $states = [];
            $exemptStates = $cert->Detail->ExemptStates->ExemptState ?? [];
            if (!is_array($exemptStates)) {
                $exemptStates = [$exemptStates];
            }
            foreach ($exemptStates as $es) {
                // The SOAP response uses StateAbbr or StateAbbreviation
                $abbr = $es->StateAbbr ?? $es->StateAbbreviation ?? null;
                if ($abbr) {
                    $states[] = $abbr;
                }
            }
            return $states;
        }

        $this->logger->info('Certificate ' . $certificateId . ' not found in GetExemptCertificates response');
        return [];
    }
}
