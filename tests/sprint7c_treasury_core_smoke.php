<?php
/**
 * Sprint 7c smoke — Treasury Payments + Transfers + Cash Position (spec §15-16, §28).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Migration 005 — treasury_payments\n";
$m5 = (string) file_get_contents("{$ROOT}/modules/treasury/migrations/005_treasury_payments.sql");
$assert('migration exists',                   strlen($m5) > 0);
$assert('table treasury_payments',            stripos($m5, 'CREATE TABLE IF NOT EXISTS treasury_payments') !== false);
$assert('8-state machine in status enum',
    stripos($m5, "ENUM('draft','pending_approval','approved','scheduled','executed','failed','voided','rejected')") !== false);
$assert('payee_type enum 5-way',
    stripos($m5, "ENUM('vendor','employee','customer','tax_authority','other')") !== false);
$assert('payment_method enum',
    stripos($m5, "ENUM('ach','check','wire','card','other')") !== false);
$assert('bank_account_id NOT NULL',           stripos($m5, 'bank_account_id BIGINT UNSIGNED NOT NULL') !== false);
$assert('journal_entry_id nullable (set on execute)',
    stripos($m5, 'journal_entry_id BIGINT UNSIGNED NULL') !== false);
$assert('accounting_event_id link',           stripos($m5, 'accounting_event_id BIGINT UNSIGNED NULL') !== false);
$assert('workflow_instance_id link',          stripos($m5, 'workflow_instance_id BIGINT UNSIGNED NULL') !== false);
$assert('uq_tp_tenant_number',                stripos($m5, 'uq_tp_tenant_number') !== false);
$assert('hot indexes',                        stripos($m5, 'idx_tp_tenant_status') !== false
                                            && stripos($m5, 'idx_tp_tenant_bank') !== false
                                            && stripos($m5, 'idx_tp_payee') !== false
                                            && stripos($m5, 'idx_tp_workflow') !== false);

echo "\nMigration 006 — treasury_transfers\n";
$m6 = (string) file_get_contents("{$ROOT}/modules/treasury/migrations/006_treasury_transfers.sql");
$assert('table treasury_transfers',           stripos($m6, 'CREATE TABLE IF NOT EXISTS treasury_transfers') !== false);
$assert('transfer_kind enum internal/intercompany',
    stripos($m6, "ENUM('internal','intercompany')") !== false);
$assert('source + destination bank cols',
    stripos($m6, 'source_bank_account_id') !== false
    && stripos($m6, 'destination_bank_account_id') !== false);
$assert('source + destination entity cols',
    stripos($m6, 'source_entity_id') !== false
    && stripos($m6, 'destination_entity_id') !== false);
$assert('source + destination JE cols',
    stripos($m6, 'source_journal_entry_id') !== false
    && stripos($m6, 'destination_journal_entry_id') !== false);
$assert('workflow index',                     stripos($m6, 'idx_tt_workflow') !== false);

echo "\napi/treasury_payments.php\n";
$tp = (string) file_get_contents("{$ROOT}/api/treasury_payments.php");
$assert('parses',                             $lint("{$ROOT}/api/treasury_payments.php"));
$assert('GET requires treasury.payment.view',
    strpos($tp, "rbac_legacy_require(\$user, 'treasury.payment.view')") !== false);
$assert('create requires treasury.create_payment',
    strpos($tp, "rbac_legacy_require(\$user, 'treasury.create_payment')") !== false);
$assert('approve requires treasury.approve_payment',
    strpos($tp, "rbac_legacy_require(\$user, 'treasury.approve_payment')") !== false);
$assert('approve routes through Treasury WorkflowGraph',
    strpos($tp, 'treasuryPaymentWorkflowAct($tid, $id') !== false
    && strpos($tp, "'approve'") !== false);
$assert('execute requires treasury.execute_payment',
    strpos($tp, "rbac_legacy_require(\$user, 'treasury.execute_payment')") !== false);
$assert('execute emits treasury.payment.executed event',
    strpos($tp, "'event_type' => 'treasury.payment.executed'") !== false);
$assert('execute calls accountingProcessEvent',
    strpos($tp, 'accountingProcessEvent($tid, $event') !== false);
$assert('execute → status=executed when posted',
    strpos($tp, 'status="executed"') !== false);
$assert('execute → status=failed on engine failure',
    strpos($tp, 'status="failed"') !== false);
$assert('void cannot void executed',
    strpos($tp, 'Cannot void an executed payment') !== false);
$assert('approve transitions only from draft|pending_approval',
    strpos($tp, "['draft', 'pending_approval']") !== false);
$assert('execute transitions only from approved|scheduled',
    strpos($tp, "['approved', 'scheduled']") !== false);
$assert('payment_number auto-generated when missing',
    strpos($tp, "'TPY-%d-%s'") !== false);

echo "\napi/treasury_transfers.php\n";
$tt = (string) file_get_contents("{$ROOT}/api/treasury_transfers.php");
$assert('parses',                             $lint("{$ROOT}/api/treasury_transfers.php"));
$assert('GET requires treasury.payment.view',
    strpos($tt, "rbac_legacy_require(\$user, 'treasury.payment.view')") !== false);
$assert('create rejects same src+dst',        strpos($tt, 'source and destination cannot be the same') !== false);
$assert('detects intercompany via entity mismatch',
    strpos($tt, "\$srcEntity !== \$dstEntity) ? 'intercompany' : 'internal'") !== false);
$assert('execute internal emits treasury.transfer.completed',
    strpos($tt, "'treasury.transfer.completed'") !== false);
$assert('execute intercompany emits treasury.intercompany.transfer.completed',
    strpos($tt, "'treasury.intercompany.transfer.completed'") !== false);
$assert('approve requires treasury.approve_transfer',
    strpos($tt, "rbac_legacy_require(\$user, 'treasury.approve_transfer')") !== false);
$assert('approve routes through Treasury WorkflowGraph',
    strpos($tt, 'treasuryTransferWorkflowAct($tid, $id') !== false
    && strpos($tt, "'approve'") !== false);
$assert('create requires treasury.create_transfer',
    strpos($tt, "rbac_legacy_require(\$user, 'treasury.create_transfer')") !== false);
$assert('payload carries both bank account ids',
    strpos($tt, "'source_bank_account_id'") !== false
    && strpos($tt, "'destination_bank_account_id'") !== false);
$assert('payload carries both entity ids',
    strpos($tt, "'source_entity_id'") !== false
    && strpos($tt, "'destination_entity_id'") !== false);

echo "\napi/treasury_cash_position.php\n";
$cp = (string) file_get_contents("{$ROOT}/api/treasury_cash_position.php");
$assert('parses',                             $lint("{$ROOT}/api/treasury_cash_position.php"));
$assert('GET-only',                           strpos($cp, "if (api_method() !== 'GET')") !== false);
$assert('requires treasury.view_bank_balances',
    strpos($cp, "rbac_legacy_require(\$user, 'treasury.view_bank_balances')") !== false);
$assert('as_of validation',                   strpos($cp, "/^\\d{4}-\\d{2}-\\d{2}\$/") !== false);
$assert('GL balance from posted JEs only',
    strpos($cp, "AND je.status   = 'posted'") !== false
    && strpos($cp, 'jl.debit') !== false
    && strpos($cp, 'jl.credit') !== false);
$assert('joins accounting_bank_accounts → accounting_accounts',
    strpos($cp, 'accounting_bank_accounts') !== false
    && strpos($cp, 'accounting_accounts') !== false
    && strpos($cp, 'aa.code = ba.gl_account_code') !== false);
$assert('outflow pending status whitelist',
    strpos($cp, "'pending_approval','approved','scheduled'") !== false);
$assert('forecast_days clamped 0..60',        strpos($cp, 'min(60, (int) (api_query') !== false);
$assert('minimum liquidity threshold input',
    strpos($cp, "api_query('minimum_liquidity_threshold')") !== false
    && strpos($cp, "api_query('minimum_liquidity_currency')") !== false);
$assert('per-currency totals + projected balance',
    strpos($cp, "'projected_balance'") !== false
    && strpos($cp, "'by_currency'") !== false);
$assert('available-to-spend control envelope',
    strpos($cp, "'liquidity_controls'") !== false
    && strpos($cp, "'available_to_spend'") !== false
    && strpos($cp, 'available_to_spend = current_cash') !== false);
$assert('cash safety score and risk level',
    strpos($cp, "'cash_safety_score'") !== false
    && strpos($cp, "'coverage_ratio'") !== false
    && strpos($cp, "'risk_level'") !== false);
$assert('high-confidence AP/AR source signals',
    strpos($cp, 'FROM ap_bills') !== false
    && strpos($cp, 'FROM billing_invoices') !== false
    && strpos($cp, "'high_confidence_near_term_outflows'") !== false
    && strpos($cp, "'high_confidence_near_term_inflows'") !== false);
$assert('graceful when no reconciliations table',
    strpos($cp, "SHOW TABLES LIKE 'accounting_reconciliations'") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
