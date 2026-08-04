<?php
/**
 * Taxcloud_Magento2 — stand-in for the TaxCloud v1→v3 credential exchange.
 *
 * Run with PHP's built-in server by the CredentialMigrator integration test:
 *   php -S 127.0.0.1:<port> Test/Integration/_files/rest_auth_stub.php
 *
 * POST /api/v3/auth/token with {apiLoginID, apiKey}:
 *   - apiLoginID beginning with "bad" → 400 (rejected pair)
 *   - anything else → 200 with a canned Bearer token
 *
 * Every request appends one line to the hit log (path given via the
 * TC_STUB_HIT_LOG env var) so tests can assert on call counts without
 * parsing server output.
 */

$hitLog = getenv('TC_STUB_HIT_LOG');
if ($hitLog) {
    file_put_contents($hitLog, $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
}

header('Content-Type: application/json');

if (($_SERVER['REQUEST_URI'] ?? '') !== '/api/v3/auth/token' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404);
    echo json_encode(['message' => 'not found']);
    return;
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$apiLoginId = (string) ($body['apiLoginID'] ?? '');

if ($apiLoginId === '' || strpos($apiLoginId, 'bad') === 0) {
    http_response_code(400);
    echo json_encode(['message' => 'invalid credentials']);
    return;
}

echo json_encode([
    'access_token' => 'stub-jwt-token',
    'access_token_validTo' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
    'token_type' => 'Bearer',
    'scope' => 'v3_api',
]);
