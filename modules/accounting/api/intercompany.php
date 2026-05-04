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

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$uid    = (int) ($user['id'] ?? 0) ?: null;
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

// ── GET: list mappings ───────────────────────────────────────────────────
if ($method === 'GET' && $action === '') {
    RBAC::requirePermission($user, 'accounting.intercompany.manage');
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
    RBAC::requirePermission($user, 'accounting.je.create');
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
    RBAC::requirePermission($user, 'accounting.intercompany.manage');
    $body = api_json_body();
    api_require_fields($body, ['from_entity_id','to_entity_id','due_from_account_code','due_to_account_code']);
    try {
        $id = intercompanyUpsertMapping($tid, $body);
    } catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    api_ok(['id' => $id], 201);
}

// ── DELETE: deactivate mapping ───────────────────────────────────────────
if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'accounting.intercompany.manage');
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
    RBAC::requirePermission($user, 'accounting.je.post');
    try {
        $res = intercompanyPostSplit($tid, $body, $uid);
    } catch (\Throwable $e) { api_error($e->getMessage(), 422); }
    api_ok($res, 201);
}

// ── POST: reverse group ──────────────────────────────────────────────────
if ($method === 'POST' && $action === 'reverse_group') {    RBAC::requirePermission($user, 'accounting.je.reverse');
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
    RBAC::requirePermission($user, 'accounting.reports.view');
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
    RBAC::requirePermission($user, 'accounting.reports.view');
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

api_error('Method not allowed', 405);