<?php
/**
 * Staffing posting-rules seeder.
 *
 * Installs three default journal templates + matching posting rules for
 * the `staffing.worker_hours.approved` event, so an approved timesheet
 * automatically books a balanced four-leg JE:
 *
 *   W2 hours:
 *     DR  5000  Direct Labor Expense      payload.cost
 *     CR  2150  Accrued Payroll           payload.cost
 *     DR  1150  Unbilled Receivable       payload.revenue
 *     CR  4000  Service Revenue           payload.revenue
 *
 *   1099 / C2C hours:
 *     DR  5010  Subcontractor Expense     payload.cost
 *     CR  2050  Accrued AP                payload.cost
 *     DR  1150  Unbilled Receivable       payload.revenue
 *     CR  4000  Service Revenue           payload.revenue
 *
 *   Internal hours (non-billable salaried staff):
 *     DR  5000  Direct Labor Expense      payload.cost
 *     CR  2150  Accrued Payroll           payload.cost
 *
 * The three posting rules each scope themselves by payload conditions
 * (`is_w2`, `is_1099_or_c2c`, `is_internal`).
 *
 * IDEMPOTENT — uses INSERT IGNORE on (tenant_id, name) for templates
 * and (tenant_id, event_type, priority) for rules.
 *
 * Usage: call `staffingSeedPostingRules($tenantId)` once per tenant.
 * Safe to re-run.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/accounting/system_accounts.php';

function staffingSeedPostingRules(int $tenantId): array {
    $pdo = getDB();

    // Ensure the 4 staffing-specific system accounts exist on this tenant.
    accountingSeedSystemAccounts($pdo, $tenantId);

    $accountId = function (string $name) use ($pdo, $tenantId): ?int {
        $st = $pdo->prepare("SELECT id FROM accounting_accounts WHERE tenant_id = :t AND name = :n AND is_system_account = 1 LIMIT 1");
        $st->execute(['t' => $tenantId, 'n' => $name]);
        $id = $st->fetchColumn();
        return $id ? (int) $id : null;
    };

    $needed = ['Direct Labor Expense','Subcontractor Expense','Accrued Payroll','Accrued AP','Unbilled Receivable','Service Revenue'];
    $missing = [];
    $accounts = [];
    foreach ($needed as $n) {
        $accounts[$n] = $accountId($n);
        if (!$accounts[$n]) $missing[] = $n;
    }
    if ($missing) {
        return ['ok' => false, 'reason' => 'missing system accounts: ' . implode(', ', $missing)];
    }

    $templates = [
        'staffing.w2_hours_approved' => [
            'description' => 'Auto-book W2 worker hours: DR Direct Labor / CR Accrued Payroll / DR Unbilled AR / CR Service Revenue',
            'memo'        => 'Approved W2 hours — TS#{payload.timesheet_id}, period {payload.period_start} → {payload.period_end}',
            'lines' => [
                ['Direct Labor Expense', 'payload.cost',    '0',               'Direct labor expense'],
                ['Accrued Payroll',      '0',               'payload.cost',    'Accrued payroll liability'],
                ['Unbilled Receivable',  'payload.revenue', '0',               'Unbilled AR accrual'],
                ['Service Revenue',      '0',               'payload.revenue', 'Service revenue accrual'],
            ],
        ],
        'staffing.contractor_hours_approved' => [
            'description' => 'Auto-book 1099/C2C contractor hours: DR Subcontractor / CR Accrued AP / DR Unbilled AR / CR Service Revenue',
            'memo'        => 'Approved 1099/C2C hours — TS#{payload.timesheet_id}, period {payload.period_start} → {payload.period_end}',
            'lines' => [
                ['Subcontractor Expense','payload.cost',    '0',               'Subcontractor expense'],
                ['Accrued AP',           '0',               'payload.cost',    'Accrued AP liability'],
                ['Unbilled Receivable',  'payload.revenue', '0',               'Unbilled AR accrual'],
                ['Service Revenue',      '0',               'payload.revenue', 'Service revenue accrual'],
            ],
        ],
        'staffing.internal_hours_approved' => [
            'description' => 'Auto-book internal salaried-staff hours: DR Direct Labor / CR Accrued Payroll (no revenue leg)',
            'memo'        => 'Approved internal hours — TS#{payload.timesheet_id}',
            'lines' => [
                ['Direct Labor Expense', 'payload.cost',    '0',               'Internal labor expense'],
                ['Accrued Payroll',      '0',               'payload.cost',    'Accrued internal payroll'],
            ],
        ],
    ];

    $tplIds = [];
    foreach ($templates as $key => $t) {
        $exist = $pdo->prepare('SELECT id FROM accounting_journal_templates WHERE tenant_id = :t AND name = :n LIMIT 1');
        $exist->execute(['t' => $tenantId, 'n' => $key]);
        $tplId = (int) ($exist->fetchColumn() ?: 0);
        if (!$tplId) {
            $ins = $pdo->prepare("INSERT INTO accounting_journal_templates (tenant_id, name, description, memo_template, currency_source) VALUES (:t, :n, :d, :m, 'entity_default')");
            $ins->execute(['t' => $tenantId, 'n' => $key, 'd' => $t['description'], 'm' => $t['memo']]);
            $tplId = (int) $pdo->lastInsertId();
        }
        $tplIds[$key] = $tplId;

        // Replace lines (idempotent per template).
        $pdo->prepare('DELETE FROM accounting_journal_template_lines WHERE tenant_id = :t AND journal_template_id = :id')
            ->execute(['t' => $tenantId, 'id' => $tplId]);
        $line = 0;
        foreach ($t['lines'] as [$acctName, $dbF, $crF, $desc]) {
            $line++;
            $pdo->prepare("INSERT INTO accounting_journal_template_lines (tenant_id, journal_template_id, line_no, account_selector, debit_formula, credit_formula, description_template) VALUES (:t, :tp, :ln, :sel, :db, :cr, :dsc)")
                ->execute([
                    't' => $tenantId, 'tp' => $tplId, 'ln' => $line,
                    'sel' => 'system:' . $acctName,
                    'db' => $dbF, 'cr' => $crF, 'dsc' => $desc,
                ]);
        }
    }

    // Three posting rules — each routes a different engagement_type bucket
    // to its matching template.
    $rules = [
        ['name' => 'staffing.w2_hours_approved',
         'priority' => 100, 'tpl' => $tplIds['staffing.w2_hours_approved'],
         'cond' => json_encode(['payload.is_w2' => 1])],
        ['name' => 'staffing.contractor_hours_approved',
         'priority' => 110, 'tpl' => $tplIds['staffing.contractor_hours_approved'],
         'cond' => json_encode(['payload.is_1099_or_c2c' => 1])],
        ['name' => 'staffing.internal_hours_approved',
         'priority' => 120, 'tpl' => $tplIds['staffing.internal_hours_approved'],
         'cond' => json_encode(['payload.is_internal' => 1])],
    ];
    foreach ($rules as $r) {
        $exist = $pdo->prepare('SELECT id FROM accounting_posting_rules WHERE tenant_id = :t AND name = :n LIMIT 1');
        $exist->execute(['t' => $tenantId, 'n' => $r['name']]);
        if ($exist->fetchColumn()) continue;
        $pdo->prepare("INSERT INTO accounting_posting_rules (tenant_id, name, event_type, priority, conditions_json, journal_template_id, is_active) VALUES (:t, :n, 'staffing.worker_hours.approved', :p, :c, :tp, 1)")
            ->execute(['t' => $tenantId, 'n' => $r['name'], 'p' => $r['priority'], 'c' => $r['cond'], 'tp' => $r['tpl']]);
    }

    return ['ok' => true, 'templates' => array_keys($tplIds), 'accounts' => array_map('intval', $accounts)];
}
