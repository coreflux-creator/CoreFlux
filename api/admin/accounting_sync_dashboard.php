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

// ---------------------------------------------------------------------
// Transaction Value at Risk widget — per-integration snapshot of the
// dollar value of push-eligible transactions that have NOT yet been
// shipped to the external system (i.e. no mapping row exists for them).
//
// Per integration we surface:
//   - pending_amount   : sum of dollar value of unmapped eligible txns
//   - pending_count    : count of those txns
//   - oldest_age_minutes : minutes since the oldest unmapped txn was
//     created (drives the green/amber/red health pip in the UI)
//   - by_entity        : same numbers broken down by invoices/bills/payments
//   - sparkline_24h    : 24 hourly buckets, each bucket = { hour, amount, count }
//     where amount/count = pending NEW arrivals during that hour (by
//     billing_invoices.created_at / ap_bills.created_at / ap_payments.created_at).
//
// SQL is intentionally per-entity (one query each) to keep the joins
// trivial and to avoid UNION ALL gymnastics on different columns.
// ---------------------------------------------------------------------
$VAR_ENTITIES = [
    'invoices' => [
        'table'      => 'billing_invoices',
        'amount_col' => 'COALESCE(amount_due, total, 0)',
        'eligible'   => "status IN ('approved','sent','partially_paid')",
    ],
    'bills' => [
        'table'      => 'ap_bills',
        'amount_col' => 'COALESCE(amount_due, total, 0)',
        'eligible'   => "status IN ('approved','partially_paid')",
    ],
    'payments' => [
        'table'      => 'ap_payments',
        'amount_col' => 'COALESCE(amount, 0)',
        'eligible'   => "status IN ('sent','cleared')",
    ],
];

function _atRiskFor(\PDO $pdo, int $tenantId, string $source, string $sourceCfg, array $cfg, string $entityType): array
{
    // Skip entirely if direction is not push/two_way — there's no risk if the
    // tenant hasn't opted in.
    if (!in_array($sourceCfg, ['push', 'two_way'], true)) {
        return [
            'pending_amount' => 0.0, 'pending_count' => 0,
            'oldest_age_minutes' => null, 'eligible' => false,
        ];
    }
    $sql = "SELECT COALESCE(SUM({$cfg['amount_col']}), 0) AS amt,
                   COUNT(*)                              AS cnt,
                   MIN(t.created_at)                     AS oldest
              FROM {$cfg['table']} t
         LEFT JOIN external_entity_mappings m
                ON m.tenant_id = t.tenant_id
               AND m.source_system = :src
               AND m.internal_entity_type = :etype
               AND m.internal_entity_id = t.id
             WHERE t.tenant_id = :t
               AND m.id IS NULL
               AND {$cfg['eligible']}";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['t' => $tenantId, 'src' => $source, 'etype' => $entityType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['amt' => 0, 'cnt' => 0, 'oldest' => null];
    } catch (\Throwable $_) {
        return ['pending_amount' => 0.0, 'pending_count' => 0, 'oldest_age_minutes' => null, 'eligible' => true];
    }
    $oldestMinutes = null;
    if (!empty($row['oldest'])) {
        $ts = strtotime((string) $row['oldest']);
        if ($ts) $oldestMinutes = max(0, (int) round((time() - $ts) / 60));
    }
    return [
        'pending_amount'     => round((float) $row['amt'], 2),
        'pending_count'      => (int) $row['cnt'],
        'oldest_age_minutes' => $oldestMinutes,
        'eligible'           => true,
    ];
}

function _atRiskSparkline(\PDO $pdo, int $tenantId, string $source, array $entityMap, array $cfgMap): array
{
    // Build 24 hourly buckets ending at the current hour.
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $cursor = $now->modify('-23 hours')->setTime((int) $now->modify('-23 hours')->format('H'), 0, 0);
    $buckets = [];
    for ($i = 0; $i < 24; $i++) {
        $start = $cursor->modify('+' . $i . ' hours');
        $buckets[$start->format('Y-m-d H:00')] = ['hour' => $start->format('Y-m-d\TH:00:00\Z'), 'amount' => 0.0, 'count' => 0];
    }
    foreach ($entityMap as $entityType => $cfgKey) {
        $cfg = $cfgMap[$cfgKey];
        $sql = "SELECT DATE_FORMAT(t.created_at, '%Y-%m-%d %H:00') AS bucket,
                       COALESCE(SUM({$cfg['amount_col']}), 0)      AS amt,
                       COUNT(*)                                    AS cnt
                  FROM {$cfg['table']} t
             LEFT JOIN external_entity_mappings m
                    ON m.tenant_id = t.tenant_id
                   AND m.source_system = :src
                   AND m.internal_entity_type = :etype
                   AND m.internal_entity_id = t.id
                 WHERE t.tenant_id = :t
                   AND m.id IS NULL
                   AND {$cfg['eligible']}
                   AND t.created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
              GROUP BY bucket";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['t' => $tenantId, 'src' => $source, 'etype' => $entityType]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $k = (string) $r['bucket'];
                if (isset($buckets[$k])) {
                    $buckets[$k]['amount'] += round((float) $r['amt'], 2);
                    $buckets[$k]['count']  += (int) $r['cnt'];
                }
            }
        } catch (\Throwable $_) { /* table or column missing — skip silently */ }
    }
    return array_values($buckets);
}

function _atRiskRollup(\PDO $pdo, int $tenantId, string $source, array $configMap, array $entityCfgs): array
{
    $entityMap = [
        'invoice' => 'invoices',
        'bill'    => 'bills',
        'payment' => 'payments',
    ];
    $byEntity = [];
    $totalAmt = 0.0; $totalCnt = 0; $oldest = null;
    foreach ($entityMap as $internalType => $cfgKey) {
        $dir   = $configMap[$cfgKey] ?? 'off';
        $stats = _atRiskFor($pdo, $tenantId, $source, $dir, $entityCfgs[$cfgKey], $internalType);
        $byEntity[$cfgKey] = $stats + ['direction' => $dir];
        $totalAmt += (float) $stats['pending_amount'];
        $totalCnt += (int)   $stats['pending_count'];
        if ($stats['oldest_age_minutes'] !== null) {
            $oldest = $oldest === null ? $stats['oldest_age_minutes'] : max($oldest, $stats['oldest_age_minutes']);
        }
    }
    $health = 'green';
    if ($oldest !== null) {
        if ($oldest >= 240)      $health = 'red';
        elseif ($oldest >= 30)   $health = 'amber';
    }
    return [
        'pending_amount'     => round($totalAmt, 2),
        'pending_count'      => $totalCnt,
        'oldest_age_minutes' => $oldest,
        'health'             => $health,
        'by_entity'          => $byEntity,
        'sparkline_24h'      => _atRiskSparkline($pdo, $tenantId, $source, $entityMap, $entityCfgs),
    ];
}

$varQbo  = _atRiskRollup($pdo, $tid, 'quickbooks_online', $qboCfg,  $VAR_ENTITIES);
$varZoho = _atRiskRollup($pdo, $tid, 'zoho_books',        $zohoCfg, $VAR_ENTITIES);

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
    'transaction_value_at_risk' => [
        'qbo'        => $varQbo,
        'zoho_books' => $varZoho,
    ],
]);
