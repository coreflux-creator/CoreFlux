<?php
/**
 * Staffing posting-rules seeder.
 *
 * Installs four default journal templates + matching posting rules for
 * the `staffing.worker_hours.approved` event, so an approved timesheet
 * automatically books a balanced four-leg JE:
 *
 *   W2 hours:
 *     DR  5000  Direct Labor Expense      payload.wage_cost
 *     DR  5020  Employer payroll tax      payload.employer_load_cost
 *     DR  5050  Workers compensation      payload.workers_comp_cost
 *     DR  5060  Worker benefits           payload.benefits_cost
 *     DR  5080  Other direct cost         payload.other_direct_cost
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
 *   Referral-only hours:
 *     DR  5010  Subcontractor Expense     payload.cost
 *     CR  2050  Accrued AP                payload.cost
 *     DR  1150  Unbilled Receivable       payload.revenue
 *     CR  4000  Service Revenue           payload.revenue
 *
 * The four posting rules each scope themselves by payload conditions
 * (`is_w2`, `is_1099_or_c2c`, `is_internal`, `is_referral`).
 *
 * Idempotent and customization-safe: existing templates and rules are left
 * intact; only missing defaults are inserted.
 *
 * Usage: call `staffingSeedPostingRules($tenantId)` once per tenant.
 * Safe to re-run.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/accounting/system_accounts.php';

function staffingSeedPostingRules(int $tenantId): array {
    $pdo = getDB();

    // Ensure the 4 staffing-specific system accounts exist on this tenant.
    accountingSeedSystemAccounts($tenantId);

    // Staffing activity is assignment-centric. Register the shared axes once
    // so every journal line can carry the same business context without
    // multiplying the chart of accounts. They remain optional by default;
    // account-specific requirements can be tightened by an administrator.
    $dimensionDefinitions = [
        ['client',          'Client',                    'reference', 'companies'],
        ['placement',       'Assignment / placement',    'reference', 'placements'],
        ['worker',          'Worker',                    'reference', 'people'],
        ['job',             'Job / requisition',         'reference', 'staffing_jobs'],
        ['recruiter',       'Recruiter',                 'reference', 'users'],
        ['account_manager', 'Account manager / sales',   'reference', 'users'],
        ['branch',          'Branch / business unit',    'text',      null],
        ['service_line',    'Service line',              'text',      null],
        ['work_state',      'Work location / state',     'text',      null],
        ['wc_class',        'Workers compensation class','text',      null],
        ['department',      'Department',                'text',      null],
        ['cost_center',     'Cost center',               'text',      null],
        ['legal_entity',    'Legal entity',              'reference', 'accounting_entities'],
        ['counterparty_entity','Counterparty legal entity','reference','accounting_entities'],
        // Vendor is polymorphic: company, person, or legacy AP-vendor key.
        // Store its canonical tagged key instead of pointing at a per-placement
        // relationship row that would fragment reporting.
        ['vendor',          'Vendor / payable party',    'text',      null],
    ];
    $dimensionInsert = $pdo->prepare(
        'INSERT IGNORE INTO accounting_dimensions
            (tenant_id, dim_key, label, data_type, reference_table,
             description, required_default, active, sort_order)
         VALUES
            (:tenant_id, :dim_key, :label, :data_type, :reference_table,
             :description, 0, 1, :sort_order)'
    );
    $dimensionsInserted = 0;
    foreach ($dimensionDefinitions as $index => [$key, $label, $dataType, $referenceTable]) {
        $dimensionInsert->execute([
            'tenant_id' => $tenantId,
            'dim_key' => $key,
            'label' => $label,
            'data_type' => $dataType,
            'reference_table' => $referenceTable,
            'description' => 'Inherited from the staffing assignment at event time.',
            'sort_order' => ($index + 1) * 10,
        ]);
        $dimensionsInserted += $dimensionInsert->rowCount();
    }
    $pdo->prepare(
        "UPDATE accounting_dimensions
            SET data_type = 'text', reference_table = NULL
          WHERE tenant_id = :tenant_id AND dim_key = 'vendor'
            AND data_type = 'reference'
            AND reference_table = 'placement_economic_parties'"
    )->execute(['tenant_id' => $tenantId]);

    $accountId = function (string $name) use ($pdo, $tenantId): ?int {
        $st = $pdo->prepare("SELECT id FROM accounting_accounts WHERE tenant_id = :t AND name = :n AND is_system_account = 1 LIMIT 1");
        $st->execute(['t' => $tenantId, 'n' => $name]);
        $id = $st->fetchColumn();
        return $id ? (int) $id : null;
    };

    $needed = [
        'Direct Labor Expense', 'Subcontractor Expense',
        'Employer Payroll Tax Expense', 'Workers Compensation Expense',
        'Worker Benefits Expense', 'Other Direct Placement Cost',
        'Accrued Payroll', 'Accrued AP', 'Unbilled Receivable', 'Service Revenue',
    ];
    $missing = [];
    $accounts = [];
    foreach ($needed as $n) {
        $accounts[$n] = $accountId($n);
        if (!$accounts[$n]) $missing[] = $n;
    }
    if ($missing) {
        return ['ok' => false, 'reason' => 'missing system accounts: ' . implode(', ', $missing)];
    }

    // Control-account defaults enforce the dimensions that are intrinsic to
    // the balance itself. These rules intentionally avoid requiring an
    // assignment on general product/service invoices or ordinary overhead.
    // INSERT IGNORE preserves any tenant-specific rule already configured.
    $dimensionIds = [];
    $dimensionRows = $pdo->prepare(
        'SELECT id, dim_key FROM accounting_dimensions WHERE tenant_id = :tenant_id AND active = 1'
    );
    $dimensionRows->execute(['tenant_id' => $tenantId]);
    foreach ($dimensionRows->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $dimensionRow) {
        $dimensionIds[(string) $dimensionRow['dim_key']] = (int) $dimensionRow['id'];
    }
    $controlDimensionRules = [
        '1000' => ['legal_entity'],
        '1100' => ['client', 'legal_entity'],
        '1150' => ['client', 'placement', 'legal_entity'],
        '2000' => ['vendor', 'legal_entity'],
        '2050' => ['vendor', 'legal_entity'],
        '1500' => ['legal_entity', 'counterparty_entity'],
        '2500' => ['legal_entity', 'counterparty_entity'],
        // These accounts have assignment-specific meanings. Generic products,
        // services, and overhead continue to use 4000 / 6000-series accounts
        // without fabricating worker or placement dimensions.
        '4010' => ['client', 'placement', 'worker', 'legal_entity'],
        '4020' => ['client', 'placement', 'recruiter', 'legal_entity'],
        '4030' => ['client', 'placement', 'legal_entity'],
        '5010' => ['placement', 'vendor', 'legal_entity'],
        '5030' => ['placement', 'worker', 'legal_entity'],
        '5040' => ['placement', 'worker', 'work_state', 'legal_entity'],
        '5050' => ['placement', 'worker', 'wc_class', 'legal_entity'],
        '5060' => ['placement', 'worker', 'legal_entity'],
        '5070' => ['placement', 'vendor', 'legal_entity'],
        '5080' => ['placement', 'legal_entity'],
    ];
    $accountByCode = $pdo->prepare(
        'SELECT id FROM accounting_accounts
          WHERE tenant_id = :tenant_id AND code = :code AND active = 1 AND is_postable = 1
          LIMIT 1'
    );
    $insertDimensionRule = $pdo->prepare(
        "INSERT IGNORE INTO accounting_account_dim_rules
            (tenant_id, account_id, dimension_id, requirement)
         VALUES (:tenant_id, :account_id, :dimension_id, 'required')"
    );
    $dimensionRulesInserted = 0;
    foreach ($controlDimensionRules as $accountCode => $dimensionKeys) {
        $accountByCode->execute(['tenant_id' => $tenantId, 'code' => $accountCode]);
        $controlAccountId = (int) ($accountByCode->fetchColumn() ?: 0);
        if (!$controlAccountId) continue;
        foreach ($dimensionKeys as $dimensionKey) {
            $dimensionId = $dimensionIds[$dimensionKey] ?? 0;
            if ($dimensionId <= 0) continue;
            $insertDimensionRule->execute([
                'tenant_id' => $tenantId,
                'account_id' => $controlAccountId,
                'dimension_id' => $dimensionId,
            ]);
            $dimensionRulesInserted += $insertDimensionRule->rowCount();
        }
    }

    $templates = [
        'staffing.w2_hours_approved' => [
            'description' => 'Auto-book W2 worker hours: DR Direct Labor / CR Accrued Payroll / DR Unbilled AR / CR Service Revenue',
            'memo'        => 'Approved W2 hours — TS#{payload.timesheet_id}, period {payload.period_start} → {payload.period_end}',
            'lines' => [
                ['Direct Labor Expense', 'payload.wage_cost', '0',             'Assigned worker wages', null],
                ['Employer Payroll Tax Expense', 'payload.employer_load_cost', '0', 'Employer payroll burden', null],
                ['Workers Compensation Expense', 'payload.workers_comp_cost', '0',  'Workers compensation', null],
                ['Worker Benefits Expense', 'payload.benefits_cost', '0',      'Worker benefits', null],
                ['Other Direct Placement Cost', 'payload.other_direct_cost', '0', 'Other direct placement cost', null],
                ['Accrued Payroll',      '0',               'payload.cost',    'Accrued payroll liability', null],
                ['Unbilled Receivable',  'payload.revenue', '0',               'Unbilled AR accrual', null],
                ['Service Revenue',      '0',               'payload.revenue', 'Service revenue accrual', null],
            ],
        ],
        'staffing.contractor_hours_approved' => [
            'description' => 'Auto-book 1099/C2C contractor hours: DR Subcontractor / CR Accrued AP / DR Unbilled AR / CR Service Revenue',
            'memo'        => 'Approved 1099/C2C hours — TS#{payload.timesheet_id}, period {payload.period_start} → {payload.period_end}',
            'lines' => [
                ['Subcontractor Expense','payload.cost',    '0',               'Subcontractor expense', ['vendor' => 'payload.vendor_dimension']],
                ['Accrued AP',           '0',               'payload.cost',    'Accrued AP liability', ['vendor' => 'payload.vendor_dimension']],
                ['Unbilled Receivable',  'payload.revenue', '0',               'Unbilled AR accrual', null],
                ['Service Revenue',      '0',               'payload.revenue', 'Service revenue accrual', null],
            ],
        ],
        'staffing.internal_hours_approved' => [
            'description' => 'Auto-book internal salaried-staff hours: DR Direct Labor / CR Accrued Payroll (no revenue leg)',
            'memo'        => 'Approved internal hours — TS#{payload.timesheet_id}',
            'lines' => [
                ['Direct Labor Expense', 'payload.wage_cost', '0',             'Internal labor expense', null],
                ['Employer Payroll Tax Expense', 'payload.employer_load_cost', '0', 'Internal employer burden', null],
                ['Workers Compensation Expense', 'payload.workers_comp_cost', '0',  'Internal workers compensation', null],
                ['Worker Benefits Expense', 'payload.benefits_cost', '0',      'Internal worker benefits', null],
                ['Other Direct Placement Cost', 'payload.other_direct_cost', '0', 'Internal other direct cost', null],
                ['Accrued Payroll',      '0',               'payload.cost',    'Accrued internal payroll', null],
            ],
        ],
        'staffing.referral_hours_approved' => [
            'description' => 'Auto-book referral-only hours: DR Subcontractor / CR Accrued AP / DR Unbilled AR / CR Service Revenue',
            'memo'        => 'Approved referral hours - TS#{payload.timesheet_id}, period {payload.period_start} to {payload.period_end}',
            'lines' => [
                ['Subcontractor Expense','payload.cost',    '0',               'Referral vendor expense', ['vendor' => 'payload.vendor_dimension']],
                ['Accrued AP',           '0',               'payload.cost',    'Accrued referral payable', ['vendor' => 'payload.vendor_dimension']],
                ['Unbilled Receivable',  'payload.revenue', '0',               'Unbilled referral receivable', null],
                ['Service Revenue',      '0',               'payload.revenue', 'Referral service revenue', null],
            ],
        ],
    ];

    $tplIds = [];
    $templatesInserted = 0;
    $templatesUpgraded = 0;
    $rulesInserted = 0;
    $legacyTemplateSignatures = [
        'staffing.w2_hours_approved' => [
            ['system:Direct Labor Expense', 'payload.cost', '0', 'Direct labor expense'],
            ['system:Accrued Payroll', '0', 'payload.cost', 'Accrued payroll liability'],
            ['system:Unbilled Receivable', 'payload.revenue', '0', 'Unbilled AR accrual'],
            ['system:Service Revenue', '0', 'payload.revenue', 'Service revenue accrual'],
        ],
        'staffing.internal_hours_approved' => [
            ['system:Direct Labor Expense', 'payload.cost', '0', 'Internal labor expense'],
            ['system:Accrued Payroll', '0', 'payload.cost', 'Accrued internal payroll'],
        ],
    ];
    foreach ($templates as $key => $t) {
        $exist = $pdo->prepare('SELECT id FROM accounting_journal_templates WHERE tenant_id = :t AND name = :n LIMIT 1');
        $exist->execute(['t' => $tenantId, 'n' => $key]);
        $tplId = (int) ($exist->fetchColumn() ?: 0);
        $templateCreated = false;
        if (!$tplId) {
            $ins = $pdo->prepare("INSERT INTO accounting_journal_templates (tenant_id, name, description, memo_template, currency_source) VALUES (:t, :n, :d, :m, 'entity_default')");
            $ins->execute(['t' => $tenantId, 'n' => $key, 'd' => $t['description'], 'm' => $t['memo']]);
            $tplId = (int) $pdo->lastInsertId();
            $templateCreated = true;
            $templatesInserted++;
        }
        $tplIds[$key] = $tplId;

        $lineCount = 0;
        if (!$templateCreated) {
            $readLines = $pdo->prepare(
                'SELECT account_selector, debit_formula, credit_formula,
                        description_template, dimensions_json
                   FROM accounting_journal_template_lines
                  WHERE tenant_id = :t AND journal_template_id = :id
                  ORDER BY line_no'
            );
            $readLines->execute(['t' => $tenantId, 'id' => $tplId]);
            $existingLines = $readLines->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $lineCount = count($existingLines);

            // Upgrade only the exact, untouched default W-2/internal shapes
            // that predate burden-component accounts. Any changed formula,
            // description, or dimension map marks the template as customized.
            $legacy = $legacyTemplateSignatures[$key] ?? null;
            if ($legacy && count($legacy) === $lineCount) {
                $isUntouchedLegacy = true;
                foreach ($legacy as $index => [$selector, $debit, $credit, $description]) {
                    $line = $existingLines[$index] ?? [];
                    $dimensionJson = strtolower(trim((string) ($line['dimensions_json'] ?? '')));
                    if ((string) ($line['account_selector'] ?? '') !== $selector
                        || (string) ($line['debit_formula'] ?? '') !== $debit
                        || (string) ($line['credit_formula'] ?? '') !== $credit
                        || (string) ($line['description_template'] ?? '') !== $description
                        || !in_array($dimensionJson, ['', '{}', '[]', 'null'], true)) {
                        $isUntouchedLegacy = false;
                        break;
                    }
                }
                if ($isUntouchedLegacy) {
                    $pdo->prepare(
                        'DELETE FROM accounting_journal_template_lines
                          WHERE tenant_id = :t AND journal_template_id = :id'
                    )->execute(['t' => $tenantId, 'id' => $tplId]);
                    $lineCount = 0;
                    $templatesUpgraded++;
                }
            }
        }
        if ($lineCount === 0) {
            $line = 0;
            foreach ($t['lines'] as [$acctName, $dbF, $crF, $desc, $dimensions]) {
                $line++;
                $pdo->prepare("INSERT INTO accounting_journal_template_lines (tenant_id, journal_template_id, line_no, account_selector, debit_formula, credit_formula, description_template, dimensions_json) VALUES (:t, :tp, :ln, :sel, :db, :cr, :dsc, :dims)")
                    ->execute([
                        't' => $tenantId, 'tp' => $tplId, 'ln' => $line,
                        'sel' => 'system:' . $acctName,
                        'db' => $dbF, 'cr' => $crF, 'dsc' => $desc,
                        'dims' => $dimensions ? json_encode($dimensions) : null,
                    ]);
            }
        }

        // Backfill vendor context into previously seeded default templates,
        // while leaving any administrator-authored dimension map untouched.
        foreach ($t['lines'] as $lineIndex => $lineDefinition) {
            $dimensions = $lineDefinition[4] ?? null;
            if (!$dimensions) continue;
            $pdo->prepare(
                "UPDATE accounting_journal_template_lines
                    SET dimensions_json = :dims
                  WHERE tenant_id = :tenant_id
                    AND journal_template_id = :template_id
                    AND line_no = :line_no
                    AND (dimensions_json IS NULL OR CAST(dimensions_json AS CHAR) IN ('{}','[]','null'))"
            )->execute([
                'dims' => json_encode($dimensions),
                'tenant_id' => $tenantId,
                'template_id' => $tplId,
                'line_no' => $lineIndex + 1,
            ]);
        }
    }

    // Four posting rules - each routes a different engagement_type bucket
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
        ['name' => 'staffing.referral_hours_approved',
         'priority' => 130, 'tpl' => $tplIds['staffing.referral_hours_approved'],
         'cond' => json_encode(['payload.is_referral' => 1])],
    ];
    foreach ($rules as $r) {
        $exist = $pdo->prepare('SELECT id FROM accounting_posting_rules WHERE tenant_id = :t AND name = :n LIMIT 1');
        $exist->execute(['t' => $tenantId, 'n' => $r['name']]);
        if ($exist->fetchColumn()) continue;
        $pdo->prepare("INSERT INTO accounting_posting_rules (tenant_id, name, event_type, priority, conditions, journal_template_id, status) VALUES (:t, :n, 'staffing.worker_hours.approved', :p, :c, :tp, 'active')")
            ->execute(['t' => $tenantId, 'n' => $r['name'], 'p' => $r['priority'], 'c' => $r['cond'], 'tp' => $r['tpl']]);
        $rulesInserted++;
    }

    return [
        'ok' => true,
        'templates' => array_keys($tplIds),
        'templates_inserted' => $templatesInserted,
        'templates_upgraded' => $templatesUpgraded,
        'rules_inserted' => $rulesInserted,
        'dimensions_inserted' => $dimensionsInserted,
        'dimension_rules_inserted' => $dimensionRulesInserted,
        'accounts' => array_map('intval', $accounts),
    ];
}
