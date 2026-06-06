<?php
/**
 * GET /api/admin/integrations_health.php
 *
 * Surfaces the contract-smoke + spec-vendor freshness state for every
 * provider that follows the
 *     spec/<p>_(openapi|schema).json
 *   + tests/<p>_payload_contract_smoke.php
 *   + tests/<p>_spec_freshness_smoke.php
 *   + tools/refresh_<p>_spec.sh
 * triplet pattern. One glance and the operator knows whether any
 * vendor schema has drifted under us before a stuck outbox surfaces
 * the problem.
 *
 * Read-only. Gated to master_admin / tenant_admin via the legacy
 * RBAC layer because it touches integration internals.
 *
 * Response shape:
 *   { providers: [
 *       { id: 'jaz',
 *         spec: { path, size_bytes, last_modified_iso, days_old },
 *         snapshot: { dir, fetched_at_iso, days_old, status: fresh|stale|missing },
 *         smokes: { contract: { path, exists }, freshness: { path, exists } },
 *         tool:   { path, exists, executable },
 *         overall: 'ok' | 'attention' | 'missing'
 *       }, …
 *   ] }
 */

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';

rbac_legacy_require_any($currentUser ?? [], ['master_admin', 'tenant_admin', '*']);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    http_response_code(405);
    api_err('method_not_allowed', 'GET only');
}

$ROOT = realpath(__DIR__ . '/../..');

/**
 * Every provider that follows the contract-smoke triplet pattern.
 * Adding a new provider here automatically surfaces it in the UI
 * widget; nothing else to wire.
 */
$providers = [
    [
        'id'        => 'jaz',
        'label'     => 'Jaz',
        'spec'      => $ROOT . '/spec/jaz_openapi.json',
        'snapshot'  => null, // vendored upstream OpenAPI; no HTML snapshot dir
        'contract'  => $ROOT . '/tests/jaz_payload_contract_smoke.php',
        'freshness' => $ROOT . '/tests/jaz_spec_freshness_smoke.php',
        'tool'      => $ROOT . '/tools/refresh_jaz_spec.sh',
    ],
    [
        'id'        => 'qbo',
        'label'     => 'QuickBooks Online',
        'spec'      => $ROOT . '/spec/qbo_schema.json',
        'snapshot'  => $ROOT . '/spec/qbo_docs', // HTML snapshot dir
        'contract'  => $ROOT . '/tests/qbo_payload_contract_smoke.php',
        'freshness' => $ROOT . '/tests/qbo_spec_freshness_smoke.php',
        'tool'      => $ROOT . '/tools/refresh_qbo_spec.sh',
    ],
];

$STALE_AFTER_DAYS = 90;
$nowTs = time();

function _ageDays(int $ts, int $now): int {
    if ($ts <= 0) return -1;
    return (int) floor(($now - $ts) / 86400);
}

function _iso(int $ts): ?string {
    return $ts > 0 ? gmdate('c', $ts) : null;
}

$out = [];
foreach ($providers as $p) {
    $row = [
        'id'    => $p['id'],
        'label' => $p['label'],
    ];

    // Spec file freshness — uses mtime as the "last touched" stamp.
    if (is_file($p['spec'])) {
        $mtime = (int) @filemtime($p['spec']);
        $row['spec'] = [
            'path'             => str_replace($GLOBALS['ROOT'] ?? '', '', $p['spec']),
            'size_bytes'       => (int) @filesize($p['spec']),
            'last_modified_iso'=> _iso($mtime),
            'days_old'         => _ageDays($mtime, $nowTs),
        ];
    } else {
        $row['spec'] = ['path' => $p['spec'], 'exists' => false];
    }

    // Snapshot dir (HTML-derived providers only).
    if ($p['snapshot']) {
        $marker = $p['snapshot'] . '/.fetched_at';
        if (is_dir($p['snapshot']) && is_file($marker)) {
            $ts = strtotime(trim((string) @file_get_contents($marker)));
            $days = _ageDays((int) $ts, $nowTs);
            $row['snapshot'] = [
                'dir'             => $p['snapshot'],
                'fetched_at_iso'  => _iso((int) $ts),
                'days_old'        => $days,
                'status'          => $days < 0 ? 'unknown' :
                                     ($days <= $STALE_AFTER_DAYS ? 'fresh' : 'stale'),
            ];
        } else {
            $row['snapshot'] = [
                'dir'    => $p['snapshot'],
                'status' => 'missing',
            ];
        }
    } else {
        $row['snapshot'] = null;
    }

    // Companion artefacts.
    $row['smokes'] = [
        'contract'  => ['path' => $p['contract'],  'exists' => is_file($p['contract'])],
        'freshness' => ['path' => $p['freshness'], 'exists' => is_file($p['freshness'])],
    ];
    $row['tool'] = [
        'path'       => $p['tool'],
        'exists'     => is_file($p['tool']),
        'executable' => is_file($p['tool']) && is_executable($p['tool']),
    ];

    // Roll-up status. `missing` if anything required is absent, `attention`
    // if the snapshot is stale, otherwise `ok`.
    $missing = !is_file($p['spec'])
               || !$row['smokes']['contract']['exists']
               || !$row['smokes']['freshness']['exists']
               || !$row['tool']['exists'];
    $stale   = ($row['snapshot']['status'] ?? null) === 'stale';
    $row['overall'] = $missing ? 'missing' : ($stale ? 'attention' : 'ok');

    $out[] = $row;
}

api_ok([
    'providers'        => $out,
    'stale_after_days' => $STALE_AFTER_DAYS,
    'generated_at_iso' => gmdate('c', $nowTs),
]);
