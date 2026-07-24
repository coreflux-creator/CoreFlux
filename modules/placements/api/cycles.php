<?php
/** Purpose-specific staffing operating cycles and placement assignments. */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/placements.php';
require_once __DIR__ . '/../lib/economics.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

function placementCycleCreatePayrollBridge(int $tenantId, string $name, string $cadence, string $anchor, int $offset): int
{
    if ($cadence === 'adhoc') throw new \InvalidArgumentException('Payroll cycles cannot use ad-hoc cadence');
    $pdo = getDB();
    $scheduleName = $name . ' schedule';
    $st = $pdo->prepare(
        'SELECT id FROM payroll_pay_schedules WHERE tenant_id = :t AND name = :n ORDER BY id LIMIT 1'
    );
    $st->execute(['t' => $tenantId, 'n' => $scheduleName]);
    $scheduleId = (int) $st->fetchColumn();
    if (!$scheduleId) {
        $pdo->prepare(
            'INSERT INTO payroll_pay_schedules
                (tenant_id, name, frequency, period_start_anchor, pay_date_offset_days, timezone, active, notes)
             VALUES (:t, :n, :f, :a, :o, "America/New_York", 1, "Created from staffing operating cycle")'
        )->execute(['t' => $tenantId, 'n' => $scheduleName, 'f' => $cadence, 'a' => $anchor, 'o' => $offset]);
        $scheduleId = (int) $pdo->lastInsertId();
    }
    $st = $pdo->prepare(
        'SELECT id FROM payroll_pay_cycles WHERE tenant_id = :t AND name = :n ORDER BY id LIMIT 1'
    );
    $st->execute(['t' => $tenantId, 'n' => $name]);
    $cycleId = (int) $st->fetchColumn();
    if (!$cycleId) {
        $pdo->prepare(
            'INSERT INTO payroll_pay_cycles
                (tenant_id, name, schedule_id, anchor_date_override, pay_date_offset_days_override, active, notes)
             VALUES (:t, :n, :s, :a, :o, 1, "Managed by staffing operating cycle")'
        )->execute(['t' => $tenantId, 'n' => $name, 's' => $scheduleId, 'a' => $anchor, 'o' => $offset]);
        $cycleId = (int) $pdo->lastInsertId();
    }
    return $cycleId;
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'placements.view');
    $where = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['purpose'])) {
        $where[] = 'purpose = :purpose';
        $params['purpose'] = (string) $_GET['purpose'];
    }
    if (empty($_GET['include_inactive'])) $where[] = 'active = 1';
    $rows = scopedQuery(
        'SELECT * FROM staffing_operating_cycles WHERE ' . implode(' AND ', $where) . ' ORDER BY purpose, name',
        $params
    );
    $placement = null;
    if (!empty($_GET['placement_id'])) {
        $placement = scopedFind(
            'SELECT id, billing_operating_cycle_id, ap_operating_cycle_id, payroll_operating_cycle_id
               FROM placements WHERE tenant_id = :tenant_id AND id = :id',
            ['id' => (int) $_GET['placement_id']]
        );
    }
    api_ok(['cycles' => $rows, 'placement' => $placement]);
}

