<?php
/**
 * /api/admin/rule_proposals.php — Phase 2 AI v1 review queue.
 *
 *   GET    /api/admin/rule_proposals.php
 *          [?status=proposed|competed|accepted|rejected|error]
 *          [?rule_type=ap_expense_category_map]
 *          [?limit=50]
 *     → { rows: [...] }
 *
 *   GET    /api/admin/rule_proposals.php?id=N
 *     → single row with full comparison_json
 *
 *   POST { action: "propose",  rule_type }              → { id, ...row }
 *   POST { action: "compete",  id, sample_size? }       → { id, ...row }
 *   POST { action: "review",   id, decision, notes? }   → { id, ...row }
 *           decision ∈ "accept" | "reject"
 *
 * Auth: standard session/JWT + RBAC tier "ai_admin" preferred, but for v1
 * any authenticated user can drive this (we'll wire RBAC in Phase 2.1).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/ai_rule_proposer.php';
require_once __DIR__ . '/../../core/ai_rule_competition.php';

$ctx      = api_require_auth();
$tenantId = (int) ($ctx['tenant_id'] ?? 0);
$userId   = (int) ($ctx['user']['id'] ?? 0) ?: null;
if (!$tenantId) api_error('No active tenant', 400);

$pdo    = getDB();
$method = api_method();

function rp_row_decode(array $r): array {
    foreach (['current_rule_json', 'proposed_rule_json', 'comparison_json'] as $k) {
        if (isset($r[$k]) && is_string($r[$k])) {
            $decoded = json_decode($r[$k], true);
            $r[$k]   = is_array($decoded) ? $decoded : null;
        }
    }
    foreach (['score', 'dollars_changed'] as $k) {
        if (isset($r[$k]) && $r[$k] !== null) $r[$k] = (float) $r[$k];
    }
    foreach (['events_compared', 'events_changed'] as $k) {
        if (isset($r[$k]) && $r[$k] !== null) $r[$k] = (int) $r[$k];
    }
    return $r;
}

if ($method === 'GET') {
    $id = (int) api_query('id', 0);
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM rule_proposals WHERE tenant_id = :t AND id = :id');
        $stmt->execute(['t' => $tenantId, 'id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) api_error('Not found', 404);
        api_ok(['row' => rp_row_decode($row)]);
    }

    $where  = ['tenant_id = :t'];
    $params = ['t' => $tenantId];
    if ($s = api_query('status', null))    { $where[] = 'status = :s';    $params['s']  = (string) $s; }
    if ($rt = api_query('rule_type', null)){ $where[] = 'rule_type = :rt'; $params['rt'] = (string) $rt; }
    $limit = max(1, min(200, (int) api_query('limit', 50)));

    $sql  = 'SELECT * FROM rule_proposals WHERE ' . implode(' AND ', $where)
          . ' ORDER BY created_at DESC LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = array_map('rp_row_decode', $stmt->fetchAll(\PDO::FETCH_ASSOC));
    api_ok(['rows' => $rows, 'count' => count($rows)]);
}

if ($method !== 'POST') api_error('Method not allowed', 405);

$body   = api_json_body();
$action = (string) ($body['action'] ?? '');

if ($action === 'propose') {
    $ruleType = (string) ($body['rule_type'] ?? '');
    if ($ruleType === '') api_error('rule_type required', 422);
    try {
        $id = aiProposeRule($tenantId, $ruleType, $userId, (int) ($body['context_size'] ?? 30));
        $stmt = $pdo->prepare('SELECT * FROM rule_proposals WHERE id = :id');
        $stmt->execute(['id' => $id]);
        api_ok(['id' => $id, 'row' => rp_row_decode($stmt->fetch(\PDO::FETCH_ASSOC) ?: [])]);
    } catch (\Throwable $e) {
        api_error('propose failed: ' . $e->getMessage(), 500);
    }
}

if ($action === 'compete') {
    $id         = (int) ($body['id'] ?? 0);
    $sampleSize = (int) ($body['sample_size'] ?? 50);
    if (!$id) api_error('id required', 422);
    // Tenant ownership check before delegating.
    $stmt = $pdo->prepare('SELECT id FROM rule_proposals WHERE tenant_id = :t AND id = :id');
    $stmt->execute(['t' => $tenantId, 'id' => $id]);
    if (!$stmt->fetchColumn()) api_error('Not found', 404);
    try {
        $row = aiRuleCompete($id, $sampleSize);
        api_ok(['id' => $id, 'row' => rp_row_decode($row)]);
    } catch (\Throwable $e) {
        api_error('compete failed: ' . $e->getMessage(), 500);
    }
}

if ($action === 'review') {
    $id       = (int) ($body['id'] ?? 0);
    $decision = (string) ($body['decision'] ?? '');
    $notes    = (string) ($body['notes'] ?? '');
    if (!$id) api_error('id required', 422);
    if (!in_array($decision, ['accept', 'reject'], true)) {
        api_error('decision must be "accept" or "reject"', 422);
    }
    $stmt = $pdo->prepare('SELECT id FROM rule_proposals WHERE tenant_id = :t AND id = :id');
    $stmt->execute(['t' => $tenantId, 'id' => $id]);
    if (!$stmt->fetchColumn()) api_error('Not found', 404);

    $newStatus = $decision === 'accept' ? 'accepted' : 'rejected';
    $pdo->prepare(
        'UPDATE rule_proposals
            SET status = :s, reviewed_by_user_id = :u, reviewed_at = NOW(),
                review_notes = :n, updated_at = NOW()
          WHERE id = :id'
    )->execute(['s' => $newStatus, 'u' => $userId, 'n' => $notes ?: null, 'id' => $id]);

    $stmt = $pdo->prepare('SELECT * FROM rule_proposals WHERE id = :id');
    $stmt->execute(['id' => $id]);
    api_ok(['id' => $id, 'row' => rp_row_decode($stmt->fetch(\PDO::FETCH_ASSOC) ?: [])]);
}

api_error('Unknown action. Use "propose" | "compete" | "review".', 422);
