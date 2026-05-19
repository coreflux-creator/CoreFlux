<?php
/**
 * Accounting API — Bank rec rules.
 *
 *   GET  /api/accounting/bank_rules[?bank_account_id=N]
 *   POST /api/accounting/bank_rules                 → create rule
 *   PUT  /api/accounting/bank_rules?id=N            → edit rule
 *   POST /api/accounting/bank_rules?action=approve&id=N    → flip is_approved=1 (auto-apply on next match)
 *   POST /api/accounting/bank_rules?action=pause&id=N
 *   POST /api/accounting/bank_rules?action=archive&id=N
 *
 * is_approved semantics:
 *   - 0 (default): rule fires as a SUGGESTION on bank-line import. The
 *     reviewer must accept through <AISuggestion /> before anything posts.
 *   - 1: rule auto-applies — bank lines that match are auto-categorized
 *     and a JE is staged (or posted) without human review. Use sparingly.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.coa.view');
    $where  = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['bank_account_id'])) {
        $where[] = '(bank_account_id IS NULL OR bank_account_id = :b)';
        $params['b'] = (int) $_GET['bank_account_id'];
    }
    $rows = scopedQuery(
        'SELECT * FROM accounting_bank_rules WHERE ' . implode(' AND ', $where) . '
         ORDER BY status, is_approved DESC, name',
        $params
    );
    api_ok(['rows' => $rows]);
}

if ($method === 'POST' && $action === 'learn') {
    // Walk recent accepted AI categorizations, find common substrings per
    // (target_account_code) cluster, draft new rules with is_approved=0.
    rbac_legacy_require($user, 'accounting.coa.edit');
    require_once __DIR__ . '/../lib/bank_rec.php';
    $minOccurrences = max(2, (int) ($_GET['min_occurrences'] ?? 3));
    $res = bankRecLearnRulesFromAccepts((int) $ctx['tenant_id'], $minOccurrences, $user['id'] ?? null);
    accountingAudit('accounting.bank.rules_learned',
        ['drafted' => $res['drafted'], 'min_occurrences' => $minOccurrences]);
    api_ok($res);
}

if ($method === 'POST' && in_array($action, ['approve','pause','archive'], true)) {
    rbac_legacy_require($user, 'accounting.coa.edit');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $update = match ($action) {
        'approve' => ['is_approved' => 1, 'status' => 'active'],
        'pause'   => ['status' => 'paused'],
        'archive' => ['status' => 'archived'],
    };
    scopedUpdate('accounting_bank_rules', $id, $update);
    accountingAudit('accounting.bank.rule_' . $action, [], $id);
    api_ok(['ok' => true, 'status' => $update]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'accounting.coa.edit');
    $body = api_json_body();
    api_require_fields($body, ['name','pattern','target_account_code']);
    $kind = (string) ($body['pattern_kind'] ?? 'contains');
    if (!in_array($kind, ['contains','starts_with','equals','regex'], true)) {
        api_error('Invalid pattern_kind', 422);
    }
    if ($kind === 'regex') {
        // Validate the regex compiles
        if (@preg_match('/' . str_replace('/', '\/', (string) $body['pattern']) . '/', '') === false) {
            api_error('Regex pattern does not compile', 422);
        }
    }
    $id = scopedInsert('accounting_bank_rules', [
        'bank_account_id'        => isset($body['bank_account_id']) ? (int) $body['bank_account_id'] : null,
        'name'                   => (string) $body['name'],
        'pattern_kind'           => $kind,
        'pattern'                => (string) $body['pattern'],
        'amount_min_cents'       => isset($body['amount_min_cents']) ? (int) $body['amount_min_cents'] : null,
        'amount_max_cents'       => isset($body['amount_max_cents']) ? (int) $body['amount_max_cents'] : null,
        'direction'              => $body['direction'] ?? 'any',
        'target_account_code'    => (string) $body['target_account_code'],
        'target_offset_account'  => $body['target_offset_account'] ?? null,
        'is_approved'            => !empty($body['is_approved']) ? 1 : 0,
        'created_via'            => $body['created_via']      ?? 'manual',
        'ai_interaction_id'      => isset($body['ai_interaction_id']) ? (int) $body['ai_interaction_id'] : null,
        'created_by_user_id'     => $user['id'] ?? null,
    ]);
    accountingAudit('accounting.bank.rule_created', [
        'name' => $body['name'], 'is_approved' => !empty($body['is_approved']),
        'created_via' => $body['created_via'] ?? 'manual',
    ], $id);
    api_ok(['id' => $id], 201);
}

if ($method === 'PUT') {
    rbac_legacy_require($user, 'accounting.coa.edit');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    $allowed = ['name','pattern_kind','pattern','amount_min_cents','amount_max_cents','direction',
                'target_account_code','target_offset_account','is_approved','status'];
    $data = [];
    foreach ($allowed as $f) if (array_key_exists($f, $body)) $data[$f] = $body[$f];
    if ($data) scopedUpdate('accounting_bank_rules', $id, $data);
    accountingAudit('accounting.bank.rule_updated', ['fields' => array_keys($data)], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
