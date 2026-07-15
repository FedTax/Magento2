<?php
/**
 * Canned TaxCloud `GetExemptCertificates` response: OK with no certificates.
 *
 * Only called when the checkout customer carries a `taxcloud_cert` custom
 * attribute (the seeded guest/customer does not), so in the standard
 * observer-wiring flow this is a safety net rather than a hot path. Provided so
 * that any test which does attach a certificate still gets a deterministic,
 * network-free answer.
 */

declare(strict_types=1);

return [
    'GetExemptCertificatesResult' => [
        'ResponseType'       => 'OK',
        'Messages'           => '',
        'ExemptCertificates' => '',
    ],
];
