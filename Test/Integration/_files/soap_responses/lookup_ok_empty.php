<?php
/**
 * Canned TaxCloud `lookup` response: ResponseType OK with an empty
 * CartItemsResponse. Api::lookupTaxes() treats an empty CartItemResponse as
 * "no tax to apply" and returns zero tax without error — exactly what the
 * observer-wiring tests want: a checkout that completes cleanly without the
 * lookup tax amount mattering to the assertions.
 *
 * Returned as an associative array; Api normalizes SOAP responses with
 * json_decode(json_encode($response), true), so arrays and stdClass behave
 * identically.
 */

declare(strict_types=1);

return [
    'LookupResult' => [
        'ResponseType' => 'OK',
        'Messages'     => '',
        'CartItemsResponse' => [
            'CartItemResponse' => [],
        ],
    ],
];
