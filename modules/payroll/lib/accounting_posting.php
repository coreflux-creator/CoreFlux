<?php
/**
 * Payroll → Accounting posting bridge.
 *
 * Approval recognizes wages, payroll taxes, deductions, and net-pay
 * liabilities. Payment clears only the net-pay liability against cash;
 * tax and deduction liabilities remain until their own remittance flows.
 */

require_once __DIR__ . '/../../accounting/lib/accounting.php';
require_once __DIR__ . '/../../../core/posting_engine/process.php';
require_once __DIR__ . '/../../../core/accounting/system_accounts.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__ . '/../../staffing/lib/dimensions.php';

function payrollAccountingPostingDefaults(): array
{
    return [
        'wage_expense_account_code'              => '5000',
        'payroll_tax_expense_account_code'       => '5020',
        'payroll_payable_account_code'            => '2200',
        'payroll_tax_payable_account_code'        => '2210',
        'payroll_deduction_payable_account_code'  => '2220',
        'payroll_cash_account_code'               => '1000',
        'auto_post_to_ledger'                     => 1,
    ];
}

function payrollAccountingPostingSettings(int $tenantId): array
{
    $stmt = getDB()->prepare('SELECT * FROM payroll_settings WHERE tenant_id = :t LIMIT 1');
    $stmt->execute(['t' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

    $settings = payrollAccountingPostingDefaults();
    foreach ($settings as $key => $default) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            $settings[$key] = $row[$key];
        }
    }
    return $settings;
}

function payrollAccountingPostingReadiness(int $tenantId, array $run): array
{
    accountingSeedSystemAccounts($tenantId);
    $settings = payrollAccountingPostingSettings($tenantId);
    $expectations = [
        'wage_expense_account_code'             => ['label' => 'Wage expense', 'type' => 'expense'],
        'payroll_tax_expense_account_code'      => ['label' => 'Employer payroll tax expense', 'type' => 'expense'],
        'payroll_payable_account_code'           => ['label' => 'Net-pay payable', 'type' => 'liability'],
        'payroll_tax_payable_account_code'       => ['label' => 'Payroll tax payable', 'type' => 'liability'],
        'payroll_deduction_payable_account_code' => ['label' => 'Deduction payable', 'type' => 'liability'],
        'payroll_cash_account_code'              => ['label' => 'Payroll cash', 'type' => 'asset'],
    ];

    $stmt = getDB()->prepare(
        'SELECT code, name, account_type, active, is_postable
           FROM accounting_accounts
          WHERE tenant_id = :t'
    );
    $stmt->execute(['t' => $tenantId]);
    $accounts = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $account) {
        $accounts[strtolower((string) $account['code'])] = $account;
    }

    $mappings = [];
    $issues = [];
    foreach ($expectations as $key => $expectation) {
        $code = trim((string) ($settings[$key] ?? ''));
        $account = $accounts[strtolower($code)] ?? null;
        $mapping = [
            'key' => $key,
            'label' => $expectation['label'],
            'code' => $code,
            'expected_type' => $expectation['type'],
            'account_name' => $account['name'] ?? null,
            'account_type' => $account['account_type'] ?? null,
            'ready' => false,
        ];
        if ($code === '' || !$account) {
            $issues[] = "{$expectation['label']}: account {$code} was not found";
        } elseif (!(bool) $account['active']) {
            $issues[] = "{$expectation['label']}: account {$code} is inactive";
        } elseif (!(bool) $account['is_postable']) {
            $issues[] = "{$expectation['label']}: account {$code} is a summary account";
        } elseif ((string) $account['account_type'] !== $expectation['type']) {
            $issues[] = "{$expectation['label']}: account {$code} must be {$expectation['type']}";
        } else {
            $mapping['ready'] = true;
        }
        $mappings[] = $mapping;
    }

    $status = (string) ($run['status'] ?? '');
    return [
        'ready' => count($issues) === 0,
        'auto_post' => (bool) ((int) ($settings['auto_post_to_ledger'] ?? 1)),
        'issues' => $issues,
        'mappings' => $mappings,
        'accrual_required' => in_array($status, ['approved', 'paid'], true),
        'accrual_posted' => !empty($run['journal_entry_id']),
        'journal_entry_id' => !empty($run['journal_entry_id']) ? (int) $run['journal_entry_id'] : null,
        'cash_required' => $status === 'paid',
        'cash_posted' => !empty($run['cash_journal_entry_id']),
        'cash_journal_entry_id' => !empty($run['cash_journal_entry_id']) ? (int) $run['cash_journal_entry_id'] : null,
    ];
}

