<?php
/**
 * Canned TaxCloud `OrderDetails` response describing an order that WAS captured
 * in TaxCloud (non-empty CapturedDate).
 *
 * Observer\Sales\Cancel::processOrderCancel() calls Api::getOrderDetails() and
 * only proceeds to Returned when CapturedDate is non-empty — that guard is how
 * it distinguishes "this order was really captured in TaxCloud, so reverse it"
 * from "nothing to undo". The cancel-wiring test needs this to be present so
 * the cancellation reaches the Returned call.
 */

declare(strict_types=1);

return [
    'OrderDetailsResult' => [
        'ResponseType'  => 'OK',
        'Messages'      => '',
        'LookupDate'    => '2026-01-01T00:00:00',
        'AuthorizedDate' => '2026-01-01T00:00:00',
        'CapturedDate'  => '2026-01-01T00:00:00',
        'ReturnedDate'  => '',
    ],
];
