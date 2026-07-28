<?php
/**
 * Canned TaxCloud `OrderDetails` response describing an order TaxCloud has a
 * Lookup for but no capture (empty CapturedDate).
 *
 * This is what the API reports for an order placed under capture_trigger =
 * payment or shipment that never reached the capturing step: the tax was quoted
 * at checkout, but the sale was never authorized/captured.
 *
 * CancellationProcessor::wasCapturedInTaxcloud() falls back to OrderDetails when
 * the local taxcloud_captured flag is absent, and only proceeds to Returned when
 * CapturedDate is non-empty — so with this response a cancellation must be a
 * no-op. The companion fixture order_details_captured.php covers the other side.
 */

declare(strict_types=1);

return [
    'OrderDetailsResult' => [
        'ResponseType'   => 'OK',
        'Messages'       => '',
        'LookupDate'     => '2026-01-01T00:00:00',
        'AuthorizedDate' => '',
        'CapturedDate'   => '',
        'ReturnedDate'   => '',
    ],
];
