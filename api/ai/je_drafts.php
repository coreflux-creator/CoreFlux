<?php
/**
 * /api/ai/je_drafts.php — AI-drafted journal entries reviewer surface
 * (Slice C / Spec §11).
 *
 *   GET                          — list draft JEs (newest first)
 *   GET  ?action=detail&id=N     — single draft JE with lines + revalidate
 *                                  report
 *   POST ?action=reject          — body {id, reason?} → status='void'
 *
 * Approval + post is NOT done here — that requires a workflow_approval
 * row and goes through the coreflux.post_approved_journal_entry tool
 * (risk_level=4). The reviewer either approves via the workflow
 * runtime or rejects here.
 *
 * RBAC: `ai.audit.view` OR `accounting.review` for list + detail.
 *       `accounting.approve` for reject.
 *
 * Every mutation writes a spec-§15 audit_log event:
 *   ai_je_draft_rejected.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../core/ai/gateway.php';
require_once __DIR__ . '/../../modules/accounting/lib/accounting.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$uid    = (int) ($user['id'] ?? 0) ?: null;
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

$canView = rbac_legacy_can($user, 'ai.audit.view') || rbac_legacy_can($user, 'accounting.review');

if ($method === 'GET' && $action === '') {
    if (!$canView) api_error('Forbidden', 403);
    $stmt = getDB()->prepare(
        "SELECT je.id, je.je_number, je.entity_id, je.posting_date,
                je.currency, je.status, je.total_debit, je.total_credit,
                je.memo, je.source_module, je.source_ref_type, je.source_ref_id,
                je.created_by_user_id, je.created_at,
                (SELECT COUNT(*) FROM accounting_journal_entry_lines l WHERE l.je_id = je.id) AS line_count
           FROM accounting_journal_entries je
          WHERE je.tenant_id = :t AND je.status = 'draft'
          ORDER BY je.id DESC LIMIT 200"
    );
    $stmt->execute(['t' => $tid]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']                 = (int)   $r['id'];
        $r['entity_id']          = (int)   $r['entity_id'];
        $r['source_ref_id']      = $r['source_ref_id']      !== null ? (int) $r['source_ref_id']      : null;
        $r['created_by_user_id'] = $r['created_by_user_id'] !== null ? (int) $r['created_by_user_id'] : null;
        $r['total_debit']        = (float) $r['total_debit'];
        $r['total_credit']       = (float) $r['total_credit'];
        $r['line_count']         = (int)   $r['line_count'];
    } unset($r);
    api_ok(['drafts' => $rows, 'count' => count($rows)]);
}

if ($method === 'GET' && $action === 'detail') {
    if (!$canView) api_error('Forbidden', 403);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);

    $jeStmt = getDB()->prepare(
        'SELECT id, tenant_id, entity_id, period_id, je_number,
                posting_date, currency, status, total_debit, total_credit,
                memo, source_module, source_ref_type, source_ref_id,
                created_by_user_id, created_at
           FROM accounting_journal_entries
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $jeStmt->execute(['id' => $id, 't' => $tid]);
    $je = $jeStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$je) api_error('draft JE not found', 404);
    if ($je['status'] !== 'draft') {
        api_error("JE #{$id} is status='{$je['status']}', not 'draft'", 422);
    }
    foreach (['id', 'tenant_id', 'entity_id', 'period_id', 'source_ref_id', 'created_by_user_id'] as $k) {
        if ($je[$k] !== null) $je[$k] = (int) $je[$k];
    }
    $je['total_debit']  = (float) $je['total_debit'];
    $je['total_credit'] = (float) $je['total_credit'];

    // tenant-leak-allow: parent JE was fetched tenant-scoped above; lines join by je_id
    $linesStmt = getDB()->prepare(
        'SELECT l.line_no, l.account_id, a.code AS account_code, a.name AS account_name,
                l.debit, l.credit, l.memo, l.dim_json
           FROM accounting_journal_entry_lines l
           JOIN accounting_accounts a ON a.id = l.account_id
          WHERE l.je_id = :je
          ORDER BY l.line_no ASC'
    );
    $linesStmt->execute(['je' => $id]);
    $lines = $linesStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($lines as &$ln) {
        $ln['line_no']    = (int)   $ln['line_no'];
        $ln['account_id'] = (int)   $ln['account_id'];
        $ln['debit']      = (float) $ln['debit'];
        $ln['credit']     = (float) $ln['credit'];
        $ln['dims']       = $ln['dim_json'] ? (json_decode((string) $ln['dim_json'], true) ?: []) : [];
        unset($ln['dim_json']);
    } unset($ln);

    // Re-validate the draft so the reviewer sees fresh check results
    // (closed periods / deactivated accounts caught here).
    $report = accountingValidateJe($tid, [
        'entity_id'    => $je['entity_id'],
        'posting_date' => $je['posting_date'],
        'currency'     => $je['currency'],
        'lines'        => array_map(fn ($ln) => [
            'account_id' => $ln['account_id'],
            'debit'      => $ln['debit'],
            'credit'     => $ln['credit'],
            'memo'       => $ln['memo'],
            'dims'       => $ln['dims'],
        ], $lines),
    ]);

    api_ok(['draft' => $je, 'lines' => $lines, 'validation' => $report]);
}

if ($method === 'POST' && $action === 'reject') {
    if (!rbac_legacy_can($user, 'accounting.approve')) {
        api_error('Forbidden', 403);
    }
    $body = api_json_body();
    $id   = (int) ($body['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $reason = isset($body['reason']) ? mb_substr((string) $body['reason'], 0, 500) : null;

    $stmt = getDB()->prepare(
        'SELECT id, status FROM accounting_journal_entries
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $id, 't' => $tid]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) api_error('draft JE not found', 404);
    if ($row['status'] !== 'draft') {
        api_error("cannot reject JE in status '{$row['status']}'", 422);
    }

    // Flip to 'void' — keeps the row + lines for audit but disqualifies
    // it from the reviewer queue and the post tool.
    getDB()->prepare(
        'UPDATE accounting_journal_entries
            SET status = "void"
          WHERE id = :id AND tenant_id = :t AND status = "draft"'
    )->execute(['id' => $id, 't' => $tid]);

    aiGatewayAuditEvent($tid, $uid, 'ai_je_draft_rejected', [
        'je_id'  => $id,
        'reason' => $reason,
    ]);

    api_ok(['je_id' => $id, 'status' => 'void', 'reason' => $reason]);
}

api_error("unknown action '{$action}' or wrong HTTP method", 400);
