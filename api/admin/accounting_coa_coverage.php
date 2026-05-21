<?php
/**
 * /api/admin/accounting_coa_coverage.php — chart of accounts coverage
 * report. Shows, for every CoreFlux account in the active tenant,
 * whether it's mapped on QBO and Zoho Books, plus the JE-reference
 * count over the last 90 days so a CFO can prioritise unmapped
 * high-traffic accounts.
 *
 *   GET                                  — coverage report
 *   POST { account_id, system }          — auto-discover a single mapping
 *                                            system ∈ {qbo, zoho_books}
 *
 * RBAC: read = `integrations.qbo.view` (same gate as dashboard);
 *       write = `integrations.qbo.manage` (touches both systems'
 *       mapping cache via the resolver).
 *
 * Response shape (GET):
 *   {
 *     accounts: [
 *       { id, code, name, account_type, active,
 *         qbo_mapped: bool, qbo_external_id, qbo_external_name,
 *         zoho_mapped: bool, zoho_external_id, zoho_external_name,
 *         je_refs_90d: int,
 *         coverage: 'both'|'qbo_only'|'zoho_only'|'neither'
 *       }, ...
 *     ],
 *     summary: { total, mapped_both, qbo_only, zoho_only, unmapped },
 *     qbo_active: bool, zoho_active: bool
 *   }
 *
 * Response shape (POST):
 *   {
 *     account_id, system, status: 'mapped'|'not_found'|'error',
 *     external_id?, external_name?, error?
 *   }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/qbo/client.php';
require_once __DIR__ . '/../../core/qbo/sync_je.php';      // qboResolveAccountRef
require_once __DIR__ . '/../../core/zoho_books/client.php';
require_once __DIR__ . '/../../core/zoho_books/sync_je.php';  // zohoBooksResolveAccountRef

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

$method = api_method();
$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

// ---------------------------------------------------------------------
// POST — auto-discover one mapping on demand
// ---------------------------------------------------------------------
if ($method === 'POST') {
    rbac_legacy_require($user, 'integrations.qbo.manage');
    $body      = api_json_body();
    $accountId = (int) ($body['account_id'] ?? 0);
    $system    = strtolower(trim((string) ($body['system'] ?? '')));

    if ($accountId <= 0)                                api_error('account_id required', 422);
    if (!in_array($system, ['qbo', 'zoho_books'], true)) api_error('system must be qbo or zoho_books', 422);

    // Validate the account belongs to this tenant before exposing it
    // to a cross-system call.
    $aStmt = $pdo->prepare('SELECT id, code, name FROM accounting_accounts WHERE id = :id AND tenant_id = :t LIMIT 1');
    $aStmt->execute(['id' => $accountId, 't' => $tid]);
    $acct = $aStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$acct) api_error('account_id not found in this tenant', 404);

    try {
        $ref = $system === 'qbo'
            ? qboResolveAccountRef($tid, $accountId)
            : zohoBooksResolveAccountRef($tid, $accountId);
    } catch (\Throwable $e) {
        api_ok([
            'account_id' => $accountId, 'system' => $system,
            'status'     => 'error', 'error'  => $e->getMessage(),
        ]);
    }
    if (!$ref) {
        api_ok([
            'account_id' => $accountId, 'system' => $system,
            'status'     => 'not_found',
            'note'       => 'No account with code "' . $acct['code'] . '" found in ' . $system . '. Create one there, or rename to match the CoreFlux code.',
        ]);
    }
    api_ok([
        'account_id'     => $accountId,
        'system'         => $system,
        'status'         => 'mapped',
        'external_id'    => (string) $ref['value'],
        'external_name'  => (string) ($ref['name'] ?? ''),
    ]);
}

// ---------------------------------------------------------------------
// GET — build the coverage report
// ---------------------------------------------------------------------
if ($method !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'integrations.qbo.view');

// One pull of every account (active or not) — the report includes
// inactive accounts so CFOs can confirm they don't need mappings either.
$accStmt = $pdo->prepare(
    'SELECT id, code, name, account_type, active
       FROM accounting_accounts
      WHERE tenant_id = :t
   ORDER BY code ASC'
);
$accStmt->execute(['t' => $tid]);
$accounts = $accStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

if (count($accounts) === 0) {
    api_ok([
        'accounts'   => [],
        'summary'    => ['total' => 0, 'mapped_both' => 0, 'qbo_only' => 0, 'zoho_only' => 0, 'unmapped' => 0],
        'qbo_active' => false,
        'zoho_active'=> false,
    ]);
}

// Bulk-load mappings for both systems in two queries (vs N per account).
$mapStmt = $pdo->prepare(
    "SELECT source_system, internal_entity_id, external_id, payload_snapshot
       FROM external_entity_mappings
      WHERE tenant_id = :t
        AND internal_entity_type = 'account'
        AND source_system IN ('quickbooks_online', 'zoho_books')"
);
$mapStmt->execute(['t' => $tid]);
$qboMap = []; $zohoMap = [];
foreach ($mapStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $m) {
    $snap = !empty($m['payload_snapshot']) ? json_decode((string) $m['payload_snapshot'], true) : null;
    $bucket = ($m['source_system'] === 'zoho_books') ? 'zoho' : 'qbo';
    $rec = [
        'external_id'   => (string) $m['external_id'],
        'external_name' => is_array($snap)
            ? (string) ($snap['Name'] ?? $snap['account_name'] ?? '')
            : '',
    ];
    if ($bucket === 'qbo') $qboMap[(int) $m['internal_entity_id']] = $rec;
    else                   $zohoMap[(int) $m['internal_entity_id']] = $rec;
}

// JE references over the last 90 days. Joins the journal entry lines
// directly so we get a usage signal even for accounts that are mapped
// but referenced by skipped JEs.
$refStmt = $pdo->prepare(
    "SELECT l.account_id, COUNT(*) AS ref_count
       FROM accounting_journal_entry_lines l
       JOIN accounting_journal_entries je ON je.id = l.je_id
      WHERE je.tenant_id = :t
        AND je.posting_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
   GROUP BY l.account_id"
);
$refStmt->execute(['t' => $tid]);
$refMap = [];
foreach ($refStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
    $refMap[(int) $r['account_id']] = (int) $r['ref_count'];
}

$summary = ['total' => 0, 'mapped_both' => 0, 'qbo_only' => 0, 'zoho_only' => 0, 'unmapped' => 0];
$out = [];
foreach ($accounts as $a) {
    $aid    = (int) $a['id'];
    $qboMp  = $qboMap[$aid]  ?? null;
    $zohoMp = $zohoMap[$aid] ?? null;
    $qOk = $qboMp  !== null;
    $zOk = $zohoMp !== null;
    if      ( $qOk &&  $zOk) { $coverage = 'both';      $summary['mapped_both']++; }
    elseif  ( $qOk && !$zOk) { $coverage = 'qbo_only';  $summary['qbo_only']++; }
    elseif  (!$qOk &&  $zOk) { $coverage = 'zoho_only'; $summary['zoho_only']++; }
    else                     { $coverage = 'neither';   $summary['unmapped']++; }
    $summary['total']++;

    $out[] = [
        'id'                 => $aid,
        'code'               => (string) $a['code'],
        'name'               => (string) $a['name'],
        'account_type'       => (string) $a['account_type'],
        'active'             => (bool) (int) $a['active'],
        'qbo_mapped'         => $qOk,
        'qbo_external_id'    => $qboMp ['external_id']   ?? null,
        'qbo_external_name'  => $qboMp ['external_name'] ?? null,
        'zoho_mapped'        => $zOk,
        'zoho_external_id'   => $zohoMp['external_id']   ?? null,
        'zoho_external_name' => $zohoMp['external_name'] ?? null,
        'je_refs_90d'        => $refMap[$aid] ?? 0,
        'coverage'           => $coverage,
    ];
}

$qboConn  = qboConnection($tid);
$zohoConn = zohoBooksConnection($tid);
api_ok([
    'accounts'    => $out,
    'summary'     => $summary,
    'qbo_active'  => (bool) ($qboConn  && $qboConn['status']  === 'active'),
    'zoho_active' => (bool) ($zohoConn && $zohoConn['status'] === 'active' && (string) $zohoConn['organization_id'] !== 'pending'),
]);