function payrollAccountingRequireReady(int $tenantId, array $run): array
{
    $readiness = payrollAccountingPostingReadiness($tenantId, $run);
    if (!$readiness['ready']) {
        throw new \RuntimeException(
            'Payroll accounting is not configured: ' . implode('; ', $readiness['issues'])
            . '. Update Payroll → Settings → Accounting.'
        );
    }
    return payrollAccountingPostingSettings($tenantId);
}

function payrollAccountingAppendLine(
    array &$lines,
    string $accountCode,
    int $debitCents,
    int $creditCents,
    string $memo,
    array $dimensions = []
): void
{
    if ($debitCents <= 0 && $creditCents <= 0) return;
    ksort($dimensions);
    $key = strtolower($accountCode) . ':' . ($debitCents > 0 ? 'debit' : 'credit')
        . ':' . hash('sha256', json_encode($dimensions));
    if (!isset($lines[$key])) {
        $lines[$key] = [
            'account_code' => $accountCode,
            'debit' => 0.0,
            'credit' => 0.0,
            'memo' => $memo,
            'dims' => $dimensions,
        ];
    }
    $lines[$key]['debit'] += $debitCents / 100;
    $lines[$key]['credit'] += $creditCents / 100;
}

function payrollAccountingStampLink(int $tenantId, int $runId, int $journalEntryId, string $kind, bool $required = false): void
{
    try {
        getDB()->prepare(
            'INSERT IGNORE INTO accounting_subledger_links
                (tenant_id, source_module, source_record_id, journal_entry_id, link_kind)
             VALUES (:t, "payroll", :source, :je, :kind)'
        )->execute([
            't' => $tenantId,
            'source' => 'payroll_run:' . $runId,
            'je' => $journalEntryId,
            'kind' => $kind,
        ]);
    } catch (\Throwable $e) {
        if ($required) throw $e;
        error_log('[payroll accounting link] ' . $e->getMessage());
    }
}

function payrollAccountingPostedEventResult(array $eventResult, string $label): array
{
    if (($eventResult['status'] ?? null) !== 'posted' || empty($eventResult['journal_entry_id'])) {
        throw new \RuntimeException(
            $label . ' could not be posted to the ledger: '
            . (string) ($eventResult['error'] ?? 'no posting rule matched')
        );
    }

    return [
        'je_id' => (int) $eventResult['journal_entry_id'],
        'je_number' => $eventResult['je_number'] ?? null,
        'status' => 'posted',
        'total_debit' => (float) ($eventResult['total_debit'] ?? 0),
        'total_credit' => (float) ($eventResult['total_credit'] ?? 0),
        'idempotent_replay' => !empty($eventResult['idempotent_replay']),
        'event_id' => isset($eventResult['event_id']) ? (int) $eventResult['event_id'] : null,
    ];
}

