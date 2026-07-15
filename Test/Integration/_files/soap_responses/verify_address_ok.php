<?php
/**
 * Canned TaxCloud `verifyAddress` response (ErrNumber 0 = success). Fired via
 * the taxcloud_lookup_before -> Observer\Sales\Address path during checkout
 * when verify_address is enabled (it is, in the seeded baseline).
 *
 * Echoes back a valid, USPS-normalized US address. The exact values don't
 * affect the observer-wiring assertions (lookup returns zero tax regardless),
 * but a well-formed ErrNumber-0 result keeps the verify path on its happy
 * branch instead of the swallowed-error branch.
 */

declare(strict_types=1);

return [
    'VerifyAddressResult' => [
        'ErrNumber'      => 0,
        'ErrDescription' => '',
        'Address1'       => '1401 LAVACA ST',
        'Address2'       => '',
        'City'           => 'AUSTIN',
        'State'          => 'TX',
        'Zip5'           => '78701',
        'Zip4'           => '1634',
    ],
];
