<?php
/**
 * Canned TaxCloud `Returned` response: ResponseType OK.
 *
 * The `Returned` SOAP operation backs both Api::returnOrder() (credit-memo
 * refund path) and Api::returnOrderCancellation() (cancel-without-invoice
 * path). A bare OK is all either method needs to consider the return
 * successful.
 */

declare(strict_types=1);

return [
    'ReturnedResult' => [
        'ResponseType' => 'OK',
        'Messages'     => '',
    ],
];