function payrollAccountingAccountId(int $tenantId, string $accountCode): int
{
    $stmt = getDB()->prepare(
        'SELECT id FROM accounting_accounts
          WHERE tenant_id = :tenant_id AND code = :code AND active = 1 AND is_postable = 1
          LIMIT 1'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'code' => $accountCode]);
    $accountId = (int) ($stmt->fetchColumn() ?: 0);
    if ($accountId <= 0) throw new \RuntimeException("Payroll ledger account {$accountCode} is unavailable");
    return $accountId;
}

function payrollAccountingRunEntityId(int $tenantId, array $run): int
{
    $journalEntryId = (int) ($run['journal_entry_id'] ?? 0);
    if ($journalEntryId <= 0) {
        throw new \RuntimeException('Post the payroll accrual before recording its cash disbursement');
    }

    $stmt = getDB()->prepare(
        'SELECT entity_id
           FROM accounting_journal_entries
          WHERE tenant_id = :tenant_id
            AND id = :journal_entry_id
            AND status = "posted"
          LIMIT 1'
    );
    $stmt->execute([
        'tenant_id' => $tenantId,
        'journal_entry_id' => $journalEntryId,
    ]);
    $entityId = (int) ($stmt->fetchColumn() ?: 0);
    if ($entityId <= 0) {
        throw new \RuntimeException(
            'The payroll accrual journal is not posted. Repair the accrual before recording its cash disbursement.'
        );
    }
    return $entityId;
}

/** @return array<int,array<string,mixed>> keyed by payroll employee id */
function payrollAccountingRunEmployeeRows(int $tenantId, int $runId): array
{
    require_once __DIR__ . '/../../people/lib/employment_links.php';
    $pdo=getDB();
    if (!peopleEmploymentSchema($pdo)) throw new RuntimeException('Payroll posting needs the reviewed employment-link update.',503);
    $peopleTenantId=peopleEmploymentOwner($tenantId,$pdo->inTransaction());
    $stmt=$pdo->prepare(
        'SELECT li.*, e.employee_number, e.legal_first_name, e.legal_last_name,
                e.department, e.location, p.id AS person_id, l.id AS employment_link_id
           FROM payroll_line_items li
      LEFT JOIN people_employees e ON e.tenant_id=:people_owner AND e.id=li.employee_id
      LEFT JOIN people_employment_links l ON l.tenant_id=e.tenant_id AND l.employee_id=e.id
      LEFT JOIN people p ON p.tenant_id=l.tenant_id AND p.id=l.person_id
          WHERE li.tenant_id=:tenant_id AND li.run_id=:run_id
          ORDER BY li.employee_id,li.id'.($pdo->inTransaction()?' FOR UPDATE':'')
    );
    $stmt->execute(['people_owner'=>$peopleTenantId,'tenant_id'=>$tenantId,'run_id'=>$runId]);
    $rows=[];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $employeeId=(int)$row['employee_id'];
        if ((int)($row['person_id']??0)<=0 || (int)($row['employment_link_id']??0)<=0) {
            throw new RuntimeException('Payroll employee #'.$employeeId.' needs a reviewed person/employment link before posting.',409);
        }
        if (isset($rows[$employeeId])) throw new RuntimeException('Payroll contains duplicate employee line items. Review the run before posting.',409);
        $row['person_id']=(int)$row['person_id'];
        $rows[$employeeId]=$row;
    }
    return $rows;
}

/**
 * Return approved-time accruals already recognized for this payroll run.
 * Each row retains the immutable dimensions captured on the originating
 * timesheet event so later assignment edits cannot rewrite history.
 *
 * @return list<array<string,mixed>>
 */
