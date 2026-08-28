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
 * @copyright  2026 The Federal Tax Authority, LLC d/b/a TaxCloud
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Taxcloud\Magento2\Test\Integration\Rest;

use Taxcloud\Magento2\Model\Certificate\RestCertificateGateway;
use Taxcloud\Magento2\Model\Config\TaxcloudConfig;
use Taxcloud\Magento2\Model\Gateway\Rest\RestClient;
use Taxcloud\Magento2\Model\Gateway\Rest\RestGateway;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * Live v3 REST API contract checks — the behaviors the REST gateway relies on
 * but TaxCloud's documentation does not pin, verified once by hand
 * (2026-08-06, see openspec change add-rest-tax-operations, design.md "Open
 * Questions") and kept honest here against API drift:
 *
 *  - the ping contract, under both auth modes
 *  - the cart → order → refund cycle, including the duplicate-orderId
 *    ErrorModel wording the capture path treats as benign, and fractional
 *    refund quantities
 *  - verify-address ZIP+4 shape
 *  - the exemption-certificates listing envelope (items/nextCursor), and the
 *    item shape inside it — states as objects carrying a two-letter
 *    `abbreviation`, which the REST path originally read as bare strings and
 *    so never matched
 *
 * These tests talk to the real TaxCloud API with the credentials seeded by
 * scripts/seed-test-data.php: the real v3 key (TAXCLOUD_API_V3_KEY, required)
 * makes X-API-KEY the auth mode everything here runs under, matching the
 * project policy of testing with a real key; the Bearer-via-exchange fallback
 * from the V1 pair keeps exactly one basic connectivity check
 * ({@see testBearerExchangeFallbackStillAuthenticates()}). The v1 apiKey
 * doubles as the v3 connection ID. Order IDs are unique per run; sandbox
 * accounts never charge real tax.
 */
class RestLiveApiTest extends IntegrationTestCase
{
    private const ORIGIN = ['line1' => '1401 Lavaca St', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'];

    /**
     * In-state Texas destination: the sandbox account provably has TX nexus
     * (the e2e golden values depend on it), so tax here is non-zero and the
     * line-total invariant can be asserted unconditionally.
     */
    private const DESTINATION_TX = ['line1' => '1100 Congress Ave', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701'];

    /**
     * No-nexus destination for the order/refund cycle, where the structural
     * contract (statuses, echoes, wording) is the subject, not the amounts.
     */
    private const DESTINATION = ['line1' => '350 5th Ave', 'city' => 'New York', 'state' => 'NY', 'zip' => '10118'];

    /**
     * Regex the capture path uses to recognize a benign duplicate — keep in
     * sync with {@see RestGateway::isDuplicateOrder()}.
     */
    private const DUPLICATE_DETAIL = '/already exist|duplicate/i';

    private function restClient(): RestClient
    {
        $config = $this->get(TaxcloudConfig::class);
        $this->assertNotSame(
            '',
            (string) $config->getRestConnectionId(),
            'seed-test-data.php must have seeded the v3 connection ID'
        );
        // TAXCLOUD_API_V3_KEY is required: everything in this suite must run
        // under real-key X-API-KEY auth, not the Bearer fallback.
        $this->assertNotSame(
            '',
            (string) $config->getRestApiKey(),
            'seed-test-data.php must have seeded the real v3 API key (TAXCLOUD_API_V3_KEY)'
        );

        return $this->get(RestClient::class);
    }

    /**
     * @param string $suffix
     * @return array<string, mixed>
     */
    private function orderPayload(string $orderId): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return [
            'orderId' => $orderId,
            'customerId' => 'it-rest-customer',
            'transactionDate' => $now,
            'completedDate' => $now,
            'currency' => ['currencyCode' => 'USD'],
            'origin' => self::ORIGIN,
            'destination' => self::DESTINATION,
            'deliveredBySeller' => false,
            'lineItems' => [
                [
                    'index' => 0, 'itemId' => 'it-rest-sku', 'price' => 10.0, 'quantity' => 2, 'tic' => 0,
                    'tax' => ['amount' => 0.0, 'rate' => 0.0],
                ],
                [
                    'index' => 1, 'itemId' => 'shipping', 'price' => 5.0, 'quantity' => 1, 'tic' => 11010,
                    'tax' => ['amount' => 0.0, 'rate' => 0.0],
                ],
            ],
        ];
    }

    public function testPingSucceedsWithTheRealV3Key(): void
    {
        $client = $this->restClient();
        $result = $client->pingForScope();

        $this->assertTrue(
            $result->isSuccess(),
            'v3 ping must succeed with the seeded real key (X-API-KEY); got '
            . $result->getOutcome() . ($result->getReason() !== '' ? ' (' . $result->getReason() . ')' : '')
        );
    }

    /**
     * The one Bearer-path check: with the real key removed for the scope, the
     * module must still authenticate by exchanging the V1 pair for a Bearer
     * token against the live exchange service. If this goes red while the
     * X-API-KEY ping stays green, suspect the undocumented exchange endpoint.
     */
    public function testBearerExchangeFallbackStillAuthenticates(): void
    {
        $client = $this->restClient();

        // Delete the saved key for this test only (snapshot-restored), so
        // AuthProvider falls back to the V1-pair exchange.
        $this->setScopedConfig('tax/taxcloud_settings/rest_api_key', null);
        $this->assertNull(
            $this->get(TaxcloudConfig::class)->getRestApiKey(),
            'the key must be gone for this scope or the test proves nothing'
        );

        $result = $client->pingForScope();

        $this->assertTrue(
            $result->isSuccess(),
            'Bearer-via-exchange ping must succeed with only the V1 pair; got '
            . $result->getOutcome() . ($result->getReason() !== '' ? ' (' . $result->getReason() . ')' : '')
        );
    }

    public function testCartCalculationEchoesLinesWithLineTotalTax(): void
    {
        $client = $this->restClient();
        $cartId = 'it-rest-' . uniqid('', false);

        $response = $client->request('POST', '/carts', ['items' => [[
            'cartId' => $cartId,
            'customerId' => 'it-rest-customer',
            'currency' => ['currencyCode' => 'USD'],
            'origin' => self::ORIGIN,
            'destination' => self::DESTINATION_TX,
            'deliveredBySeller' => false,
            'lineItems' => [
                ['index' => 0, 'itemId' => 'it-rest-sku', 'price' => 10.0, 'quantity' => 2, 'tic' => 0],
            ],
        ]]]);

        $this->assertTrue($response->isSuccess(), 'cart creation failed: ' . $response->errorDetail());

        $cart = $response->getBody()['items'][0] ?? null;
        $this->assertIsArray($cart, 'CreateCartsResponse must carry the cart under items[0]');
        $this->assertSame($cartId, $cart['cartId']);

        $line = $cart['lineItems'][0] ?? null;
        $this->assertIsArray($line);
        $this->assertSame(0, $line['index']);
        $this->assertArrayHasKey('amount', $line['tax'], 'each response line must carry tax.amount');
        $this->assertArrayHasKey('rate', $line['tax'], 'each response line must carry tax.rate');

        // tax.amount is the LINE TOTAL, not per-unit (verified live 2026-08-06:
        // CA rate 0.08625 on $10 × 2 answered 1.73, not 0.86). The gateway
        // applies it as the line total, so drift here would misprice carts.
        $rate = (float) $line['tax']['rate'];
        $this->assertGreaterThan(0, $rate, 'TX nexus must yield a non-zero rate for an in-state cart');
        $this->assertEqualsWithDelta(
            $rate * 10.0 * 2,
            (float) $line['tax']['amount'],
            0.02,
            'tax.amount must be the line-total tax'
        );
    }

    public function testOrderCycleDuplicateWordingAndFractionalRefund(): void
    {
        $client = $this->restClient();
        $orderId = 'IT-REST-' . uniqid('', false);

        // Create a completed order directly (the capture path's request shape).
        $created = $client->request('POST', '/orders', $this->orderPayload($orderId));
        $this->assertTrue($created->isSuccess(), 'order creation failed: ' . $created->errorDetail());
        $this->assertSame($orderId, $created->getBody()['orderId'] ?? null);

        // Re-submitting the same orderId must fail with the ErrorModel wording
        // the capture path recognizes as a benign duplicate (observed live:
        // 400 "completed order already exists for this store").
        $duplicate = $client->request('POST', '/orders', $this->orderPayload($orderId));
        $this->assertFalse($duplicate->isSuccess(), 'duplicate orderId must not create a second order');
        $this->assertContains(
            $duplicate->getStatus(),
            [400, 409, 422],
            'duplicate must answer a client error the gateway inspects; got HTTP ' . $duplicate->getStatus()
        );
        $this->assertMatchesRegularExpression(
            self::DUPLICATE_DETAIL,
            $duplicate->errorDetail(),
            'duplicate detail must match RestGateway::isDuplicateOrder() or captures will double-report failures'
        );

        // Fractional quantities express partial-amount refunds (adjustment
        // distribution, partial shipping) — v3 must accept and echo them.
        $refund = $client->request(
            'POST',
            '/orders/refunds/' . rawurlencode($orderId),
            ['items' => [
                ['itemId' => 'it-rest-sku', 'quantity' => 0.5],
                ['itemId' => 'shipping', 'quantity' => 0.4],
            ]]
        );
        $this->assertTrue($refund->isSuccess(), 'fractional refund failed: ' . $refund->errorDetail());

        // The 201 echo usually carries the refund items, but occasionally
        // answers [] (observed live 2026-08-06 — an async race on TaxCloud's
        // side). The recorded refund is what matters, so fall back to reading
        // it off the order resource (the getOrderDetails path). Never re-POST
        // a refund: it is not idempotent.
        $refundItems = $refund->getBody()[0]['items'] ?? [];
        $orderBody = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $order = $client->request('GET', '/orders/' . rawurlencode($orderId) . '?expand=refunds');
            $this->assertTrue($order->isSuccess(), 'order fetch failed: ' . $order->errorDetail());
            $orderBody = $order->getBody();
            if ($refundItems === []) {
                $refundItems = $orderBody['refunds'][0]['items'] ?? [];
            }
            if ($refundItems !== [] && !empty($orderBody['refunds'])) {
                break;
            }
            sleep(2);
        }

        $quantities = array_column($refundItems, 'quantity', 'itemId');
        $this->assertEqualsWithDelta(
            0.5,
            (float) ($quantities['it-rest-sku'] ?? 0),
            0.0001,
            'refund not recorded with fractional quantities; order: ' . json_encode($orderBody)
        );
        $this->assertEqualsWithDelta(
            0.4,
            (float) ($quantities['shipping'] ?? 0),
            0.0001,
            'refund not recorded with fractional quantities; order: ' . json_encode($orderBody)
        );

        // The refund is visible on the order resource the way getOrderDetails
        // reads it (expand=refunds).
        $this->assertNotEmpty($orderBody['completedDate'] ?? '', 'completedDate must be set');
        $this->assertNotEmpty($orderBody['refunds'] ?? [], 'the refund must appear under expand=refunds');
    }

    public function testVerifyAddressReturnsContractShapeThroughTheGateway(): void
    {
        $this->restClient();

        // Through the real gateway (mapper, cache, events), asserting the
        // transport-unaware output contract callers rely on.
        $verified = $this->get(RestGateway::class)->verifyAddress([
            'Address1' => '162 East Ave',
            'Address2' => '',
            'City' => 'Norwalk',
            'State' => 'CT',
            'Zip5' => '06851',
            'Zip4' => '',
        ]);

        $this->assertIsArray($verified, 'verification of a known-good address must succeed');
        foreach (['Address1', 'Address2', 'City', 'State', 'Zip5', 'Zip4'] as $key) {
            $this->assertArrayHasKey($key, $verified);
        }
        $this->assertMatchesRegularExpression('/^\d{5}$/', $verified['Zip5']);
        // v3 answers a combined ZIP+4 ("06851-5775" observed live); the mapper
        // must split it into the four-digit Zip4.
        $this->assertMatchesRegularExpression('/^(\d{4})?$/', $verified['Zip4']);
    }

    public function testExemptionCertificateListingEnvelope(): void
    {
        $client = $this->restClient();

        // CertificateRepository reads exactly these envelope keys.
        $response = $client->request('GET', '/tax/exemption-certificates?limit=2', null, null, false);

        $this->assertTrue($response->isSuccess(), 'certificate listing failed: ' . $response->errorDetail());
        $body = $response->getBody();
        $this->assertIsArray($body['items'] ?? null, 'the listing envelope must carry items[]');
        $this->assertArrayHasKey('nextCursor', $body, 'the listing envelope must carry nextCursor');
    }

    /**
     * The item-level contract, which the envelope assertions above cannot
     * reach.
     *
     * This is the test that was missing when the REST validator shipped reading
     * `states` as a list of plain strings: v3 returns objects carrying an
     * `abbreviation`, so every certificate resolved to "covers no state" and no
     * exemption was ever applied. An envelope-only assertion could not catch
     * that, and against an account holding no certificates it could not catch
     * anything at all — which is why this asserts a non-empty listing before
     * anything else.
     *
     * seed-test-data.php provisions the certificate this reads (section 4j).
     */
    public function testExemptionCertificateItemShape(): void
    {
        $client = $this->restClient();

        $response = $client->request('GET', '/tax/exemption-certificates?limit=100', null, null, false);
        $this->assertTrue($response->isSuccess(), 'certificate listing failed: ' . $response->errorDetail());

        $items = $response->getBody()['items'] ?? [];
        $this->assertIsArray($items);
        $this->assertNotEmpty(
            $items,
            'no certificates on the account — run scripts/seed-test-data.php, which seeds one for '
            . 'exempt-customer@example.com. Without one this test proves nothing.'
        );

        $certificate = $items[0];
        $this->assertIsString($certificate['certificateId'] ?? null, 'certificateId must be a string');
        $this->assertIsString($certificate['customerId'] ?? null, 'customerId must be a string');
        $this->assertArrayHasKey('disabledAt', $certificate, 'disabledAt gates whether a cert may be applied');

        // The crux: states are OBJECTS carrying a two-letter abbreviation, not
        // bare strings. RestCertificateMapper depends on exactly this.
        $this->assertIsArray($certificate['states'] ?? null, 'states must be an array');
        $this->assertNotEmpty($certificate['states'], 'the seeded certificate must cover at least one state');
        foreach ($certificate['states'] as $state) {
            $this->assertIsArray($state, 'each state entry must be an object, not a bare abbreviation string');
            $this->assertArrayHasKey('abbreviation', $state, 'each state entry must carry an abbreviation');
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $state['abbreviation']);
        }
    }

    /**
     * End of the same thread: the validator resolves the seeded certificate
     * against the live API and reports the state it actually covers. Fails
     * against the pre-fix parse, which returned null for every certificate.
     */
    public function testSeededCertificateValidatesForItsCoveredState(): void
    {
        $client = $this->restClient();

        $response = $client->request('GET', '/tax/exemption-certificates?limit=100', null, null, false);
        $this->assertTrue($response->isSuccess(), 'certificate listing failed: ' . $response->errorDetail());

        $items = $response->getBody()['items'] ?? [];
        $certificate = null;
        foreach ($items as $item) {
            if (is_array($item) && empty($item['disabledAt']) && !empty($item['states'])) {
                $certificate = $item;
                break;
            }
        }
        $this->assertNotNull(
            $certificate,
            'no live certificate with covered states on the account — run scripts/seed-test-data.php'
        );

        $gateway = $this->get(RestCertificateGateway::class);
        $covered = $certificate['states'][0]['abbreviation'];

        $mapped = null;
        foreach ($gateway->listCertificates($certificate['customerId']) as $candidate) {
            if ($candidate->getCertificateId() === $certificate['certificateId']) {
                $mapped = $candidate;
                break;
            }
        }

        $this->assertNotNull($mapped, 'the certificate must come back from a listing by its own customer identity');
        $this->assertTrue(
            $mapped->covers($covered),
            'a certificate covering ' . $covered . ' must cover a ' . $covered . ' destination'
        );
        $this->assertFalse(
            $mapped->covers($covered === 'CA' ? 'NY' : 'CA'),
            'a certificate must not cover a state it does not list'
        );
    }
}
