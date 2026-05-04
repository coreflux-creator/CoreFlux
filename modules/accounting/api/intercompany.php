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
if ($method === 'POST' && $action === 'reverse_group') {
    RBAC::requirePermission($user, 'accounting.je.reverse');
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

api_error('Method not allowed', 405);