function payrollAccountingStaffingAccrualGroups(
    int $tenantId,
    int $runId,
    array $employeeRows
): array {
    if (!$employeeRows) return [];
    require_once __DIR__ . '/../../time/lib/approval_economics.php';
    $accountingTenantId = effectiveTenantIdForModule('accounting', $tenantId) ?? $tenantId;
    $pdo = getDB();
    $lock = $pdo->inTransaction() ? ' FOR UPDATE' : '';
    $sources = $pdo->prepare(
        'SELECT * FROM time_entries WHERE tenant_id=? AND person_id=?
           AND CAST(payroll_extracted_ref AS CHAR) IN (?,?) ORDER BY id'.$lock
    );
    $events = $pdo->prepare(
        "SELECT id,entity_id,source_record_id,payload,status,journal_entry_id FROM accounting_events
          WHERE tenant_id=? AND event_type='staffing.worker_hours.approved'
            AND source_module='staffing' AND source_record_id IN (?,?,?) ORDER BY id".$lock
    );
    $groups = [];
    $seenEntries = [];
    foreach ($employeeRows as $employeeId => $employee) {
        if ((int)$employeeId<=0 || (int)($employee['person_id']??0)<=0) {
            throw new RuntimeException('Payroll accrual requires the reviewed employee/person identity.',409);
        }
        $sources->execute([$tenantId,(int)$employee['person_id'],(string)$runId,'payroll:run#'.$runId]);
        $entries = $sources->fetchAll(PDO::FETCH_ASSOC);
        // Verify the complete selection, including sources with no posted event.
        // Dropping changed/unapproved rows here would falsely increase new labor expense.
        $rates = timeApprovedRatesByEntry($entries,$tenantId);
        foreach ($entries as $entry) {
            $entryId=(int)$entry['id'];
            if (isset($seenEntries[$entryId])) throw new RuntimeException('One approved time source maps to multiple payroll employees. Review its identity before posting.',409);
            $seenEntries[$entryId]=true;
            if ((int)$entry['payable']!==1) continue;
            $rate=$rates[$entryId];
            $snapshot=json_decode((string)$entry['dimension_snapshot_json'],true);
            $engagement=(string)($snapshot['engagement_type']??'');
            if (!in_array($engagement,['w2','temp_to_perm','internal'],true)) {
                throw new RuntimeException('The approved payroll source has no employee engagement evidence. Review it before posting.',409);
            }
            if (strtoupper(trim((string)($rate['currency']??'')))!=='USD'
                || ($rate['pay_rate_unit']??'')!=='hour') {
                throw new RuntimeException('Approved payroll accrual needs an explicit USD hourly source rate. Review its original currency and unit.',409);
            }
            $timesheetId=(int)($entry['timesheet_id']??0);
            $placementId=(int)$entry['placement_id'];
            if ($timesheetId<=0 || $placementId<=0) throw new RuntimeException('The approved payroll source has no original timesheet or placement.',409);
            $base='timesheet:'.$timesheetId.':placement:'.$placementId.':'.$engagement;
            $legacy=$timesheetId.':'.$engagement;
            $events->execute([$accountingTenantId,$base,$base.':segment:'.substr((string)$entry['dimension_snapshot_hash'],0,24),$legacy]);
            $matches=$events->fetchAll(PDO::FETCH_ASSOC);
            if (!$matches) continue;
            if (array_filter($matches,static fn(array $event):bool=>$event['source_record_id']===$legacy)) {
                throw new RuntimeException('Payroll run #'.$runId.' includes time booked by a legacy aggregate staffing accrual. '
                    .'Reconcile that timesheet posting by assignment before posting payroll; otherwise labor would be counted twice.',409);
            }
            if (count($matches)!==1) throw new RuntimeException('Overlapping staffing accrual events cover the same approved time. Reconcile them before posting payroll.',409);
            $event=$matches[0];
            $payload=json_decode((string)$event['payload'],true);
            $dimensions=is_array($payload['dimensions']??null)?$payload['dimensions']:[];
            if (!is_scalar($dimensions['placement']??null) || !is_scalar($dimensions['worker']??null)
                || (string)$dimensions['placement']!==(string)$placementId
                || (string)$dimensions['worker']!==(string)$entry['person_id']
                || (int)$event['entity_id']<=0) {
                throw new RuntimeException('Staffing accrual event #'.(int)$event['id'].' has a different or missing assignment snapshot. Review it before posting payroll.',409);
            }
            $key=(int)$event['id'];
            $group=$groups[$key]??[
                'employee_id'=>(int)$employeeId,'placement_id'=>$placementId,'timesheet_id'=>$timesheetId,
                'accounting_event_id'=>$key,'entity_id'=>(int)$event['entity_id'],'dimensions'=>$dimensions,
                '_amounts'=>[], '_event'=>$event,
            ];
            if ($group['employee_id']!==(int)$employeeId || $group['placement_id']!==$placementId || $group['timesheet_id']!==$timesheetId) {
                throw new RuntimeException('A staffing accrual event covers conflicting payroll source identities.',409);
            }
            $amounts=timeApprovedEntryAmounts($entry,$rate,$snapshot);
            foreach (['wage_cost','employer_load_cost','workers_comp_cost','benefits_cost','other_direct_cost'] as $field) {
                $group['_amounts'][$field]=($group['_amounts'][$field]??0)+$amounts[$field];
            }
            $groups[$key]=$group;
        }
    }
    ksort($groups,SORT_NUMERIC);
    foreach ($groups as &$group) {
        // Match the original emitter's component rounding, including employer burdens.
        $components=array_map(static fn(float $amount):int=>(int)round($amount*100),$group['_amounts']);
        $group['accrued_wage_cents']=max(0,$components['wage_cost']);
        $group['accrued_total_cents']=max(0,array_sum($components));
        payrollAccountingVerifySourceAccrual($pdo,$accountingTenantId,$group['_event'],$group);
        unset($group['_amounts'],$group['_event']);
    }
    unset($group);
    return array_values($groups);
}

