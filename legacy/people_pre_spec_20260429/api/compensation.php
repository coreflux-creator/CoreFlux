<?php
/**
 * People — Compensation (history-aware, append-only)
 *
 * GET ?employee_id=N            list full history (newest first)
 * GET ?employee_id=N&active=1   current active row
 * POST                           create a new comp row; auto-ends previous active row's effective_to
 *                                to be one day before the new effective_from.
 *
 * Deterministic calculation: any pay math consumers do uses rate_cents +
 * pay_frequency — AI NEVER produces these.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../lib/employees.php';

$ctx = api_require_auth();

switch (api_method()) {
    case 'GET': {
        $empId = (int) (api_query('employee_id') ?? 0);
        if (!$empId) api_error('Missing employee_id', 422);
        if ((int) api_query('active') === 1) {
            api_ok(['compensation' => peopleActiveCompensation($empId)]);
        }
        $rows = scopedQuery(
            'SELECT * FROM people_compensation
             WHERE tenant_id = :tenant_id AND employee_id = :emp
             ORDER BY effective_from DESC, id DESC',
            ['emp' => $empId]
        );
        api_ok(['compensation' => $rows]);
    }

    case 'POST': {
        $body = api_json_body();
        api_require_fields($body, ['employee_id','pay_type','pay_rate_cents','pay_frequency','effective_from']);
        $empId = (int) $body['employee_id'];
        $effFrom = $body['effective_from'];

        // Close any currently-open row (effective_to IS NULL) by setting its effective_to
        // to one day before the new effective_from.
        $pdo = getDB();
        if ($pdo) {
            $stmt = $pdo->prepare(
                'UPDATE people_compensation
                 SET effective_to = DATE_SUB(:eff, INTERVAL 1 DAY), updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND employee_id = :emp
                   AND effective_to IS NULL AND effective_from < :eff'
            );
            $stmt->execute(['eff' => $effFrom, 'tenant_id' => currentTenantId(), 'emp' => $empId]);
        }

        $id = scopedInsert('people_compensation', [
            'employee_id'       => $empId,
            'pay_type'          => $body['pay_type'],
            'pay_rate_cents'    => (int) $body['pay_rate_cents'],
            'pay_frequency'     => $body['pay_frequency'],
            'currency'          => $body['currency']            ?? 'USD',
            'bonus_target_cents'=> isset($body['bonus_target_cents']) ? (int) $body['bonus_target_cents'] : null,
            'effective_from'    => $effFrom,
            'effective_to'      => $body['effective_to']        ?? null,
            'reason'            => $body['reason']              ?? null,
            'notes'             => $body['notes']               ?? null,
            'created_by'        => $ctx['user']['id'] ?? null,
        ]);

        // Audit
        try {
            scopedInsert('people_change_log', [
                'user_id'        => $ctx['user']['id'] ?? null,
                'employee_id'    => $empId,
                'entity'         => 'comp',
                'entity_id'      => $id,
                'action'         => 'create',
                'fields_changed' => json_encode(['pay_type','pay_rate_cents','pay_frequency','effective_from']),
                'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) { error_log($e->getMessage()); }

        api_ok(['id' => $id], 201);
    }
}

api_error('Method not allowed', 405);
