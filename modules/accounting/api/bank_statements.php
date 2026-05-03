<?php
/**
 * Accounting API — Bank statement import + line matching.
 *
 *   GET  /api/accounting/bank_statements?bank_account_id=N[&match_status=unmatched]
 *   POST /api/accounting/bank_statements?action=import_csv&bank_account_id=N
 *        Body: { csv: <text>, header_map?: { date_col, desc_col, amount_col, fitid_col } }
 *   POST /api/accounting/bank_statements?action=match&line_id=N         Body: { je_id }
 *   POST /api/accounting/bank_statements?action=unmatch&line_id=N
 *   POST /api/accounting/bank_statements?action=ignore&line_id=N
 *   POST /api/accounting/bank_statements?action=apply_rules&bank_account_id=N
 *        → walks unmatched lines and auto-applies any approved rule that matches.
 *          Suggested (is_approved=0) rules write ai_suggested_* fields instead.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';
require_once __DIR__ . '/../lib/bank_rec.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    RBAC::requirePermission($user, 'accounting.coa.view');
    $bid = (int) ($_GET['bank_account_id'] ?? 0);
    if ($bid <= 0) api_error('bank_account_id required', 400);
    $where  = ['tenant_id = :tenant_id', 'bank_account_id = :b'];
    $params = ['b' => $bid];
    if (!empty($_GET['match_status'])) {
        $where[] = 'match_status = :ms';
        $params['ms'] = (string) $_GET['match_status'];
    }
    $rows = scopedQuery(
        'SELECT id, posted_date, description, amount, bank_reference, fitid, match_status,
                matched_je_id, matched_at, ai_suggested_account_code, ai_suggested_je_id,
                ai_suggested_rule_id, ai_suggested_confidence, applied_rule_id
         FROM accounting_bank_statement_lines
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY posted_date DESC, id DESC LIMIT 500',
        $params
    );
    api_ok(['rows' => $rows]);
}

if ($method === 'POST' && $action === 'import_csv') {
    RBAC::requirePermission($user, 'accounting.je.create');
    $bid = (int) ($_GET['bank_account_id'] ?? 0);
    if ($bid <= 0) api_error('bank_account_id required', 400);
    $bank = scopedFind('SELECT id FROM accounting_bank_accounts WHERE tenant_id = :tenant_id AND id = :id', ['id' => $bid]);
    if (!$bank) api_error('Bank account not found', 404);
    $body = api_json_body();
    $csv  = (string) ($body['csv'] ?? '');
    $map  = $body['header_map'] ?? null;
    if ($csv === '') api_error('csv body required', 422);
    $res = bankRecImportCsv((int) $ctx['tenant_id'], $bid, $csv, is_array($map) ? $map : null, $user['id'] ?? null);
    accountingAudit('accounting.bank.statement_imported',
        ['bank_account_id' => $bid, 'rows' => $res['inserted'], 'duplicates' => $res['duplicates']],
        $res['import_id']);
    api_ok($res, 201);
}

if ($method === 'POST' && $action === 'match') {
    RBAC::requirePermission($user, 'accounting.je.create');
    $lid = (int) ($_GET['line_id'] ?? 0);
    if ($lid <= 0) api_error('line_id required', 400);
    $body = api_json_body();
    $jeId = (int) ($body['je_id'] ?? 0);
    if ($jeId <= 0) api_error('je_id required', 422);
    $res = bankRecMatchLine((int) $ctx['tenant_id'], $lid, $jeId, $user['id'] ?? null);
    accountingAudit('accounting.bank.line_matched', ['line_id' => $lid, 'je_id' => $jeId], $lid);
    api_ok($res);
}

if ($method === 'POST' && $action === 'unmatch') {
    RBAC::requirePermission($user, 'accounting.je.create');
    $lid = (int) ($_GET['line_id'] ?? 0);
    if ($lid <= 0) api_error('line_id required', 400);
    $res = bankRecUnmatchLine((int) $ctx['tenant_id'], $lid);
    accountingAudit('accounting.bank.line_unmatched', ['line_id' => $lid], $lid);
    api_ok($res);
}

if ($method === 'POST' && $action === 'ignore') {
    RBAC::requirePermission($user, 'accounting.je.create');
    $lid = (int) ($_GET['line_id'] ?? 0);
    if ($lid <= 0) api_error('line_id required', 400);
    scopedUpdate('accounting_bank_statement_lines', $lid, ['match_status' => 'ignored']);
    accountingAudit('accounting.bank.line_ignored', ['line_id' => $lid], $lid);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'apply_rules') {
    RBAC::requirePermission($user, 'accounting.je.create');
    $bid = (int) ($_GET['bank_account_id'] ?? 0);
    if ($bid <= 0) api_error('bank_account_id required', 400);
    $res = bankRecApplyRules((int) $ctx['tenant_id'], $bid, $user['id'] ?? null);
    accountingAudit('accounting.bank.rules_applied',
        ['bank_account_id' => $bid, 'auto_applied' => $res['auto_applied'], 'suggested' => $res['suggested']]);
    api_ok($res);
}

api_error('Method not allowed', 405);