/** A source event label alone cannot establish an active liability to clear. */
function payrollAccountingVerifySourceAccrual(PDO $pdo,int $owner,array $event,array $group): void
{
    if ($event['status']!=='posted' || (int)$event['journal_entry_id']<=0) {
        throw new RuntimeException('The staffing accrual is not posted. Resolve its accounting request before payroll posting.',409);
    }
    $q=$pdo->prepare('SELECT status,entity_id FROM accounting_journal_entries WHERE tenant_id=? AND id=?'.($pdo->inTransaction()?' FOR UPDATE':''));
    $q->execute([$owner,$event['journal_entry_id']]);$journal=$q->fetch(PDO::FETCH_ASSOC);
    if (!$journal || $journal['status']!=='posted' || (int)$journal['entity_id']!==(int)$event['entity_id']) {
        throw new RuntimeException('The staffing accrual journal is missing, reversed or belongs to another entity. Reconcile it before payroll posting.',409);
    }
    $q=$pdo->prepare('SELECT l.credit,l.debit,l.dim_json FROM accounting_journal_entry_lines l
        JOIN accounting_accounts a ON a.tenant_id=l.tenant_id AND a.id=l.account_id
        WHERE l.tenant_id=? AND l.je_id=? AND a.code="2150" ORDER BY l.id'.($pdo->inTransaction()?' FOR UPDATE':''));
    $q->execute([$owner,$event['journal_entry_id']]);$available=0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $dimensions=json_decode((string)$line['dim_json'],true);
        if ($dimensions!==$group['dimensions'] && $dimensions!=$group['dimensions']) {
            throw new RuntimeException('The staffing accrual journal dimensions differ from its approved source.',409);
        }
        $available+=(int)round(((float)$line['credit']-(float)$line['debit'])*100);
    }
    $payload=json_decode((string)$event['payload'],true);
    if (!isset($payload['wage_cost'],$payload['cost']) || $available<=0
        || $available!==(int)round((float)$payload['cost']*100)
        || $group['accrued_total_cents']>$available
        || $group['accrued_wage_cents']>(int)round((float)$payload['wage_cost']*100)) {
        throw new RuntimeException('The approved payroll source exceeds or differs from its recorded staffing liability. Reconcile it before posting.',409);
    }
}

/** @return array<int,int> */
function payrollAccountingAllocateCents(int $total, array $weights): array
{
    $total = max(0, $total);
    $weights = array_map(static fn(mixed $weight): int => max(0, (int) $weight), $weights);
    $weightTotal = array_sum($weights);
    if ($total === 0 || $weightTotal === 0) return array_fill_keys(array_keys($weights), 0);

    $allocations = [];
    $fractions = [];
    $allocated = 0;
    foreach ($weights as $key => $weight) {
        $raw = ($total * $weight) / $weightTotal;
        $base = (int) floor($raw);
        $allocations[$key] = $base;
        $fractions[$key] = $raw - $base;
        $allocated += $base;
    }
    arsort($fractions, SORT_NUMERIC);
    $remaining = $total - $allocated;
    foreach (array_keys($fractions) as $key) {
        if ($remaining <= 0) break;
        $allocations[$key]++;
        $remaining--;
    }
    return $allocations;
}

function payrollPostRunAccrual(int $tenantId, int $runId, ?int $actorUserId): array
{
    $pdo=getDB();$owns=cf_tx_begin($pdo);
    if (!$owns) $pdo->exec('SAVEPOINT payroll_accrual_post');
    try {
        $lock=$pdo->prepare('SELECT id FROM payroll_runs WHERE tenant_id=? AND id=? FOR UPDATE');
        $lock->execute([$tenantId,$runId]);
        if (!$lock->fetchColumn()) throw new RuntimeException('Payroll run not found',404);
        $result=payrollPostRunAccrualLocked($tenantId,$runId,$actorUserId);
        if ($owns) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT payroll_accrual_post');
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            if ($owns) $pdo->rollBack();
            else {$pdo->exec('ROLLBACK TO SAVEPOINT payroll_accrual_post');$pdo->exec('RELEASE SAVEPOINT payroll_accrual_post');}
        }
        throw $error;
    }
}

