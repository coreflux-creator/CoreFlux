<?php
/**
 * /api/admin/accounting_sync_dashboard.php — unified roll-up of every
 * accounting integration (QBO + Zoho Books) for the active tenant.
 *
 *   GET ?
 *
 *   Response shape:
 *   {
 *     qbo: {
 *       configured, connected, status, company_name, realm_id, environment,
 *       last_probe_at, last_probe_error, sync_config: {entity: direction},
 *       recent_activity: [...]                 // last 10 qbo_sync_audit rows
 *     },
 *     zoho_books: {
 *       configured, connected, status, organization_name, organization_id, dc,
 *       last_probe_at, last_probe_error, sync_config: {entity: direction},
 *       recent_activity: [...]                 // last 10 zoho_books_sync_audit rows
 *     },
 *     entities: [
 *       { key, label, qbo_dir, zoho_dir, qbo_last_sync, zoho_last_sync,
 *         qbo_last_ok, zoho_last_ok, coverage: 'both'|'qbo_only'|'zoho_only'|'neither',
 *         drift_signal: 'aligned'|'qbo_ahead'|'zoho_ahead'|'one_sided'|'inactive' },
 *       ...
 *     ],
 *     summary: { both, qbo_only, zoho_only, neither, total },
 *     unified_activity: [ { system:'qbo'|'zoho_books', ... }, ... ]   // last 30 rows merged + sorted
 *   }
 *
 * RBAC: `integrations.qbo.view` (sufficient — the wildcard covers
 * Zoho Books too via rbac_config). master_admin's `*` covers it.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/qbo/client.php';
require_once __DIR__ . '/../../core/zoho_books/client.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'integrations.qbo.view');

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

// ---------------------------------------------------------------------
// Unified entity grid. The seven canonical accounting entities. For
// Zoho Books, customers + vendors are both `contacts`; we surface the
// same Zoho direction on both rows so the drift view stays honest.
// ---------------------------------------------------------------------
$UNIFIED_ENTITIES = [
    ['key' => 'journal_entries',   'label' => 'Journal Entries',                'qbo' => 'journal_entries',   'zoho' => 'journal_entries'],
    ['key' => 'customers',         'label' => 'Customers',                      'qbo' => 'customers',         'zoho' => 'contacts'],
    ['key' => 'vendors',           'label' => 'Vendors',                        'qbo' => 'vendors',           'zoho' => 'contacts'],
    ['key' => 'invoices',          'label' => 'Invoices',                       'qbo' => 'invoices',          'zoho' => 'invoices'],
    ['key' => 'bills',             'label' => 'Bills',                          'qbo' => 'bills',             'zoho' => 'bills'],
    ['key' => 'payments',          'label' => 'Payments',                       'qbo' => 'payments',          'zoho' => 'payments'],
    ['key' => 'chart_of_accounts', 'label' => 'Chart of Accounts',              'qbo' => 'chart_of_accounts', 'zoho' => 'chart_of_accounts'],
];

// ---------------------------------------------------------------------
// QBO block
// ---------------------------------------------------------------------
$qboRow    = qboConnection($tid);
$qboCfg    = qboSyncConfigRead($tid);
$qboActive = $qboRow && $qboRow['status'] === 'active';

$qboRecent = $pdo->prepare(
    'SELECT id, action, entity_type, direction, ok,
            items_processed, items_skipped, items_failed,
            detail, occurred_at
       FROM qbo_sync_audit
      WHERE tenant_id = :t
   ORDER BY occurred_at DESC
      LIMIT 10'
);
$qboRecent->execute(['t' => $tid]);
$qboActivity = $qboRecent->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($qboActivity as &$r) {
    $r['id'] = (int) $r['id'];
    $r['ok'] = (bool) (int) $r['ok'];
    $r['items_processed'] = (int) $r['items_processed'];
    $r['items_skipped']   = (int) $r['items_skipped'];
    $r['items_failed']    = (int) $r['items_failed'];
    if (!empty($r['detail'])) {
        $decoded = json_decode((string) $r['detail'], true);
        $r['detail'] = is_array($decoded) ? $decoded : null;
    }
}
unset($r);

// ---------------------------------------------------------------------
// Zoho Books block
// ---------------------------------------------------------------------
$zohoRow    = zohoBooksConnection($tid);
$zohoCfg    = zohoBooksSyncConfigRead($tid);
$zohoActive = $zohoRow && $zohoRow['status'] === 'active'
              && (string) $zohoRow['organization_id'] !== 'pending';

$zohoRecent = $pdo->prepare(
    'SELECT id, action, entity_type, direction, ok,
            items_processed, items_skipped, items_failed,
            detail, occurred_at
       FROM zoho_books_sync_audit
      WHERE tenant_id = :t
   ORDER BY occurred_at DESC
      LIMIT 10'
);
$zohoRecent->execute(['t' => $tid]);
$zohoActivity = $zohoRecent->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($zohoActivity as &$r) {
    $r['id'] = (int) $r['id'];
    $r['ok'] = (bool) (int) $r['ok'];
    $r['items_processed'] = (int) $r['items_processed'];
    $r['items_skipped']   = (int) $r['items_skipped'];
    $r['items_failed']    = (int) $r['items_failed'];
    if (!empty($r['detail'])) {
        $decoded = json_decode((string) $r['detail'], true);
        $r['detail'] = is_array($decoded) ? $decoded : null;
    }
}
unset($r);

// ---------------------------------------------------------------------
// Per-entity last sync — derive from audit rows by entity_type.
// Returns [entity => ['at' => 'YYYY-MM-DD HH:MM:SS', 'ok' => bool] | null]
// ---------------------------------------------------------------------
$qboLastByEntity  = [];
$zohoLastByEntity = [];
$lastSyncStmtSql  = "SELECT entity_type,
                            MAX(occurred_at)               AS last_at,
                            SUBSTRING_INDEX(GROUP_CONCAT(ok ORDER BY occurred_at DESC), ',', 1) AS last_ok
                       FROM %s
                      WHERE tenant_id = :t
                        AND entity_type IS NOT NULL
                        AND action LIKE 'sync_%%'
                   GROUP BY entity_type";

try {
    $st = $pdo->prepare(sprintf($lastSyncStmtSql, 'qbo_sync_audit'));
    $st->execute(['t' => $tid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qboLastByEntity[(string) $r['entity_type']] = [
            'at' => (string) $r['last_at'],
            'ok' => (string) $r['last_ok'] === '1',
        ];
    }
} catch (\Throwable $_) { /* qbo tables not yet present */ }
try {
    $st = $pdo->prepare(sprintf($lastSyncStmtSql, 'zoho_books_sync_audit'));
    $st->execute(['t' => $tid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $zohoLastByEntity[(string) $r['entity_type']] = [
            'at' => (string) $r['last_at'],
            'ok' => (string) $r['last_ok'] === '1',
        ];
    }
} catch (\Throwable $_) { /* zoho tables not yet present */ }

// ---------------------------------------------------------------------
// Build per-entity unified rows with coverage + drift signals.
// drift_signal heuristic:
//   - 'aligned'    : both active + matching direction + neither stale
//   - 'qbo_ahead'  : qbo last_sync ≥ 1 day newer than zoho's
//   - 'zoho_ahead' : reciprocal
//   - 'one_sided'  : only one side is actively syncing
//   - 'inactive'   : neither side has direction != 'off'
// ---------------------------------------------------------------------
$entities = [];
$summary = ['both' => 0, 'qbo_only' => 0, 'zoho_only' => 0, 'neither' => 0, 'total' => count($UNIFIED_ENTITIES)];

foreach ($UNIFIED_ENTITIES as $row) {
    $qDir = $qboCfg[$row['qbo']]   ?? 'off';
    $zDir = $zohoCfg[$row['zoho']] ?? 'off';
    $qOn  = $qDir !== 'off';
    $zOn  = $zDir !== 'off';

    if      ($qOn && $zOn)  { $coverage = 'both';      $summary['both']++; }
    elseif  ($qOn && !$zOn) { $coverage = 'qbo_only';  $summary['qbo_only']++; }
    elseif  (!$qOn && $zOn) { $coverage = 'zoho_only'; $summary['zoho_only']++; }
    else                    { $coverage = 'neither';   $summary['neither']++; }

    $qLast = $qboLastByEntity[$row['qbo']]    ?? null;
    $zLast = $zohoLastByEntity[$row['zoho']]  ?? null;

    $driftSignal = 'inactive';
    if ($coverage === 'both') {
        $driftSignal = 'aligned';
        if ($qLast && $zLast) {
            $diff = strtotime($qLast['at']) - strtotime($zLast['at']);
            if (abs($diff) >= 86400) $driftSignal = $diff > 0 ? 'qbo_ahead' : 'zoho_ahead';
        }
    } elseif ($coverage === 'qbo_only' || $coverage === 'zoho_only') {
        $driftSignal = 'one_sided';
    }

    $entities[] = [
        'key'             => $row['key'],
        'label'           => $row['label'],
        'qbo_entity_key'  => $row['qbo'],
        'zoho_entity_key' => $row['zoho'],
        'qbo_dir'         => $qDir,
        'zoho_dir'        => $zDir,
        'qbo_last_sync'   => $qLast['at'] ?? null,
        'zoho_last_sync'  => $zLast['at'] ?? null,
        'qbo_last_ok'     => $qLast['ok'] ?? null,
        'zoho_last_ok'    => $zLast['ok'] ?? null,
        'coverage'        => $coverage,
        'drift_signal'    => $driftSignal,
    ];
}

// ---------------------------------------------------------------------
// Unified activity feed — merge + sort by occurred_at DESC, cap at 30.
// ---------------------------------------------------------------------
$unifiedActivity = [];
foreach ($qboActivity as $r) {
    $unifiedActivity[] = [
        'system'          => 'qbo',
        'id'              => $r['id'],
        'action'          => $r['action'],
        'entity_type'     => $r['entity_type'],
        'direction'       => $r['direction'],
        'ok'              => $r['ok'],
        'items_processed' => $r['items_processed'],
        'items_skipped'   => $r['items_skipped'],
        'items_failed'    => $r['items_failed'],
        'occurred_at'     => $r['occurred_at'],
    ];
}
foreach ($zohoActivity as $r) {
    $unifiedActivity[] = [
        'system'          => 'zoho_books',
        'id'              => $r['id'],
        'action'          => $r['action'],
        'entity_type'     => $r['entity_type'],
        'direction'       => $r['direction'],
        'ok'              => $r['ok'],
        'items_processed' => $r['items_processed'],
        'items_skipped'   => $r['items_skipped'],
        'items_failed'    => $r['items_failed'],
        'occurred_at'     => $r['occurred_at'],
    ];
}
usort($unifiedActivity, static fn($a, $b) => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));
$unifiedActivity = array_slice($unifiedActivity, 0, 30);

