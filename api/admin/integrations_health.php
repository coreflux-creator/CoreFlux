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
        // Charter primitive #5 — adapter overrides verifyCreate to assert
        // downstream status (active for journals, draft for bills/invoices).
        'verify_create' => true,
        // Charter primitive #6 — raw vendor body captured via JazApiException::$raw.
        'error_surface' => true,
        // Charter primitive #4 — operator-managed account mapping fallback.
        'mapping_fallback' => true,
    ],
    [
        'id'        => 'qbo',
        'label'     => 'QuickBooks Online',
        'spec'      => $ROOT . '/spec/qbo_schema.json',
        'snapshot'  => $ROOT . '/spec/qbo_docs', // HTML snapshot dir
        'contract'  => $ROOT . '/tests/qbo_payload_contract_smoke.php',
        'freshness' => $ROOT . '/tests/qbo_spec_freshness_smoke.php',
        'tool'      => $ROOT . '/tools/refresh_qbo_spec.sh',
        // Charter primitive #5 — procedural verifier in core/integrations/verify_create.php
        // stamps `pushed_unverified` after each POST.
        'verify_create' => true,
        // Charter primitive #6 — QboApiException::$raw carries the
        // truncated vendor response body (incl. Fault.Error[].code).
        'error_surface' => true,
        // Charter primitive #4 — `qbo_account_mapping_fallback_smoke.php` locks the contract.
        'mapping_fallback' => true,
    ],
    [
        'id'        => 'zoho',
        'label'     => 'Zoho Books',
        'spec'      => $ROOT . '/spec/zoho_schema.json',
        'snapshot'  => $ROOT . '/spec/zoho_docs',
        'contract'  => $ROOT . '/tests/zoho_payload_contract_smoke.php',
        'freshness' => $ROOT . '/tests/zoho_spec_freshness_smoke.php',
        'tool'      => $ROOT . '/tools/refresh_zoho_spec.sh',
        // Charter primitive #5 — procedural verifier (this session).
        'verify_create' => true,
        // Charter primitive #6 — ZohoBooksApiException::$raw carries the
        // truncated vendor response body; sync_je/bills/invoices persist
        // it to the audit log on every failure (this session).
        'error_surface' => true,
        // Charter primitive #4 — operator-managed account mapping fallback.
        'mapping_fallback' => true,
    ],
    [
        'id'        => 'mercury',
        'label'     => 'Mercury',
        'spec'      => $ROOT . '/spec/mercury_schema.json',
        'snapshot'  => $ROOT . '/spec/mercury_docs',
        'contract'  => $ROOT . '/tests/mercury_payload_contract_smoke.php',
        'freshness' => $ROOT . '/tests/mercury_spec_freshness_smoke.php',
        'tool'      => $ROOT . '/tools/refresh_mercury_spec.sh',
        // Banking API; #4 (mapping fallback) is n/a (no CoA).
        // Charter primitive #5 — procedural verifier (this session).
        'verify_create' => true,
        // Charter primitive #6 — MercuryApiException::$raw + $errorCode
        // are now persisted to mp_events at every originate catch site
        // (this session).
        'error_surface' => true,
        // No chart-of-accounts → primitive #4 doesn't apply (null = n/a).
        'mapping_fallback' => null,
    ],
    [
        'id'        => 'plaid',
        'label'     => 'Plaid',
        'spec'      => $ROOT . '/spec/plaid_schema.json',
        'snapshot'  => null, // OpenAPI is gigantic — we track a curated subset only
        'contract'  => $ROOT . '/tests/plaid_payload_contract_smoke.php',
        'freshness' => $ROOT . '/tests/plaid_spec_freshness_smoke.php',
        'tool'      => $ROOT . '/tools/refresh_plaid_spec.sh',
        // Plaid is read-mostly bank + transfers. #4 (CoA mapping fallback)
        // doesn't apply. #5 (verifyCreate) is implemented as polling via
        // plaid_transfer_sync.php — Plaid surfaces a transfer's outcome
        // through TRANSFER_EVENTS_UPDATE webhooks (see api/plaid_transfer_webhook.php).
        'verify_create' => true,
        'error_surface' => true,
        'mapping_fallback' => null,
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
    // Charter primitive #5 — declared by the provider config above;
    // the adapter class itself is the source of truth (this is a
    // declarative cache for the UI).
    $row['verify_create'] = (bool) ($p['verify_create'] ?? false);
    // Charter primitive #6 — same declarative cache for whether the
    // adapter throws a typed exception with the raw vendor body attached.
    $row['error_surface'] = (bool) ($p['error_surface'] ?? false);
    // Charter primitive #4 — null means n/a (e.g. banking APIs with no CoA).
    $row['mapping_fallback'] = array_key_exists('mapping_fallback', $p) ? $p['mapping_fallback'] : null;

    // ── Charter compliance score (this is the operator's one-glance pill) ──
    // Each primitive contributes 1 to numerator/denominator (n/a = neither).
    // 1: vendored spec file present
    // 2: contract smoke present
    // 3: freshness smoke present
    // 4: account-mapping fallback (operator grid consulted before vendor CoA query)
    // 5: verifyCreate primitive wired
    // 6: typed exception carries raw vendor body
    // 7: provider onboarded into the health panel (this endpoint itself = always 1)
    $primitives = [
        '1_spec'             => is_file($p['spec']),
        '2_contract_smoke'   => $row['smokes']['contract']['exists'] ?? false,
        '3_freshness_smoke'  => $row['smokes']['freshness']['exists'] ?? false,
        '4_mapping_fallback' => $row['mapping_fallback'],
        '5_verify_create'    => $row['verify_create'],
        '6_error_surface'    => $row['error_surface'],
        '7_health_onboarded' => true,
    ];
    $earned = 0; $total = 0;
    foreach ($primitives as $v) {
        if ($v === null) continue; // n/a — excluded from denominator
        $total++;
        if ($v === true) $earned++;
    }
    $row['charter'] = [
        'score_earned'    => $earned,
        'score_total'     => $total,
        'score_label'     => $earned . '/' . $total,
        'compliant'       => ($earned === $total),
        'primitives'      => $primitives,
    ];

    // Roll-up status. `missing` if anything required is absent, `attention`
    // if the snapshot is stale OR verifyCreate isn't wired OR the error
    // surface gap is open, otherwise `ok`.
    $missing = !is_file($p['spec'])
               || !$row['smokes']['contract']['exists']
               || !$row['smokes']['freshness']['exists']
               || !$row['tool']['exists'];
    $stale   = ($row['snapshot']['status'] ?? null) === 'stale';
    $verifyGap = $row['verify_create'] === false;
    $errSurfaceGap = $row['error_surface'] === false;
    $row['overall'] = $missing ? 'missing' : (($stale || $verifyGap || $errSurfaceGap) ? 'attention' : 'ok');

    $out[] = $row;
}

api_ok([
    'providers'        => $out,
    'stale_after_days' => $STALE_AFTER_DAYS,
    'generated_at_iso' => gmdate('c', $nowTs),
]);
