<?php
/**
 * Accounting API — Intercompany mappings + split posts.
 *
 *   GET  /api/accounting/intercompany                       → list mappings
 *   GET  /api/accounting/intercompany?from_entity=A&to_entity=B  → resolve pair
 *   POST /api/accounting/intercompany                       → upsert mapping
 *   DELETE /api/accounting/intercompany?id=N                → deactivate mapping
 *   POST /api/accounting/intercompany?action=post_split     → post a split across N entities
 *   POST /api/accounting/intercompany?action=reverse_group  → reverse entire IC group
 *   GET  /api/accounting/intercompany?action=group&group_id=abcd → list JEs in a group
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';
require_once __DIR__ . '/../lib/intercompany.php';
require_once __DIR__ . '/../lib/cross_tenant_intercompany.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$uid    = (int) ($user['id'] ?? 0) ?: null;
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

// ── GET: list mappings ───────────────────────────────────────────────────
if ($method === 'GET' && $action === '') {
    rbac_legacy_require($user, 'accounting.intercompany.manage');
    if (!empty($_GET['from_entity']) && !empty($_GET['to_entity'])) {
        $m = intercompanyGetMapping($tid, (int) $_GET['from_entity'], (int) $_GET['to_entity']);
        api_ok(['mapping' => $m]);
    }
    $rows = scopedQuery(
        'SELECT m.*, ef.legal_name AS from_entity_name, et.legal_name AS to_entity_name
         FROM accounting_intercompany_mappings m
         LEFT JOIN accounting_entities ef ON ef.id = m.from_entity_id
         LEFT JOIN accounting_entities et ON et.id = m.to_entity_id
         WHERE m.tenant_id = :tenant_id
         ORDER BY m.from_entity_id, m.to_entity_id'
    );
    api_ok(['rows' => $rows]);
}

// ── GET: list JEs in a group ─────────────────────────────────────────────
if ($method === 'GET' && $action === 'group') {
    rbac_legacy_require($user, 'accounting.je.create');
    $g = (string) ($_GET['group_id'] ?? '');
    if ($g === '') api_error('group_id required', 400);
    $rows = scopedQuery(
        "SELECT id, je_number, entity_id, posting_date, status, total_debit, total_credit, memo
         FROM accounting_journal_entries
         WHERE tenant_id = :tenant_id AND intercompany_group_id = :g
         ORDER BY entity_id, id",
        ['g' => $g]
    );
    api_ok(['group_id' => $g, 'jes' => $rows]);
}

// ── POST: upsert mapping ─────────────────────────────────────────────────
if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'accounting.intercompany.manage');
    $body = api_json_body();
    api_require_fields($body, ['from_entity_id','to_entity_id','due_from_account_code','due_to_account_code']);
    try {
        $id = intercompanyUpsertMapping($tid, $body);
    } catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    api_ok(['id' => $id], 201);
}

// ── DELETE: deactivate mapping ───────────────────────────────────────────
if ($method === 'DELETE') {
    rbac_legacy_require($user, 'accounting.intercompany.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    scopedUpdate('accounting_intercompany_mappings', $id, ['active' => 0]);
    accountingAudit('accounting.intercompany.mapping_deactivated', ['id' => $id], $id);
    api_ok(['ok' => true]);
}

// ── POST: post a split ───────────────────────────────────────────────────
if ($method === 'POST' && $action === 'post_split') {
    $body = api_json_body();
    api_require_fields($body, ['source','splits']);
    // Cross-entity permission check (choice 3b): require accounting.je.post
    // in every target entity. Tenant-wide 'accounting.*' already grants it.
    rbac_legacy_require($user, 'accounting.je.post');
    try {
        $res = intercompanyPostSplit($tid, $body, $uid);
    } catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    api_ok($res, 201);
}

// ── POST: reverse group ──────────────────────────────────────────────────
if ($method === 'POST' && $action === 'reverse_group') {    rbac_legacy_require($user, 'accounting.je.reverse');
    $body = api_json_body();
    $gid    = trim((string) ($body['group_id'] ?? ''));
    $reason = trim((string) ($body['reason']   ?? ''));
    if ($gid === '')    api_error('group_id required', 400);
    if ($reason === '') api_error('reason required',   422);
    try {
        $res = intercompanyReverseGroup($tid, $gid, $reason, $uid);
    } catch (\Throwable $e) { api_error($e->getMessage(), 409); }
    api_ok($res);
}

// ── GET action=elimination_worksheet ─────────────────────────────────────
if ($method === 'GET' && $action === 'elimination_worksheet') {
    rbac_legacy_require($user, 'accounting.reports.view');
    $from = $_GET['from'] ?? null;
    $to   = $_GET['to']   ?? null;
    $res  = intercompanyEliminationWorksheet($tid, $from, $to);
    accountingAudit('accounting.intercompany.elimination_viewed', [
        'pair_count'       => $res['summary']['pair_count'],
        'imbalanced_pairs' => $res['summary']['imbalanced_pairs'],
        'orphan_count'     => $res['summary']['orphan_line_count'],
    ], null);
    api_ok($res);
}

// ── POST action=narrate_elimination — AI narrative on the worksheet ──────
if ($method === 'POST' && $action === 'narrate_elimination') {
    rbac_legacy_require($user, 'accounting.reports.view');
    rbac_legacy_require($user, 'ai.use');
    require_once __DIR__ . '/../../../core/ai_service.php';
    $body = api_json_body();
    $from = $body['from'] ?? null;
    $to   = $body['to']   ?? null;
    $worksheet = intercompanyEliminationWorksheet($tid, $from, $to);
    try {
        $env = aiAsk([
            'feature_class' => 'narrative',
            'kind'          => 'narrative',
            'feature_key'   => 'accounting.intercompany.elimination_narrative',
            'prompt'        => 'Write a 120-180 word narrative summarising this '
                             . 'intercompany elimination worksheet for a controller '
                             . 'pre-closing the month. Focus on: (1) how many pairs '
                             . 'are out of balance and which entity pair has the '
                             . 'largest imbalance, (2) any orphan IC-tagged lines, '
                             . '(3) a one-line recommendation. Do NOT restate '
                             . 'dollar figures column-by-column — the user has the '
                             . 'table. Close with a verdict: "Ready to eliminate" '
                             . 'or "Fix imbalances before close".',
            'context'       => [
                'summary'          => $worksheet['summary'],
                'imbalanced_pairs' => array_values(array_filter($worksheet['pairs'], fn ($p) => abs($p['imbalance_signed']) > 0.005)),
                'orphan_sample'    => array_slice($worksheet['orphans'], 0, 10),
                'period'           => ['from' => $from, 'to' => $to],
            ],
            'max_output_tokens' => 400,
        ]);
    } catch (\Throwable $e) {
        api_error('AI narrative failed: ' . $e->getMessage(), 500);
    }
    accountingAudit('accounting.intercompany.elimination_narrative_generated', [
        'summary' => $worksheet['summary'],
    ], null);
    api_ok($env);
}

// ─────────────────────────────────────────────────────────────────────────
// Cross-tenant intercompany approval workflow (Batch 3)
// ─────────────────────────────────────────────────────────────────────────
// Distinct from the entity-level split above: these endpoints move money
// across SEPARATE tenant_ids that share the same master parent (e.g.
// Seven Generations posting against Arabella).  Each leg lands on a
// different tenant's books and the counterparty must approve before the
// to-leg posts.  The source leg posts immediately at propose-time and is
// reversed via a compensating JE on decline / expire.

// ── GET ?action=xtenant_inbox — rows awaiting MY approval (I'm the target)
if ($method === 'GET' && $action === 'xtenant_inbox') {
    rbac_legacy_require($user, 'accounting.intercompany.manage');
    $status = (string) ($_GET['status'] ?? 'pending');
    $limit  = (int)    ($_GET['limit']  ?? 100);
    try {
        $rows = accountingListCrossTenantIntercompanyInbox($tid, $status, $limit);
    } catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    api_ok(['status' => $status, 'rows' => $rows]);
}

// ── GET ?action=xtenant_outbox — rows I PROPOSED (I'm the source)
if ($method === 'GET' && $action === 'xtenant_outbox') {
    rbac_legacy_require($user, 'accounting.intercompany.manage');
    $status = (string) ($_GET['status'] ?? 'pending');
    $limit  = (int)    ($_GET['limit']  ?? 100);
    try {
        $rows = accountingListCrossTenantIntercompanyOutbox($tid, $status, $limit);
    } catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    api_ok(['status' => $status, 'rows' => $rows]);
}

// ── POST ?action=xtenant_propose — source admin proposes a new IC entry
if ($method === 'POST' && $action === 'xtenant_propose') {
    rbac_legacy_require($user, 'accounting.je.post');
    $body = api_json_body();
    api_require_fields($body, ['to_tenant_id','amount','memo']);
    $toTenantId = (int) $body['to_tenant_id'];
    $amount     = (float) $body['amount'];
    $memo       = trim((string) $body['memo']);
    if ($toTenantId === $tid) api_error('to_tenant_id must differ from the active tenant', 422);
    $opts = [];
    foreach ([
        'from_account_code','to_account_code','from_offset_code','to_offset_code',
        'from_currency','to_currency','fx_rate','posting_date',
        'intercompany_ref','ttl_days','target_entity_id',
    ] as $k) {
        if (isset($body[$k]) && $body[$k] !== '' && $body[$k] !== null) $opts[$k] = $body[$k];
    }
    try {
        $res = accountingProposeCrossTenantIntercompany($tid, $toTenantId, $amount, $memo, $opts, $uid);
    } catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    api_ok($res, 201);
}

// ── POST ?action=xtenant_approve — counterparty approves; to-leg posts
if ($method === 'POST' && $action === 'xtenant_approve') {
    rbac_legacy_require($user, 'accounting.je.post');
    $body = api_json_body();
    $queueId = (int) ($body['queue_id'] ?? 0);
    if ($queueId <= 0) api_error('queue_id required', 400);
    // Authority gate: the active tenant MUST be the row's target_tenant.
    $pdo = getDB();
    $st  = $pdo->prepare('SELECT target_tenant_id, status FROM intercompany_xtenant_queue WHERE id = :id LIMIT 1');
    $st->execute(['id' => $queueId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$row) api_error('queue row not found', 404);
    if ((int) $row['target_tenant_id'] !== $tid) {
        api_error('Only the counterparty tenant can approve this entry', 403);
    }
    try {
        $res = accountingApproveCrossTenantIntercompany($queueId, $uid);
    } catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    api_ok($res);
}

// ── POST ?action=xtenant_decline — counterparty declines; source-leg reversed
if ($method === 'POST' && $action === 'xtenant_decline') {
    rbac_legacy_require($user, 'accounting.je.post');
    $body = api_json_body();
    $queueId = (int) ($body['queue_id'] ?? 0);
    $reason  = trim((string) ($body['reason'] ?? ''));
    if ($queueId <= 0) api_error('queue_id required', 400);
    if ($reason === '') api_error('reason required', 422);
    $pdo = getDB();
    $st  = $pdo->prepare('SELECT target_tenant_id FROM intercompany_xtenant_queue WHERE id = :id LIMIT 1');
    $st->execute(['id' => $queueId]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$row) api_error('queue row not found', 404);
    if ((int) $row['target_tenant_id'] !== $tid) {
        api_error('Only the counterparty tenant can decline this entry', 403);
    }
    try {
        $res = accountingDeclineCrossTenantIntercompany($queueId, $uid, $reason);
    } catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    api_ok($res);
}

// ── POST ?action=xtenant_expire_sweep — admin-only, also reachable from cron
if ($method === 'POST' && $action === 'xtenant_expire_sweep') {
    if (($user['role'] ?? '') !== 'master_admin'
        && ($user['role'] ?? '') !== 'tenant_admin'
        && (int) ($user['is_global_admin'] ?? 0) !== 1) {
        api_error('Forbidden — admin only', 403);
    }
    try {
        $res = accountingExpireCrossTenantIntercompanyPending($uid);
    } catch (\Throwable $e) { api_error($e->getMessage(), 500); }
    api_ok($res);
}

api_error('Method not allowed', 405);
