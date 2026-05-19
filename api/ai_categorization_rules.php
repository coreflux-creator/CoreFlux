<?php
/**
 * /api/ai_categorization_rules.php — saved rules UI backend.
 *
 *   GET    /api/ai_categorization_rules.php
 *          → list every learned (merchant → account) mapping with accept /
 *            reject counts. Auto-apply-eligible rows are flagged.
 *
 *   PATCH  /api/ai_categorization_rules.php?id=N
 *          body: { disabled: true|false, disabled_reason?: string }
 *          → soft-mute / unmute a rule. Disabled rules are skipped by
 *            aiCategorizationFromHistory().
 *
 *   DELETE /api/ai_categorization_rules.php?id=N
 *          → permanently delete a rule (forget the history). Used when a
 *            merchant was misclassified so badly it shouldn't influence
 *            future suggestions at all.
 *
 * Permission: `accounting.je.create` (same as the categorize action that
 * created these rows).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/ai_categorization.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($ctx['user'], 'accounting.je.create');
$pdo    = getDB();
$method = api_method();

if ($method === 'GET') {
    // Hydrate with the GL account name so the UI can show "Stripe → 7000 SaaS Subscriptions".
    $rows = scopedQuery(
        "SELECT h.id, h.feature_key, h.signal_kind, h.signal_value, h.final_value,
                h.accept_count, h.reject_count, h.last_accepted_at, h.last_rejected_at,
                h.disabled_at, h.disabled_reason, h.created_at,
                aa.id   AS account_id,
                aa.code AS account_code,
                aa.name AS account_name,
                aa.account_type
           FROM ai_categorization_history h
           LEFT JOIN accounting_accounts aa
             ON aa.tenant_id = h.tenant_id
            AND CONCAT('account_id:', aa.id) = h.final_value
          WHERE h.tenant_id = :tenant_id
          ORDER BY (h.disabled_at IS NULL) DESC,
                   h.accept_count DESC,
                   h.last_accepted_at DESC"
    );
    // Decorate each row with status flags the UI uses to render badges.
    foreach ($rows as &$r) {
        $accept = (int) $r['accept_count'];
        $reject = (int) ($r['reject_count'] ?? 0);
        $score  = $accept - $reject;
        $r['effective_score']      = $score;
        $r['auto_apply_eligible']  = ($score >= 3 && empty($r['disabled_at']));
        $r['weak']                 = ($score >= 1 && $score < 3 && empty($r['disabled_at']));
        $r['contested']            = ($reject > 0);
        $r['is_disabled']          = !empty($r['disabled_at']);
        // signal_value is normalized lowercase in storage — title-case for display.
        $r['display_label']        = $r['signal_value']
            ? ucwords(str_replace(['_', '-'], ' ', (string) $r['signal_value']))
            : '(blank)';
    }
    api_ok([
        'rows'              => $rows,
        'count'             => count($rows),
        'auto_apply_count'  => count(array_filter($rows, fn ($r) => $r['auto_apply_eligible'])),
        'disabled_count'    => count(array_filter($rows, fn ($r) => $r['is_disabled'])),
    ]);
}

if ($method === 'PATCH' || $method === 'POST') {
    $id   = (int) ($_GET['id'] ?? 0);
    $body = api_json_body();
    if ($id <= 0) api_error('id required', 400);

    $row = scopedFind(
        'SELECT id, signal_value FROM ai_categorization_history
          WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$row) api_error('Rule not found', 404);

    $disabled = !empty($body['disabled']);
    $reason   = isset($body['disabled_reason']) ? trim((string) $body['disabled_reason']) : null;

    $stmt = $pdo->prepare(
        'UPDATE ai_categorization_history
            SET disabled_at      = :da,
                disabled_reason  = :dr,
                disabled_by_user = :du
          WHERE tenant_id = :t AND id = :id'
    );
    $stmt->execute([
        't'  => $tenantId, 'id' => $id,
        'da' => $disabled ? date('Y-m-d H:i:s') : null,
        'dr' => $disabled ? $reason : null,
        'du' => $disabled ? (int) ($ctx['user']['id'] ?? 0) : null,
    ]);
    api_ok([
        'ok'           => true,
        'is_disabled'  => $disabled,
        'signal_value' => $row['signal_value'],
    ]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $del = $pdo->prepare(
        'DELETE FROM ai_categorization_history
          WHERE tenant_id = :t AND id = :id'
    );
    $del->execute(['t' => $tenantId, 'id' => $id]);
    api_ok(['ok' => true, 'deleted' => $del->rowCount()]);
}

api_error('Method not allowed', 405);
