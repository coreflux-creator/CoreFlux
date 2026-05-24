<?php
/**
 * Internal HMAC-signed JobDiva proxy.
 *
 * Path: /api/internal/jobdiva_proxy.php
 *
 * Purpose
 * -------
 * Lets the Node `subgraph-jobdiva` service call JobDiva REST endpoints
 * without re-implementing JobDiva's auth flow (session token, refresh on
 * 401, error normalisation) in TypeScript. Reuses the canonical PHP
 * `jobdivaCall()` for every request so behaviour stays identical to the
 * existing cron workers and PHP REST surface.
 *
 * Security model
 * --------------
 * - This endpoint is INTERNAL ONLY. It is NEVER exposed publicly; in
 *   production the Apollo Router and Node subgraphs run on the same
 *   box as PHP-FPM and reach this endpoint over localhost.
 * - All requests must carry:
 *     X-Internal-Timestamp: <unix seconds>
 *     X-Internal-Signature: hmac_sha256(secret, "<ts>.<body>")
 * - Timestamps older than 60s or in the future >5s are rejected.
 * - Shared secret read from env INTERNAL_HMAC_SECRET. If unset, the
 *   endpoint disables itself (returns 503) so a misconfigured prod box
 *   can't accidentally accept unsigned requests.
 *
 * Request body (JSON)
 * -------------------
 *   {
 *     "tenant_id": 17,
 *     "method":    "POST",
 *     "path":      "/apiv2/jobdiva/searchJob",
 *     "body":      { "jobId": 12345 }   // optional
 *     "query":     { "limit": 50 }       // optional
 *   }
 *
 * Response (JSON)
 * ---------------
 *   { "ok": true,  "data": <jobdivaCall result> }
 *   { "ok": false, "error": "<message>", "status": 4xx|5xx }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/jobdiva/client.php';

header('Content-Type: application/json');

// ---------------------------------------------------------------------
// Shared secret
// ---------------------------------------------------------------------
$secret = (string) (getenv('INTERNAL_HMAC_SECRET') ?: '');
if ($secret === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'internal bridge disabled: INTERNAL_HMAC_SECRET unset']);
    exit;
}

// ---------------------------------------------------------------------
// Read + verify HMAC
// ---------------------------------------------------------------------
$rawBody = (string) file_get_contents('php://input');
$ts      = (string) ($_SERVER['HTTP_X_INTERNAL_TIMESTAMP'] ?? '');
$sig     = (string) ($_SERVER['HTTP_X_INTERNAL_SIGNATURE'] ?? '');

if ($ts === '' || $sig === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'missing X-Internal-Timestamp or X-Internal-Signature']);
    exit;
}
$tsInt = (int) $ts;
$now   = time();
if ($tsInt < $now - 60 || $tsInt > $now + 5) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'timestamp out of window']);
    exit;
}
$expected = hash_hmac('sha256', $ts . '.' . $rawBody, $secret);
if (!hash_equals($expected, $sig)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'bad signature']);
    exit;
}

// ---------------------------------------------------------------------
// Parse + dispatch
// ---------------------------------------------------------------------
$req = json_decode($rawBody, true);
if (!is_array($req)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'body must be JSON object']);
    exit;
}
$tenantId = (int) ($req['tenant_id'] ?? 0);
$method   = strtoupper((string) ($req['method'] ?? 'POST'));
$path     = (string) ($req['path'] ?? '');
$body     = isset($req['body'])  && is_array($req['body'])  ? $req['body']  : null;
$query    = isset($req['query']) && is_array($req['query']) ? $req['query'] : null;

if ($tenantId <= 0 || $path === '' || !in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'tenant_id, method, path required']);
    exit;
}

// Hard guard: only forward to /apiv2/jobdiva/* — never an arbitrary URL.
if (strpos($path, '/apiv2/jobdiva/') !== 0 && strpos($path, '/apiv2/v2/') !== 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'path must start with /apiv2/jobdiva/']);
    exit;
}

try {
    $resp = jobdivaCall($tenantId, $method, $path, $body, $query);
    echo json_encode(['ok' => true, 'data' => $resp], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