api_ok([
    'qbo' => [
        'configured'        => qboConfigured(),
        'connected'         => (bool) $qboActive,
        'status'            => $qboRow['status']           ?? null,
        'company_name'      => $qboRow['company_name']     ?? null,
        'realm_id'          => $qboRow['realm_id']         ?? null,
        'environment'       => $qboRow['environment']      ?? null,
        'last_probe_at'     => $qboRow['last_probe_at']    ?? null,
        'last_probe_error'  => $qboRow['last_probe_error'] ?? null,
        'sync_config'       => $qboCfg,
        'recent_activity'   => $qboActivity,
    ],
    'zoho_books' => [
        'configured'        => zohoBooksConfigured(),
        'connected'         => (bool) $zohoActive,
        'status'            => $zohoRow['status']            ?? null,
        'organization_name' => $zohoRow['organization_name'] ?? null,
        'organization_id'   => $zohoRow['organization_id']   ?? null,
        'dc'                => $zohoRow['dc']                ?? null,
        'last_probe_at'     => $zohoRow['last_probe_at']     ?? null,
        'last_probe_error'  => $zohoRow['last_probe_error']  ?? null,
        'sync_config'       => $zohoCfg,
        'recent_activity'   => $zohoActivity,
    ],
    'entities'         => $entities,
    'summary'          => $summary,
    'unified_activity' => $unifiedActivity,
]);
