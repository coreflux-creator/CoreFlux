<?php
/**
 * Internal HMAC-signed mappings lookup.
 *
 * Path: /api/internal/mappings_lookup.php
 *
 * Lets Node subgraphs translate between CoreFlux internal IDs and the
 * external IDs stored in `external_entity_mappings`, without giving
 * them direct DB access.
 *
 * Same HMAC scheme as jobdiva_proxy.php (see that file for the
 * security model).
 *
 * Request body:
 *   { "op": "find_external_by_internal",
 *     "tenant_id": 17,
 *     "source_system": "jobdiva",
 *     "internal_entity_type": "placement",
 *     "internal_entity_id":   42 }
 *   → { "ok": true, "external_id": "5581186" }
 *
 *   { "op": "find_internal_by_external",
 *     "tenant_id": 17,
 *     "source_system": "jobdiva",
 *     "internal_entity_type": "placement",
 *     "external_id":   "5581186" }
 *   → { "ok": true, "internal_id": 42 }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/integrations/entity_mappings.php';

header('Content-Type: application/json');

$secret = (string) (getenv('INTERNAL_HMAC_SECRET') ?: '');
if ($secret === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'internal bridge disabled: INTERNAL_HMAC_SECRET unset']);
    exit;
}

$rawBody = (string) file_get_contents('php://input');
$ts      = (string) ($_SERVER['HTTP_X_INTERNAL_TIMESTAMP'] ?? '');
$sig     = (string) ($_SERVER['HTTP_X_INTERNAL_SIGNATURE'] ?? '');

if ($ts === '' || $sig === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'missing signature headers']);
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

$req = json_decode($rawBody, true);
if (!is_array($req)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'body must be JSON']);
    exit;
}
$op       = (string) ($req['op'] ?? '');
$tid      = (int)    ($req['tenant_id'] ?? 0);
$source   = (string) ($req['source_system'] ?? '');
$entType  = (string) ($req['internal_entity_type'] ?? '');

if ($tid <= 0 || $source === '' || $entType === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'tenant_id, source_system, internal_entity_type required']);
    exit;
}

try {
    switch ($op) {
        case 'find_external_by_internal': {
            $internalId = (int) ($req['internal_entity_id'] ?? 0);
            if ($internalId <= 0) throw new \InvalidArgumentException('internal_entity_id required');
            $row = mappingFindExternal($tid, $source, $entType, $internalId);
            echo json_encode([
                'ok' => true,
                'external_id' => $row['external_id'] ?? null,
                'last_synced_at' => $row['updated_at'] ?? null,
            ]);
            return;
        }
        case 'find_internal_by_external': {
            $externalId = (string) ($req['external_id'] ?? '');
            if ($externalId === '') throw new \InvalidArgumentException('external_id required');
            $row = mappingFindInternal($tid, $source, $entType, $externalId);
            echo json_encode([
                'ok' => true,
                'internal_id' => isset($row['internal_entity_id']) ? (int) $row['internal_entity_id'] : null,
            ]);
            return;
        }
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'unknown op: ' . $op]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
