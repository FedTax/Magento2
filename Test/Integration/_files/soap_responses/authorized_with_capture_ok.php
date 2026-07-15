<?php
/**
 * Canned TaxCloud `authorizedWithCapture` response: ResponseType OK.
 *
 * This is the call the capture observer (Observer\Sales\Complete -> the
 * configured capture trigger) makes. A bare OK is all Api::authorizeCapture()
 * needs to consider the capture successful.
 */

declare(strict_types=1);

return [
    'AuthorizedWithCaptureResult' => [
        'ResponseType' => 'OK',
        'Messages'     => '',
    ],
];
