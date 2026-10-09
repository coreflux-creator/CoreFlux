<?php
/** Tables and columns needed by the CoreAccounting invoice, bill and bank lifecycle. */
declare(strict_types=1);

function coreAccountingRequiredSchema(): array
{
    return [
        'password_resets' => ['id', 'user_id', 'email', 'token_hash', 'expires_at',
            'used_at', 'created_at'],
        'accounting_entities' => ['id', 'tenant_id', 'code', 'base_currency', 'active'],
        'accounting_periods' => ['id', 'tenant_id', 'entity_id', 'start_date', 'end_date', 'status'],
        'accounting_accounts' => ['id', 'tenant_id', 'code', 'account_type', 'normal_side',
            'is_postable', 'statement_section', 'active'],
        'accounting_journal_entries' => ['id', 'tenant_id', 'entity_id', 'period_id',
            'posting_date', 'status', 'posted_at'],
        'accounting_journal_entry_lines' => ['id', 'tenant_id', 'je_id', 'account_id',
            'debit', 'credit'],
        'accounting_posting_idempotency' => ['tenant_id', 'idempotency_key', 'je_id'],
        'accounting_events' => ['id', 'tenant_id', 'entity_id', 'source_module',
            'source_record_id', 'status', 'journal_entry_id'],
        'accounting_subledger_links' => ['id', 'tenant_id', 'source_module',
            'source_record_id', 'journal_entry_id', 'link_kind'],
        'accounting_bank_accounts' => ['id', 'tenant_id', 'entity_id', 'gl_account_code',
            'currency', 'status'],
        'accounting_bank_statement_imports' => ['id', 'tenant_id', 'bank_account_id',
            'updated_at'],
        'accounting_bank_statement_lines' => ['id', 'tenant_id', 'bank_account_id',
            'match_status', 'matched_je_id', 'updated_at'],
        'accounting_reconciliations' => ['id', 'tenant_id', 'bank_account_id', 'status'],
        'billing_invoices' => ['id', 'tenant_id', 'entity_id', 'status', 'total',
            'amount_due', 'journal_entry_id', 'created_by_user_id'],
        'billing_invoice_lines' => ['id', 'invoice_id', 'description', 'quantity',
            'unit_price', 'total'],
        'billing_invoice_tokens' => ['id', 'tenant_id', 'invoice_id', 'token_hash',
            'expires_at', 'revoked_at', 'revoked_by_user_id', 'delivery_request_id',
            'delivery_status', 'delivery_recipient', 'delivery_provider_id',
            'delivery_error', 'delivery_started_at', 'delivery_finished_at'],
        'billing_payments' => ['id', 'tenant_id', 'bank_account_id', 'journal_entry_id',
            'amount', 'voided_at'],
        'billing_payment_allocations' => ['id', 'payment_id', 'invoice_id',
            'amount_applied', 'reversed_at'],
        'ap_bills' => ['id', 'tenant_id', 'entity_id', 'status', 'total', 'amount_due',
            'journal_entry_id', 'created_by_user_id'],
        'ap_bill_lines' => ['id', 'bill_id', 'description', 'total',
            'gl_expense_account_code'],
        'ap_approval_policies' => ['id', 'tenant_id', 'entity_id', 'chain_json', 'active'],
        'ap_bill_approvals' => ['id', 'tenant_id', 'bill_id', 'approver_user_id',
            'step_no', 'state'],
        'ap_payments' => ['id', 'tenant_id', 'entity_id', 'bank_account_id',
            'status', 'journal_entry_id'],
        'ap_payment_allocations' => ['id', 'payment_id', 'bill_id', 'amount_applied'],
        'workflow_definitions' => ['id', 'tenant_id', 'def_key', 'steps_json', 'active'],
        'workflow_instances' => ['id', 'tenant_id', 'subject_type', 'subject_id',
            'status', 'started_by_user_id'],
        'coreone_accounting_credentials' => ['id', 'tenant_id', 'entity_id',
            'token_hash', 'scopes_json', 'created_by_user_id', 'revoked_at'],
        'coreone_document_requests' => ['id', 'tenant_id', 'entity_id', 'source_type',
            'source_record_id', 'intent_hash', 'target_id'],
    ];
}

