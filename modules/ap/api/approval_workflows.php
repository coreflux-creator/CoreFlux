<?php
/**
 * AP — Approval Workflows API.
 *
 *   GET  /api/ap/approval_workflows              — list workflows + rules
 *   POST /api/ap/approval_workflows              — create workflow {name, is_default?, rules: [...]}
 *   PATCH ?id=N                                  — update workflow + replace rules in one call
 *   DELETE ?id=N                                 — soft-delete (is_active=0)
 *
 * Each workflow has N rules, where each rule is:
 *   { step_no:int, min_amount, max_amount?, approver_user_id }
 *
 * Permission: `ap.bills.approve_admin` for all writes; `ap.bills.view`
 * for the GET so AP clerks can see who'll sign off on what.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';

$ctx      = api_require_auth();
$tenantId = (int) $ctx['tenant_id'];
$user     = $ctx['user'];
$pdo      = getDB();

$method   = api_method();

if ($method === 'GET') {
    RBAC::requirePermission($user, 'ap.view');
    $stmt = $pdo->prepare(
        'SELECT id, name, is_active, is_default, created_at
           FROM ap_approval_workflows
          WHERE tenant_id = :t
          ORDER BY is_default DESC, name ASC'
    );
    $stmt->execute(['t' => $tenantId]);
    $workflows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ids = array_map(fn($w) => (int) $w['id'], $workflows);
    $rulesByWf = [];
    if ($ids) {
        $place = implode(',', array_fill(0, count($ids), '?'));
        $r = $pdo->prepare(
            "SELECT awr.id, awr.workflow_id, awr.step_no, awr.min_amount,
                    awr.max_amount, awr.approver_user_id, u.email AS approver_email,
                    u.name AS approver_name
               FROM ap_approval_workflow_rules awr
               LEFT JOIN users u ON u.id = awr.approver_user_id
              WHERE awr.tenant_id = ? AND awr.workflow_id IN ({$place})
              ORDER BY awr.workflow_id, awr.step_no, awr.min_amount"
        );
        $r->execute(array_merge([$tenantId], $ids));
        foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            $rulesByWf[(int) $rule['workflow_id']][] = $rule;
        }
    }
    foreach ($workflows as &$w) {
        $w['rules'] = $rulesByWf[(int) $w['id']] ?? [];
    }
    api_ok(['rows' => $workflows]);
}

if ($method === 'POST' || $method === 'PATCH') {
    RBAC::requirePermission($user, 'ap.bills.approve_admin');
    $body  = api_json_body();
    $name  = trim((string) ($body['name'] ?? ''));
    if ($method === 'POST' && $name === '') api_error('name required', 422);
    $isActive  = (int) (!isset($body['is_active'])  || $body['is_active']);
    $isDefault = (int) ($body['is_default'] ?? 0);
    $rules     = is_array($body['rules'] ?? null) ? $body['rules'] : [];

    foreach ($rules as $r) {
        if (!isset($r['approver_user_id']) || (int) $r['approver_user_id'] <= 0) {
            api_error('Each rule needs approver_user_id', 422);
        }
    }

    $pdo->beginTransaction();
    try {
        if ($isDefault) {
            $pdo->prepare('UPDATE ap_approval_workflows SET is_default = 0 WHERE tenant_id = :t')
                ->execute(['t' => $tenantId]);
        }
        if ($method === 'POST') {
            $pdo->prepare(
                'INSERT INTO ap_approval_workflows (tenant_id, name, is_active, is_default)
                 VALUES (:t, :n, :a, :d)'
            )->execute(['t' => $tenantId, 'n' => $name, 'a' => $isActive, 'd' => $isDefault]);
            $wfId = (int) $pdo->lastInsertId();
        } else {
            $wfId = (int) ($_GET['id'] ?? 0);
            if ($wfId <= 0) api_error('id required', 422);
            $check = $pdo->prepare('SELECT 1 FROM ap_approval_workflows WHERE tenant_id = :t AND id = :id');
            $check->execute(['t' => $tenantId, 'id' => $wfId]);
            if (!$check->fetchColumn()) api_error('Workflow not found', 404);
            $pdo->prepare(
                'UPDATE ap_approval_workflows
                    SET name = COALESCE(:n, name),
                        is_active  = :a,
                        is_default = :d
                  WHERE tenant_id = :t AND id = :id'
            )->execute([
                'n'  => $name !== '' ? $name : null,
                'a'  => $isActive, 'd' => $isDefault,
                't'  => $tenantId, 'id' => $wfId,
            ]);
        }

        // Replace rules wholesale on every save — workflows are tiny and
        // user expectation is "this list IS the rules". Avoids drift.
        $pdo->prepare('DELETE FROM ap_approval_workflow_rules WHERE tenant_id = :t AND workflow_id = :w')
            ->execute(['t' => $tenantId, 'w' => $wfId]);
        $ins = $pdo->prepare(
            'INSERT INTO ap_approval_workflow_rules
                (tenant_id, workflow_id, step_no, min_amount, max_amount, approver_user_id)
             VALUES (:t, :w, :s, :mn, :mx, :a)'
        );
        foreach ($rules as $r) {
            $ins->execute([
                't'  => $tenantId, 'w' => $wfId,
                's'  => max(1, (int) ($r['step_no']    ?? 1)),
                'mn' => round((float) ($r['min_amount']  ?? 0), 2),
                'mx' => isset($r['max_amount']) && $r['max_amount'] !== '' && $r['max_amount'] !== null
                          ? round((float) $r['max_amount'], 2) : null,
                'a'  => (int) $r['approver_user_id'],
            ]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        api_error('Could not save workflow: ' . $e->getMessage(), 500);
    }

    api_ok(['ok' => true, 'id' => $wfId]);
}

if ($method === 'DELETE') {
    RBAC::requirePermission($user, 'ap.bill.approve');
    $wfId = (int) ($_GET['id'] ?? 0);
    if ($wfId <= 0) api_error('id required', 422);
    $pdo->prepare(
        'UPDATE ap_approval_workflows SET is_active = 0, is_default = 0
          WHERE tenant_id = :t AND id = :id'
    )->execute(['t' => $tenantId, 'id' => $wfId]);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