/** Run, line items, approved sources and recorded accruals remain locked through posting. */
function payrollPostRunAccrualLocked(int $tenantId, int $runId, ?int $actorUserId): array
{
    if (!getDB()->inTransaction()) throw new LogicException('Payroll accrual requires its source transaction.');
    $run = payrollAccountingLoadRun($tenantId, $runId);
    if (!in_array((string) $run['status'], ['approved', 'paid'], true)) {
        throw new \RuntimeException('Payroll accrual can only post after final approval');
    }
    $settings = payrollAccountingRequireReady($tenantId, $run);

    $gross = (int) $run['gross_total_cents'];
    $employeeTaxes = (int) $run['taxes_total_cents'];
    $deductions = (int) $run['deductions_total_cents'];
    $net = (int) $run['net_total_cents'];
    $employerTaxes = (int) $run['employer_taxes_cents'];
    $debits = $gross + $employerTaxes;
    $credits = $net + $employeeTaxes + $deductions + $employerTaxes;
    if ($gross <= 0 || abs($debits - $credits) > 1) {
        throw new \RuntimeException(
            sprintf('Payroll totals do not balance: debits=%0.2f credits=%0.2f', $debits / 100, $credits / 100)
        );
    }

    $employeeRows = payrollAccountingRunEmployeeRows($tenantId, $runId);
    if (!$employeeRows) throw new \RuntimeException('Payroll run has no employee line items');
    $accrualGroups = payrollAccountingStaffingAccrualGroups($tenantId, $runId, $employeeRows);
    $groupsByEmployee = [];
    $assignmentEntityIds = [];
    foreach ($accrualGroups as $group) {
        $groupsByEmployee[(int) $group['employee_id']][] = $group;
        if ((int) $group['entity_id'] > 0) $assignmentEntityIds[(int) $group['entity_id']] = true;
    }
    if (count($assignmentEntityIds) > 1) {
        throw new \RuntimeException(
            'Payroll run #' . $runId . ' spans multiple legal entities. Split it into one payroll run per entity before posting.'
        );
    }
    $entityId = $assignmentEntityIds
        ? (int) array_key_first($assignmentEntityIds)
        : (int) accountingDefaultEntity($tenantId)['id'];
    if ($entityId <= 0) throw new \RuntimeException('Payroll run needs an active legal entity before posting');

    $memo = 'Payroll run #' . $runId . ' · ' . $run['period_start'] . ' to ' . $run['period_end'];
    $lines = [];
    $coveredGrossTotal = 0;
    $coveredEmployerTaxTotal = 0;
    $lineGrossTotal = 0;
    $lineEmployeeTaxTotal = 0;
    $lineDeductionTotal = 0;
    $lineNetTotal = 0;
    $lineEmployerTaxTotal = 0;

    foreach ($employeeRows as $employeeId => $employee) {
        $employeeGross = (int) $employee['gross_cents'];
        $employeeTaxesForLine = (int) $employee['employee_taxes_cents'];
        $employeeDeductions = (int) $employee['pretax_cents'] + (int) $employee['posttax_cents'];
        $employeeNet = (int) $employee['net_cents'];
        $employeeEmployerTaxes = (int) $employee['employer_taxes_cents'];
        $lineGrossTotal += $employeeGross;
        $lineEmployeeTaxTotal += $employeeTaxesForLine;
        $lineDeductionTotal += $employeeDeductions;
        $lineNetTotal += $employeeNet;
        $lineEmployerTaxTotal += $employeeEmployerTaxes;

        $employeeDimensions = array_filter([
            'worker' => (int) $employee['person_id'],
            'work_state' => trim((string) ($employee['work_state'] ?? '')) ?: null,
            'department' => trim((string) ($employee['department'] ?? '')) ?: null,
            'branch' => trim((string) ($employee['location'] ?? '')) ?: null,
            'legal_entity' => $entityId,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        $employeeGroups = array_values($groupsByEmployee[(int) $employeeId] ?? []);
        $weights = [];
        foreach ($employeeGroups as $index => $group) {
            if ((int) $group['entity_id'] !== $entityId) {
                throw new \RuntimeException('A staffing accrual belongs to a different legal entity than this payroll run');
            }
            $weights[$index] = (int) $group['accrued_wage_cents'];
        }
        $accruedWages = array_sum($weights);
        $coveredGross = min($employeeGross, $accruedWages);
        $grossAllocations = payrollAccountingAllocateCents($coveredGross, $weights);
        $targetCoveredEmployerTaxes = $employeeGross > 0
            ? min($employeeEmployerTaxes, (int) round($employeeEmployerTaxes * ($coveredGross / $employeeGross)))
            : 0;
        $taxAllocations = payrollAccountingAllocateCents($targetCoveredEmployerTaxes, $weights);
        $coveredEmployerTaxes = 0;

        foreach ($employeeGroups as $index => $group) {
            $grossAllocation = min((int) ($grossAllocations[$index] ?? 0), (int) $group['accrued_wage_cents']);
            $availableAccruedBurden = max(
                0,
                (int) $group['accrued_total_cents'] - (int) $group['accrued_wage_cents']
            );
            $taxAllocation = min((int) ($taxAllocations[$index] ?? 0), $availableAccruedBurden);
            $coveredEmployerTaxes += $taxAllocation;
            $clearAmount = $grossAllocation + $taxAllocation;
            $assignmentDimensions = (array) $group['dimensions'];
            $assignmentDimensions['legal_entity'] = $entityId;
            payrollAccountingAppendLine(
                $lines,
                '2150',
                $clearAmount,
                0,
                $memo . ' · clear approved-time accrual',
                $assignmentDimensions
            );
        }

        $uncoveredGross = $employeeGross - $coveredGross;
        $uncoveredEmployerTaxes = $employeeEmployerTaxes - $coveredEmployerTaxes;
        payrollAccountingAppendLine(
            $lines,
            (string) $settings['wage_expense_account_code'],
            $uncoveredGross,
            0,
            $memo . ' · wages not accrued from approved time',
            $employeeDimensions
        );
        payrollAccountingAppendLine(
            $lines,
            (string) $settings['payroll_tax_expense_account_code'],
            $uncoveredEmployerTaxes,
            0,
            $memo . ' · employer taxes not accrued from approved time',
            $employeeDimensions
        );
        payrollAccountingAppendLine(
            $lines,
            (string) $settings['payroll_payable_account_code'],
            0,
            $employeeNet,
            $memo . ' · net pay payable',
            $employeeDimensions
        );
        payrollAccountingAppendLine(
            $lines,
            (string) $settings['payroll_tax_payable_account_code'],
            0,
            $employeeTaxesForLine + $employeeEmployerTaxes,
            $memo . ' · taxes payable',
            $employeeDimensions
        );
        payrollAccountingAppendLine(
            $lines,
            (string) $settings['payroll_deduction_payable_account_code'],
            0,
            $employeeDeductions,
            $memo . ' · deductions payable',
            $employeeDimensions
        );
        $coveredGrossTotal += $coveredGross;
        $coveredEmployerTaxTotal += $coveredEmployerTaxes;
    }

    foreach ([
        ['gross', $lineGrossTotal, $gross],
        ['employee taxes', $lineEmployeeTaxTotal, $employeeTaxes],
        ['deductions', $lineDeductionTotal, $deductions],
        ['net pay', $lineNetTotal, $net],
        ['employer taxes', $lineEmployerTaxTotal, $employerTaxes],
    ] as [$label, $lineTotal, $runTotal]) {
        if (abs($lineTotal - $runTotal) > 1) {
            throw new \RuntimeException("Payroll {$label} detail does not match the run total");
        }
    }

    $renderedDebits = 0;
    $renderedCredits = 0;
    foreach ($lines as $line) {
        $renderedDebits += (int) round((float) $line['debit'] * 100);
        $renderedCredits += (int) round((float) $line['credit'] * 100);
    }
    $roundingCents = $renderedCredits - $renderedDebits;
    if ($roundingCents !== 0) {
        payrollAccountingAppendLine(
            $lines,
            '9900',
            $roundingCents > 0 ? $roundingCents : 0,
            $roundingCents < 0 ? abs($roundingCents) : 0,
            $memo . ' · payroll rounding',
            ['legal_entity' => $entityId]
        );
    }

    $eventResult = accountingProcessEvent($tenantId, [
        'entity_id' => $entityId,
        'event_type' => 'payroll.run.approved',
        'source_module' => 'payroll',
        'source_record_id' => 'payroll_run:' . $runId . ':accrual',
        'event_date' => (string) $run['period_end'],
        'payload' => [
            'run_id' => $runId,
            'gross' => $gross / 100,
            'taxes' => $employeeTaxes / 100,
            'net' => $net / 100,
            'currency' => 'USD',
            'employer_tax_total' => $employerTaxes / 100,
            'deductions' => $deductions / 100,
            'staffing_accrual_gross_reclassified' => $coveredGrossTotal / 100,
            'staffing_accrual_tax_reclassified' => $coveredEmployerTaxTotal / 100,
            'dimensions' => ['legal_entity' => $entityId],
            'memo' => $memo,
            'lines' => array_values($lines),
        ],
    ], $actorUserId);
    $result = payrollAccountingPostedEventResult($eventResult, 'Payroll accrual');

    $journalCheck=getDB()->prepare('SELECT status,entity_id FROM accounting_journal_entries WHERE tenant_id=? AND id=? FOR UPDATE');
    $journalCheck->execute([$tenantId,(int)$result['je_id']]);
    $journal=$journalCheck->fetch(PDO::FETCH_ASSOC);
    if (!$journal || $journal['status']!=='posted' || (int)$journal['entity_id']!==$entityId) {
        throw new RuntimeException('The payroll accrual journal is no longer posted. Review its correction before retrying.',409);
    }

    getDB()->prepare(
        'UPDATE payroll_runs SET journal_entry_id = :je, updated_at = NOW()
          WHERE tenant_id = :t AND id = :id'
    )->execute(['je' => (int) $result['je_id'], 't' => $tenantId, 'id' => $runId]);
    payrollAccountingStampLink($tenantId, $runId, (int) $result['je_id'], 'accrual', true);
    payrollAudit('payroll.run.posted', [
        'run_id' => $runId,
        'journal_entry_id' => (int) $result['je_id'],
        'leg' => 'accrual',
        'idempotent_replay' => !empty($result['idempotent_replay']),
    ], $runId);

    return $result;
}

function payrollPostRunCash(int $tenantId, int $runId, ?int $actorUserId): array
{
    $run = payrollAccountingLoadRun($tenantId, $runId);
    if ((string) $run['status'] !== 'paid') {
        throw new \RuntimeException('Payroll cash can only post after the run is marked paid');
    }
    $settings = payrollAccountingRequireReady($tenantId, $run);
    $net = (int) $run['net_total_cents'];
    if ($net <= 0) throw new \RuntimeException('Payroll net pay must be greater than zero');

    $memo = 'Payroll run #' . $runId . ' · net pay disbursed';
    $cashAccountId = payrollAccountingAccountId($tenantId, (string) $settings['payroll_cash_account_code']);
    $entityId = payrollAccountingRunEntityId($tenantId, $run);
    $dimensions = ['legal_entity' => $entityId];
    $eventResult = accountingProcessEvent($tenantId, [
        'entity_id' => $entityId,
        'event_type' => 'payroll.cash.disbursed',
        'source_module' => 'payroll',
        'source_record_id' => 'payroll_run:' . $runId . ':cash',
        'event_date' => (string) ($run['pay_date'] ?: date('Y-m-d')),
        'payload' => [
            'run_id' => $runId,
            'amount' => $net / 100,
            'currency' => 'USD',
            'bank_account_id' => $cashAccountId,
            'method' => (string) ($run['disbursement_rail'] ?? 'internal'),
            'bank_ref' => $run['rail_external_ref'] ?? null,
            'dimensions' => $dimensions,
            'memo' => $memo,
            'lines' => [
                [
                    'account_code' => (string) $settings['payroll_payable_account_code'],
                    'debit' => $net / 100,
                    'credit' => 0,
                    'memo' => $memo,
                    'dims' => $dimensions,
                ],
                [
                    'account_code' => (string) $settings['payroll_cash_account_code'],
                    'debit' => 0,
                    'credit' => $net / 100,
                    'memo' => $memo,
                    'dims' => $dimensions,
                ],
            ],
        ],
    ], $actorUserId);
    $result = payrollAccountingPostedEventResult($eventResult, 'Payroll cash disbursement');

    getDB()->prepare(
        'UPDATE payroll_runs SET cash_journal_entry_id = :je, updated_at = NOW()
          WHERE tenant_id = :t AND id = :id'
    )->execute(['je' => (int) $result['je_id'], 't' => $tenantId, 'id' => $runId]);
    payrollAccountingStampLink($tenantId, $runId, (int) $result['je_id'], 'cash');
    payrollAudit('payroll.run.posted', [
        'run_id' => $runId,
        'journal_entry_id' => (int) $result['je_id'],
        'leg' => 'cash',
        'idempotent_replay' => !empty($result['idempotent_replay']),
    ], $runId);

    return $result;
}

function payrollAccountingLoadRun(int $tenantId, int $runId): array
{
    $stmt = getDB()->prepare(
        'SELECT r.*, pp.period_start, pp.period_end, pp.pay_date
           FROM payroll_runs r
           JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
          WHERE r.tenant_id = :t AND r.id = :id
          LIMIT 1'.(getDB()->inTransaction() ? ' FOR UPDATE' : '')
    );
    $stmt->execute(['t' => $tenantId, 'id' => $runId]);
    $run = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$run) throw new \RuntimeException('Payroll run not found');
    return $run;
}