function coreAccountingRequiredUniqueKeys(): array
{
    return [
        'accounting_posting_idempotency' => [['tenant_id', 'idempotency_key']],
        'accounting_events' => [['tenant_id', 'source_module', 'source_record_id', 'event_type']],
        'accounting_journal_entries' => [['tenant_id', 'je_number']],
        'accounting_bank_statement_lines' => [['tenant_id', 'bank_account_id', 'fitid']],
        'billing_invoices' => [
            ['tenant_id', 'invoice_number'],
            ['tenant_id', 'source_system', 'external_id'],
        ],
        'billing_invoice_tokens' => [
            ['token_hash'],
            ['tenant_id', 'invoice_id', 'delivery_request_id'],
        ],
        'billing_payments' => [['tenant_id', 'source_system', 'external_id']],
        'ap_bills' => [
            ['tenant_id', 'internal_ref'],
            ['tenant_id', 'source_system', 'external_id'],
        ],
        'ap_payments' => [['tenant_id', 'source_system', 'external_id']],
        'coreone_document_requests' => [['tenant_id', 'source_type', 'source_record_id']],
    ];
}

function coreAccountingMissingUniqueKeys(array $present): array
{
    $missing = [];
    foreach (coreAccountingRequiredUniqueKeys() as $table => $requiredKeys) {
        $available = $present[$table] ?? [];
        foreach ($requiredKeys as $columns) {
            if (!in_array($columns, $available, true)) {
                $missing[] = $table . ' UNIQUE (' . implode(', ', $columns) . ')';
            }
        }
    }
    return $missing;
}

function coreAccountingMissingSchema(array $present): array
{
    $missing = [];
    foreach (coreAccountingRequiredSchema() as $table => $columns) {
        if (!array_key_exists($table, $present)) {
            $missing[] = $table;
            continue;
        }
        $available = array_fill_keys($present[$table], true);
        foreach ($columns as $column) {
            if (!isset($available[$column])) $missing[] = $table . '.' . $column;
        }
    }
    return $missing;
}

function coreAccountingInspectSchema(PDO $pdo): array
{
    $required = coreAccountingRequiredSchema();
    $tables = array_keys($required);
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $stmt = $pdo->prepare(
        "SELECT table_name, column_name FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name IN ($placeholders)"
    );
    $stmt->execute($tables);
    $present = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $present[$row['table_name']][] = $row['column_name'];
    }
    $indexStmt = $pdo->prepare(
        "SELECT table_name, index_name, seq_in_index, column_name, sub_part
           FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name IN ($placeholders)
            AND non_unique = 0
          ORDER BY table_name, index_name, seq_in_index"
    );
    $indexStmt->execute($tables);
    $uniqueIndexes = [];
    foreach ($indexStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $column = $row['column_name'];
        if ($row['sub_part'] !== null) $column .= '(' . $row['sub_part'] . ')';
        $uniqueIndexes[$row['table_name']][$row['index_name']][] = $column;
    }
    $uniqueKeys = [];
    foreach ($uniqueIndexes as $table => $indexes) {
        $uniqueKeys[$table] = array_values($indexes);
    }
    $requiredUniqueKeys = coreAccountingRequiredUniqueKeys();
    return [
        'required_tables' => count($required),
        'required_columns' => array_sum(array_map('count', $required)),
        'required_unique_keys' => array_sum(array_map('count', $requiredUniqueKeys)),
        'missing' => array_merge(
            coreAccountingMissingSchema($present),
            coreAccountingMissingUniqueKeys($uniqueKeys)
        ),
    ];
}
