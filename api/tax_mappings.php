<?php
/**
 * Tax-mappings admin endpoint (Sprint 7f.1).
 *
 *   GET  /api/tax_mappings.php?tax_form_code=US-1040-SCH-C
 *        → { tax_form_code, mappings: [{id, account_id, code, name, account_type, line, label, notes}],
 *            unmapped_accounts: [{id, code, name, account_type}], available_forms: [...] }
 *
 *   POST /api/tax_mappings.php          (create/update via upsert)
 *        body: { account_id, tax_form_code, tax_form_line, tax_form_label?, notes? }
 *        → { ok, id }
 *
 *   DELETE /api/tax_mappings.php?id=N  → { ok }
 *
 * Standard form catalogue (`available_forms`) is hard-coded for now.
 *
 * RBAC: GET = `accounting.coa.view`; POST/DELETE = `accounting.je.create`.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

const TAX_FORMS = [
    'US-1040-SCH-C' => 'US — Form 1040 Schedule C (Sole proprietor)',
    'US-1120'       => 'US — Form 1120 (C-corp)',
    'US-1120-S'     => 'US — Form 1120-S (S-corp)',
    'US-1065'       => 'US — Form 1065 (Partnership)',
    'US-990'        => 'US — Form 990 (Non-profit)',
];

$pdo    = getDB();
$method = api_method();

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.coa.view');

    $form = trim((string) (api_query('tax_form_code') ?? ''));
    $availableForms = [];
    foreach (TAX_FORMS as $c => $l) $availableForms[] = ['code' => $c, 'label' => $l];

    if ($form === '') {
        api_ok([
            'tax_form_code'      => null,
            'mappings'           => [],
            'unmapped_accounts'  => [],
            'available_forms'    => $availableForms,
        ]);
    }
    if (!isset(TAX_FORMS[$form])) api_error('Unknown tax_form_code', 422);

    $mapStmt = $pdo->prepare(
        "SELECT m.id, m.account_id, m.tax_form_line, m.tax_form_label, m.notes,
                a.code, a.name, a.account_type
           FROM accounting_tax_mappings m
           JOIN accounting_accounts a ON a.id = m.account_id AND a.tenant_id = m.tenant_id
          WHERE m.tenant_id = :t AND m.tax_form_code = :f
          ORDER BY a.code ASC"
    );
    $mapStmt->execute(['t' => $tid, 'f' => $form]);
    $mappings = $mapStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $mappedIds = array_map(static fn($m) => (int) $m['account_id'], $mappings);
    $placeholders = $mappedIds ? '(' . implode(',', array_fill(0, count($mappedIds), '?')) . ')' : '(0)';

    // Only postable expense + revenue accounts (the ones the tax form
    // actually wants reported).
    $params = array_merge([$tid], $mappedIds);
    $unmappedSql = "SELECT id, code, name, account_type
                      FROM accounting_accounts
                     WHERE tenant_id = ?
                       AND active = 1 AND is_postable = 1
                       AND account_type IN ('revenue','expense','cost_of_goods_sold','other_income','other_expense','contra_revenue')
                       AND id NOT IN {$placeholders}
                  ORDER BY code ASC";
    $uStmt = $pdo->prepare($unmappedSql);
    $uStmt->execute($params);
    $unmapped = $uStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $rows = array_map(static fn($m) => [
        'id'             => (int) $m['id'],
        'account_id'     => (int) $m['account_id'],
        'code'           => (string) $m['code'],
        'name'           => (string) $m['name'],
        'account_type'   => (string) $m['account_type'],
        'tax_form_line'  => (string) $m['tax_form_line'],
        'tax_form_label' => $m['tax_form_label'] !== null ? (string) $m['tax_form_label'] : null,
        'notes'          => $m['notes'] !== null ? (string) $m['notes'] : null,
    ], $mappings);

    $unmappedRows = array_map(static fn($r) => [
        'id'           => (int) $r['id'],
        'code'         => (string) $r['code'],
        'name'         => (string) $r['name'],
        'account_type' => (string) $r['account_type'],
    ], $unmapped);

    api_ok([
        'tax_form_code'     => $form,
        'tax_form_label'    => TAX_FORMS[$form],
        'mappings'          => $rows,
        'mapped_count'      => count($rows),
        'unmapped_accounts' => $unmappedRows,
        'unmapped_count'    => count($unmappedRows),
        'available_forms'   => $availableForms,
    ]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'accounting.je.create');
    $body = api_json_body();
    $accountId = (int) ($body['account_id']    ?? 0);
    $form      = trim((string) ($body['tax_form_code'] ?? ''));
    $line      = trim((string) ($body['tax_form_line'] ?? ''));
    $label     = isset($body['tax_form_label']) ? (string) $body['tax_form_label'] : null;
    $notes     = isset($body['notes'])         ? (string) $body['notes']           : null;

    if ($accountId <= 0)        api_error('account_id required', 422);
    if (!isset(TAX_FORMS[$form])) api_error('Unknown tax_form_code', 422);
    if ($line === '')           api_error('tax_form_line required', 422);
    if (mb_strlen($line) > 32)  api_error('tax_form_line too long', 422);

    // Sanity-check account belongs to tenant.
    $aChk = $pdo->prepare('SELECT 1 FROM accounting_accounts WHERE tenant_id = :t AND id = :id');
    $aChk->execute(['t' => $tid, 'id' => $accountId]);
    if (!$aChk->fetchColumn()) api_error('Account not found in this tenant', 404);

    $up = $pdo->prepare(
        'INSERT INTO accounting_tax_mappings
            (tenant_id, account_id, tax_form_code, tax_form_line, tax_form_label, notes,
             created_by_user_id, updated_by_user_id)
         VALUES (:t, :a, :f, :ln, :lb, :n, :u, :u)
         ON DUPLICATE KEY UPDATE
             tax_form_line = VALUES(tax_form_line),
             tax_form_label = VALUES(tax_form_label),
             notes = VALUES(notes),
             updated_by_user_id = VALUES(updated_by_user_id)'
    );
    $up->execute([
        't' => $tid, 'a' => $accountId, 'f' => $form,
        'ln' => $line, 'lb' => $label, 'n' => $notes,
        'u' => $user['id'] ?? null,
    ]);

    $idStmt = $pdo->prepare(
        'SELECT id FROM accounting_tax_mappings
          WHERE tenant_id = :t AND account_id = :a AND tax_form_code = :f LIMIT 1'
    );
    $idStmt->execute(['t' => $tid, 'a' => $accountId, 'f' => $form]);
    api_ok(['ok' => true, 'id' => (int) $idStmt->fetchColumn()]);
}

if ($method === 'DELETE') {
    rbac_legacy_require($user, 'accounting.je.create');
    $id = (int) (api_query('id') ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $del = $pdo->prepare('DELETE FROM accounting_tax_mappings WHERE tenant_id = :t AND id = :id');
    $del->execute(['t' => $tid, 'id' => $id]);
    api_ok(['ok' => true, 'deleted' => $del->rowCount()]);
}

api_error('Method not allowed', 405);
