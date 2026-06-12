<?php
/**
 * People — Bank Accounts (encrypted direct deposit)
 *
 * Plaintext routing/account numbers are NEVER stored, NEVER logged.
 * Only ciphertext + last4 + HMAC hash persist.
 *
 * GET ?employee_id=N                list active accounts (masked only)
 * POST                              add a new account (body includes plaintext routing/account)
 * PUT ?id=N                         update non-sensitive fields (priority, allocation, nickname, status)
 * DELETE ?id=N                      soft-close (status='closed', closed_at=today)
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/encryption.php';
require_once __DIR__ . '/../lib/employees.php';
require_once __DIR__ . '/../lib/audit.php';

$ctx = api_require_auth();
$user = $ctx['user'];

switch (api_method()) {
    case 'GET': {
        rbac_legacy_require($user, 'people.banking.view');
        $empId = (int) (api_query('employee_id') ?? 0);
        if (!$empId) api_error('Missing employee_id', 422);
        $rows = scopedQuery(
            'SELECT * FROM people_bank_accounts
             WHERE tenant_id = :tenant_id AND employee_id = :emp
             ORDER BY priority ASC',
            ['emp' => $empId]
        );
        _bankAccountAudit($ctx, $empId, null, 'view', ['row_count' => count($rows), 'last4_only' => true]);
        peopleAudit('people.banking.viewed', ['employee_id' => $empId, 'row_count' => count($rows), 'last4_only' => true], $empId);
        api_ok(['bank_accounts' => array_map('_presentBank', $rows)]);
    }

    case 'POST': {
        rbac_legacy_require($user, 'people.banking.manage');
        $body = api_json_body();
        api_require_fields($body, ['employee_id','routing_number','account_number']);
        $empId = (int) $body['employee_id'];

        $data = [
            'employee_id'      => $empId,
            'priority'         => (int)($body['priority']         ?? 1),
            'allocation_type'  => $body['allocation_type']        ?? 'remainder',
            'allocation_value' => isset($body['allocation_value']) ? (int) $body['allocation_value'] : null,
            'account_type'     => $body['account_type']           ?? 'checking',
            'bank_name'        => $body['bank_name']              ?? null,
            'routing_cipher'   => encryptField($body['routing_number']),
            'routing_last4'    => last4($body['routing_number']),
            'routing_hash'     => fieldHash($body['routing_number']),
            'account_cipher'   => encryptField($body['account_number']),
            'account_last4'    => last4($body['account_number']),
            'account_hash'     => fieldHash($body['account_number']),
            'status'           => $body['status']                 ?? 'pending_verified',
            'effective_from'   => $body['effective_from']         ?? date('Y-m-d'),
        ];
        $id = scopedInsert('people_bank_accounts', $data);

        // Audit — never include cipher/plaintext in the changelog
        try {
            scopedInsert('people_change_log', [
                'user_id'        => $ctx['user']['id'] ?? null,
                'employee_id'    => $empId,
                'entity'         => 'bank_account',
                'entity_id'      => $id,
                'action'         => 'create',
                'fields_changed' => json_encode(['priority','allocation_type','account_type','status','routing_last4','account_last4']),
                'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) { error_log($e->getMessage()); }

        peopleAudit('people.banking.updated', ['employee_id' => $empId, 'bank_account_id' => $id, 'action' => 'create'], $empId);
        api_ok(['id' => $id], 201);
    }

    case 'PUT':
    case 'PATCH': {
        rbac_legacy_require($user, 'people.banking.manage');
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        $body = api_json_body();
        // Only non-sensitive fields can be updated here. To change numbers, POST a new account + close the old one.
        $allowed = ['priority','allocation_type','allocation_value','account_type','bank_name','status'];
        $update = array_intersect_key($body, array_flip($allowed));
        if (!$update) api_ok(['ok' => true]);
        scopedUpdate('people_bank_accounts', $id, $update);
        peopleAudit('people.banking.updated', ['bank_account_id' => $id, 'fields' => array_keys($update), 'action' => 'update'], $id);
        api_ok(['ok' => true]);
    }

    case 'DELETE': {
        rbac_legacy_require($user, 'people.banking.manage');
        $id = (int) (api_query('id') ?? 0);
        if (!$id) api_error('Missing id', 422);
        scopedUpdate('people_bank_accounts', $id, [
            'status'    => 'closed',
            'closed_at' => date('Y-m-d'),
        ]);
        peopleAudit('people.banking.updated', ['bank_account_id' => $id, 'action' => 'close'], $id);
        api_ok(['ok' => true]);
    }
}
api_error('Method not allowed', 405);


function _presentBank(array $row): array {
    // NEVER return ciphertext to the client. Masks only.
    unset($row['routing_cipher'], $row['account_cipher'], $row['routing_hash'], $row['account_hash']);
    $row['routing_masked'] = $row['routing_last4'] ? '•••' . $row['routing_last4'] : null;
    $row['account_masked'] = $row['account_last4'] ? '•••' . $row['account_last4'] : null;
    return $row;
}

function _bankAccountAudit(array $ctx, int $employeeId, ?int $entityId, string $action, array $fields): void {
    try {
        scopedInsert('people_change_log', [
            'user_id'        => $ctx['user']['id'] ?? null,
            'employee_id'    => $employeeId,
            'entity'         => 'bank_account',
            'entity_id'      => $entityId,
            'action'         => $action,
            'fields_changed' => json_encode($fields),
            'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
}