if ($method === 'POST' && $action === 'assign') {
    rbac_legacy_require($user, 'placements.manage');
    $body = api_json_body();
    api_require_fields($body, ['placement_id']);
    $placementId = (int) $body['placement_id'];
    $map = [
        'billing' => 'billing_operating_cycle_id',
        'ap' => 'ap_operating_cycle_id',
        'payroll' => 'payroll_operating_cycle_id',
    ];
    $legacyMap = [
        'billing' => 'billing_cycle_id',
        'ap' => 'ap_cycle_id',
        'payroll' => 'payroll_cycle_id',
    ];
    $updates = [];
    foreach ($map as $purpose => $field) {
        if (!array_key_exists($field, $body)) continue;
        $cycleId = $body[$field] === null || $body[$field] === '' ? null : (int) $body[$field];
        if ($cycleId) {
            $cycle = scopedFind(
                'SELECT id, purpose, payroll_pay_cycle_id FROM staffing_operating_cycles
                  WHERE tenant_id = :tenant_id AND id = :id AND active = 1',
                ['id' => $cycleId]
            );
            if (!$cycle || $cycle['purpose'] !== $purpose) api_error("{$field} must reference an active {$purpose} cycle", 422);
            $updates[$legacyMap[$purpose]] = !empty($cycle['payroll_pay_cycle_id'])
                ? (int) $cycle['payroll_pay_cycle_id']
                : null;
        } else {
            $updates[$legacyMap[$purpose]] = null;
        }
        $updates[$field] = $cycleId;
    }
    if (!$updates) api_error('No cycle assignments supplied', 422);
    $before = placementAuditRow($placementId);
    scopedUpdate('placements', $placementId, $updates);
    placementEconomicsReconcile($tenantId, $placementId);
    placementsAudit('placement.cycles.assigned', [
        'placement_id' => $placementId, 'assignments' => $updates,
    ], $placementId, ['before' => $before, 'after' => placementAuditRow($placementId)]);
    api_ok(['ok' => true, 'placement' => placementGet($placementId)]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'placements.manage');
    $body = api_json_body();
    api_require_fields($body, ['name','purpose','cadence']);
    $purpose = (string) $body['purpose'];
    $cadence = (string) $body['cadence'];
    if (!in_array($purpose, ['billing','ap','payroll'], true)) api_error('Invalid purpose', 422);
    if (!in_array($cadence, ['weekly','biweekly','semimonthly','monthly','adhoc'], true)) api_error('Invalid cadence', 422);
    $anchor = (string) ($body['anchor_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) api_error('anchor_date must be YYYY-MM-DD', 422);
    $offset = (int) ($body['settlement_offset_days'] ?? 0);
    $defaultTerms = $purpose === 'ap'
        ? placementEconomicsNormaliseTerms((string) ($body['default_payment_terms'] ?? 'NET30'))
        : null;
    $payrollBridgeId = $purpose === 'payroll'
        ? placementCycleCreatePayrollBridge($tenantId, trim((string) $body['name']), $cadence, $anchor, $offset)
        : null;
    try {
        $id = scopedInsert('staffing_operating_cycles', [
            'purpose' => $purpose,
            'name' => trim((string) $body['name']),
            'cadence' => $cadence,
            'anchor_date' => $anchor,
            'settlement_offset_days' => $offset,
            'default_payment_terms' => $defaultTerms,
            'payroll_pay_cycle_id' => $payrollBridgeId,
            'source_system' => 'coreflux',
            'active' => 1,
            'created_by_user_id' => $user['id'] ?? null,
        ]);
    } catch (\Throwable $e) {
        api_error('Cycle name already exists for this purpose.', 409);
    }
    placementsAudit('staffing.operating_cycle.created', [
        'cycle_id' => $id, 'purpose' => $purpose, 'cadence' => $cadence,
        'payroll_pay_cycle_id' => $payrollBridgeId,
    ], $id);

    if (!empty($body['placement_id'])) {
        $placementId = (int) $body['placement_id'];
        $field = $purpose . '_operating_cycle_id';
        if ($purpose === 'billing') $field = 'billing_operating_cycle_id';
        $updates = [$field => $id];
        $legacyField = ['billing' => 'billing_cycle_id', 'ap' => 'ap_cycle_id', 'payroll' => 'payroll_cycle_id'][$purpose];
        $updates[$legacyField] = $payrollBridgeId ?: null;
        scopedUpdate('placements', $placementId, $updates);
        placementEconomicsReconcile($tenantId, $placementId);
        placementsAudit('placement.cycles.assigned', [
            'placement_id' => $placementId,
            'assignments' => $updates,
            'source' => 'cycle_create',
        ], $placementId);
    }
    api_ok(['id' => $id, 'payroll_pay_cycle_id' => $payrollBridgeId], 201);
}

if ($method === 'PATCH') {
    rbac_legacy_require($user, 'placements.manage');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    $allowed = ['name','cadence','anchor_date','settlement_offset_days','default_payment_terms','active'];
    $updates = [];
    foreach ($allowed as $field) if (array_key_exists($field, $body)) $updates[$field] = $body[$field];
    if (!$updates) api_error('No fields to update', 422);
    if (isset($updates['cadence']) && !in_array($updates['cadence'], ['weekly','biweekly','semimonthly','monthly','adhoc'], true)) {
        api_error('Invalid cadence', 422);
    }
    if (isset($updates['anchor_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $updates['anchor_date'])) {
        api_error('anchor_date must be YYYY-MM-DD', 422);
    }
    if (array_key_exists('default_payment_terms', $updates) && $updates['default_payment_terms'] !== null) {
        $updates['default_payment_terms'] = placementEconomicsNormaliseTerms((string) $updates['default_payment_terms']);
    }
    scopedUpdate('staffing_operating_cycles', $id, $updates);
    placementsAudit('staffing.operating_cycle.updated', ['cycle_id' => $id, 'fields' => array_keys($updates)], $id);
    api_ok(['ok' => true]);
}

api_error('Method not allowed', 405);
