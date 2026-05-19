<?php
/**
 * Accounting API — Journal Entries
 *
 *   GET    /api/accounting/journal_entries               → list (filters)
 *   GET    /api/accounting/journal_entries?id=N          → detail (header + lines)
 *   POST   /api/accounting/journal_entries               → create + post  body: {entity_id?,posting_date,memo?,lines:[{account_code,debit,credit,...}]}
 *   POST   /api/accounting/journal_entries?action=draft  → same payload, leaves status='draft'
 *   POST   /api/accounting/journal_entries?action=reverse&id=N  body: {reason}
 *   GET    /api/accounting/journal_entries?action=trial_balance&as_of=YYYY-MM-DD[&entity_id=]
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'trial_balance') {
    rbac_legacy_require($user, 'accounting.je.create');
    $asOf = (string) ($_GET['as_of'] ?? date('Y-m-d'));
    $eid  = !empty($_GET['entity_id']) ? (int) $_GET['entity_id'] : null;
    try {
        api_ok(['as_of' => $asOf, 'entity_id' => $eid, 'rows' => accountingTrialBalance($tid, $asOf, $eid)]);
    } catch (\Throwable $e) {
        error_log('trial_balance failed: ' . $e->getMessage());
        api_ok(['as_of' => $asOf, 'entity_id' => $eid, 'rows' => [], 'data_warning' => 'Trial balance not available — ' . $e->getMessage()]);
    }
}

if ($method === 'GET' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'accounting.je.create');
    $id = (int) $_GET['id'];
    $je = scopedFind('SELECT * FROM accounting_journal_entries WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$je) api_error('Not found', 404);
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT l.*, a.code AS account_code, a.name AS account_name, a.account_type
         FROM accounting_journal_entry_lines l
         JOIN accounting_accounts a ON a.id = l.account_id
         WHERE l.je_id = :id ORDER BY l.line_no'
    );
    $stmt->execute(['id' => $id]);
    api_ok(['entry' => $je, 'lines' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.je.create');
    $where  = ['je.tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['status']))        { $where[] = 'je.status = :s';            $params['s']   = $_GET['status']; }
    if (!empty($_GET['source_module'])) { $where[] = 'je.source_module = :src';   $params['src'] = $_GET['source_module']; }
    if (!empty($_GET['from']))          { $where[] = 'je.posting_date >= :f';     $params['f']   = $_GET['from']; }
    if (!empty($_GET['to']))            { $where[] = 'je.posting_date <= :to2';   $params['to2'] = $_GET['to']; }
    if (!empty($_GET['entity_id']))     { $where[] = 'je.entity_id = :e';         $params['e']   = (int) $_GET['entity_id']; }
    $accountJoin = '';
    if (!empty($_GET['account_code'])) {
        $accountJoin = ' INNER JOIN accounting_journal_entry_lines l ON l.je_id = je.id
                         INNER JOIN accounting_accounts a ON a.id = l.account_id ';
        $where[]   = 'a.code = :acode';
        $params['acode'] = (string) $_GET['account_code'];
    }
    $perPage = max(1, min(200, (int) ($_GET['per_page'] ?? 50)));
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;
    $rows = scopedQuery(
        'SELECT DISTINCT je.id, je.je_number, je.posting_date, je.entity_id, je.period_id, je.source_module,
                je.source_ref_type, je.source_ref_id, je.status, je.currency, je.total_debit, je.total_credit, je.memo, je.posted_at
         FROM accounting_journal_entries je ' . $accountJoin . '
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY je.posting_date DESC, je.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );
    $cnt = scopedQuery(
        'SELECT COUNT(DISTINCT je.id) AS c FROM accounting_journal_entries je ' . $accountJoin . ' WHERE ' . implode(' AND ', $where),
        $params
    );
    api_ok(['rows' => $rows, 'total' => (int) ($cnt[0]['c'] ?? 0), 'page' => $page, 'per_page' => $perPage]);
}

if ($method === 'POST' && $action === 'reverse') {
    rbac_legacy_require($user, 'accounting.je.reverse');
    $id = (int) ($_GET['id'] ?? 0);
    $body = api_json_body();
    $reason = trim((string) ($body['reason'] ?? ''));
    if ($reason === '') api_error('reason required', 422);
    try {
        $res = accountingReverseJe($tid, $id, $reason, $user['id'] ?? null);
    } catch (\Throwable $e) { api_error($e->getMessage(), 409); }
    accountingAudit('accounting.je.reversed', ['orig_id' => $id, 'reversal_id' => $res['je_id'], 'reason' => $reason], $id);
    api_ok($res);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'accounting.je.post');
    $body = api_json_body();
    api_require_fields($body, ['posting_date','lines']);
    $body['source_module'] = $body['source_module'] ?? 'manual';
    $postNow = ($action !== 'draft');
    try {
        $res = accountingPostJe($tid, $body, $user['id'] ?? null, $postNow);
    } catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    accountingAudit('accounting.je.' . ($postNow ? 'posted' : 'drafted'), [
        'je_id' => $res['je_id'], 'je_number' => $res['je_number'],
        'total' => $res['total_debit'], 'source' => $body['source_module'],
        'replay' => $res['idempotent_replay'],
    ], $res['je_id']);
    api_ok($res, 201);
}

api_error('Method not allowed', 405);
