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

namespace Taxcloud\Magento2\Test\Integration\Certificate;

use Taxcloud\Magento2\Model\Certificate\Certificate;
use Taxcloud\Magento2\Model\Certificate\RestCertificateGateway;
use Taxcloud\Magento2\Model\Certificate\SoapCertificateGateway;
use Taxcloud\Magento2\Test\Integration\IntegrationTestCase;

/**
 * The certificate lifecycle — create, list, delete — against the LIVE TaxCloud
 * sandbox, once per transport.
 *
 * Unit tests cannot reach what this covers. They confirm the code agrees with
 * fixtures the same author wrote, which is exactly the failure that let the v3
 * state-shape defect ship: the fixtures were wrong in the same direction as the
 * code, and everything passed. Only a live call can say whether the payloads
 * this module sends are ones TaxCloud actually accepts.
 *
 * Each test creates its own certificate under a run-unique customer identity
 * and deletes it in a finally block. The identity must be unique per run
 * because the sandbox account is shared: a fixed one would let a leaked
 * certificate from an earlier failure be mistaken for this run's.
 *
 * The seeded exempt customer (identity "2") is never touched. Its certificate
 * is a fixture other suites depend on, and TaxCloud offers no way to restore
 * one that gets deleted by accident.
 */
class LiveCertificateLifecycleTest extends IntegrationTestCase
{
    /**
     * Certificate detail both transports accept. Values chosen from the enums
     * each API documents, so a rejection means a payload-shape problem rather
     * than an invalid choice.
     *
     * @return array<string, mixed>
     */
    private function certificateData(): array
    {
        return [
            'states' => ['TX'],
            'firstName' => 'Lifecycle',
            'lastName' => 'Probe',
            'title' => 'Owner',
            'address1' => '1100 Congress Ave',
            'city' => 'Austin',
            'state' => 'TX',
            'zip' => '78701',
            'businessType' => 'WholesaleTrade',
            'reason' => 'Resale',
            'reasonDescription' => 'Resale',
            'taxId' => '12-3456789',
            'taxType' => 'FEIN',
        ];
    }

    /**
     * A customer identity unique to this run, so a leaked certificate from an
     * earlier failed run can never be read as this one's.
     */
    private function identity(string $suffix): string
    {
        return 'it-cert-' . $suffix . '-' . uniqid('', false);
    }

    /**
     * @param Certificate[] $certificates
     */
    private function findById(array $certificates, string $certificateId): ?Certificate
    {
        foreach ($certificates as $certificate) {
            if ($certificate->getCertificateId() === $certificateId) {
                return $certificate;
            }
        }

        return null;
    }

    /**
     * Assert the round trip, whichever transport supplied the certificate.
     *
     * @param SoapCertificateGateway|RestCertificateGateway $gateway
     */
    private function assertLifecycle($gateway, string $identity): void
    {
        $certificateId = $gateway->createCertificate($identity, $this->certificateData());

        try {
            $this->assertNotSame('', $certificateId, 'creation must return a certificate id');

            $listed = $gateway->listCertificates($identity);
            $certificate = $this->findById($listed, $certificateId);

            $this->assertNotNull(
                $certificate,
                'a certificate just created under this identity must come back when listing it'
            );
            $this->assertSame(
                ['TX'],
                $certificate->getStates(),
                'the covered state must survive the round trip — this is the assertion the original defect broke'
            );
            $this->assertTrue($certificate->covers('TX'));
            $this->assertFalse($certificate->covers('NY'));
            $this->assertFalse($certificate->isDisabled());
            $this->assertFalse(
                $certificate->isSinglePurchase(),
                'the module never creates single-purchase certificates on either transport'
            );
        } finally {
            // The sandbox account is shared; a leaked certificate outlives the
            // run that made it and there is no bulk cleanup.
            $gateway->deleteCertificate($certificateId, $identity);
        }

        $this->assertNull(
            $this->findById($gateway->listCertificates($identity), $certificateId),
            'a deleted certificate must be gone from the listing'
        );
    }

    public function testCertificateLifecycleOverSoap(): void
    {
        $this->assertLifecycle($this->get(SoapCertificateGateway::class), $this->identity('soap'));
    }

    public function testCertificateLifecycleOverRest(): void
    {
        $this->assertLifecycle($this->get(RestCertificateGateway::class), $this->identity('rest'));
    }

    /**
     * The cross-transport property the seeding design depends on: v3 reads a
     * certificate created over v1.
     *
     * Not symmetric, and deliberately not asserted in reverse — v1 cannot read
     * a v3-created certificate, and worse, one v3 certificate makes v1's whole
     * listing for that customer fail. That is why the seeded fixture is created
     * over SOAP. If this ever goes red, the seeding strategy needs revisiting.
     */
    public function testRestReadsACertificateCreatedOverSoap(): void
    {
        $soap = $this->get(SoapCertificateGateway::class);
        $rest = $this->get(RestCertificateGateway::class);
        $identity = $this->identity('cross');

        $certificateId = $soap->createCertificate($identity, $this->certificateData());

        try {
            $viaRest = $this->findById($rest->listCertificates($identity), $certificateId);

            $this->assertNotNull($viaRest, 'v3 must be able to read a certificate created over v1');
            $this->assertSame(['TX'], $viaRest->getStates());
        } finally {
            $soap->deleteCertificate($certificateId, $identity);
        }
    }

    /**
     * A retrieval failure must be distinguishable from "this customer holds
     * none" — they are opposite answers to whether an order should be taxed,
     * and the whole fail-closed posture rests on the gateway not conflating
     * them. An identity with no certificates must return an empty list, not
     * throw.
     */
    public function testUnknownIdentityListsEmptyRatherThanFailing(): void
    {
        $identity = $this->identity('absent');

        $this->assertSame([], $this->get(RestCertificateGateway::class)->listCertificates($identity));
        $this->assertSame([], $this->get(SoapCertificateGateway::class)->listCertificates($identity));
    }
}
