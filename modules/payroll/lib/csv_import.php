<?php
/**
 * Payroll register CSV import.
 *
 * The file is validated in full before any run or line item is changed. An
 * existing empty draft for the period is reused; a second regular run is
 * never created accidentally.
 */
declare(strict_types=1);

function payrollCsvFindColumn(array $headers, array $aliases): ?int
{
    foreach ($headers as $i => $header) {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $header));
        foreach ($aliases as $alias) {
            $aliasNormalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $alias));
            if ($normalized === $aliasNormalized) return (int) $i;
        }
    }
    return null;
}

function payrollCsvParseDollarsToCents(?string $raw): ?int
{
    if ($raw === null) return null;
    $raw = trim($raw);
    if ($raw === '') return null;
    $negativeParentheses = strlen($raw) >= 2 && $raw[0] === '(' && substr($raw, -1) === ')';
    if ($negativeParentheses) $raw = substr($raw, 1, -1);
    $raw = preg_replace('/[\$£€,]/', '', $raw) ?? '';
    if ($raw === '' || $raw === '-' || !is_numeric($raw)) return null;
    $cents = (int) round(((float) $raw) * 100);
    return $negativeParentheses ? -abs($cents) : $cents;
}

function payrollCsvNormalizeLookup(?string $value): string
{
    return strtolower(trim((string) preg_replace('/\s+/', ' ', trim((string) $value))));
}

/** @return array{rows: array<int,array>, by_id: array<string,list<int>>, by_number: array<string,list<int>>, by_email: array<string,list<int>>, by_name: array<string,list<int>>} */
function payrollCsvEmployeeDirectory(PDO $pdo, int $tenantId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, employee_number, legal_first_name, preferred_name, legal_last_name,
                work_email, personal_email, status
           FROM people_employees
          WHERE tenant_id = :tenant_id'
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    $directory = ['rows' => [], 'by_id' => [], 'by_number' => [], 'by_email' => [], 'by_name' => []];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $employee) {
        $id = (int) $employee['id'];
        $directory['rows'][$id] = $employee;
        $directory['by_id'][(string) $id][] = $id;

        $number = payrollCsvNormalizeLookup($employee['employee_number'] ?? null);
        if ($number !== '') $directory['by_number'][$number][] = $id;
        foreach (['work_email', 'personal_email'] as $field) {
            $email = payrollCsvNormalizeLookup($employee[$field] ?? null);
            if ($email !== '') $directory['by_email'][$email][] = $id;
        }
        $last = trim((string) ($employee['legal_last_name'] ?? ''));
        foreach ([$employee['legal_first_name'] ?? null, $employee['preferred_name'] ?? null] as $first) {
            $name = payrollCsvNormalizeLookup(trim((string) $first . ' ' . $last));
            if ($name !== '') $directory['by_name'][$name][] = $id;
        }
    }
    foreach (['by_id', 'by_number', 'by_email', 'by_name'] as $index) {
        foreach ($directory[$index] as $key => $ids) {
            $directory[$index][$key] = array_values(array_unique(array_map('intval', $ids)));
        }
    }
    return $directory;
}

/** @return array{employee:?array,error:?string} */
function payrollCsvResolveEmployee(array $directory, array $identifiers): array
{
    $lookups = [
        'employee_id' => 'by_id',
        'employee_number' => 'by_number',
        'employee_email' => 'by_email',
        'employee_name' => 'by_name',
    ];
    $candidateSets = [];
    $missing = [];
    foreach ($lookups as $field => $index) {
        $value = payrollCsvNormalizeLookup($identifiers[$field] ?? null);
        if ($value === '') continue;
        $matches = $directory[$index][$value] ?? [];
        if (!$matches) $missing[] = $field . ' "' . trim((string) $identifiers[$field]) . '"';
        else $candidateSets[] = $matches;
    }
    if (!$candidateSets) {
        return ['employee' => null, 'error' => $missing
            ? 'No employee matched ' . implode(', ', $missing)
            : 'Provide employee_id, employee_number, employee_email, or employee_name'];
    }

    $matches = array_shift($candidateSets);
    foreach ($candidateSets as $set) $matches = array_values(array_intersect($matches, $set));
    if (!$matches) {
        return ['employee' => null, 'error' => 'Employee identifiers refer to different records'];
    }
    if (count($matches) !== 1) {
        return ['employee' => null, 'error' => 'Employee identifiers are ambiguous; add employee_number or employee_id'];
    }
    if ($missing) {
        return ['employee' => null, 'error' => 'Employee match is inconsistent: ' . implode(', ', $missing) . ' was not found'];
    }
    $employee = $directory['rows'][(int) $matches[0]] ?? null;
    return $employee
        ? ['employee' => $employee, 'error' => null]
        : ['employee' => null, 'error' => 'Employee record was not found'];
}

/** Backwards-compatible helper used by smoke tests and older callers. */
function payrollResolveEmployeeId(PDO $pdo, int $tenantId, ?string $idRaw, ?string $email, ?string $name): ?int
{
    $result = payrollCsvResolveEmployee(payrollCsvEmployeeDirectory($pdo, $tenantId), [
        'employee_id' => $idRaw,
        'employee_email' => $email,
        'employee_name' => $name,
    ]);
    return $result['employee'] ? (int) $result['employee']['id'] : null;
}

function payrollCsvCell(array $row, ?int $column): string
{
    return $column === null ? '' : trim((string) ($row[$column] ?? ''));
}

function payrollCsvParseNumber(string $raw, string $label, int $rowNumber, array &$errors): ?float
{
    if ($raw === '') return 0.0;
    $normalized = str_replace(',', '', $raw);
    if (!is_numeric($normalized)) {
        $errors[] = "row {$rowNumber}: {$label} must be numeric";
        return null;
    }
    return (float) $normalized;
}

function payrollCsvAddError(array &$errors, string $message): void
{
    if (count($errors) < 100) $errors[] = $message;
}

/**
 * @return array{run_id:?int,rows_seen:int,rows_inserted:int,rows_skipped:int,totals:array,errors:list<string>,warnings:list<string>,reused_draft:bool}
 */
function payrollImportRunCsv(
    PDO $pdo,
    int $tenantId,
    int $payPeriodId,
    string $filePath,
    string $runType = 'regular',
    ?int $actorUserId = null
): array {
    $summary = [
        'run_id' => null,
        'rows_seen' => 0,
        'rows_inserted' => 0,
        'rows_skipped' => 0,
        'totals' => [
            'gross_cents' => 0,
            'taxes_cents' => 0,
            'deductions_cents' => 0,
            'net_cents' => 0,
            'employer_taxes_cents' => 0,
        ],
        'errors' => [],
        'warnings' => [],
        'reused_draft' => false,
    ];
    if ($tenantId <= 0 || $payPeriodId <= 0) {
        $summary['errors'][] = 'tenant_id and pay_period_id are required';
        return $summary;
    }
    if (!in_array($runType, ['regular', 'off_cycle', 'correction', 'final'], true)) {
        $summary['errors'][] = "invalid run_type '{$runType}'";
        return $summary;
    }
    if (!is_readable($filePath)) {
        $summary['errors'][] = 'CSV file is not readable';
        return $summary;
    }

    $periodStmt = $pdo->prepare(
        'SELECT pp.*, ps.frequency,
                (SELECT COUNT(*) FROM payroll_pay_cycles pc
                  WHERE pc.tenant_id = pp.tenant_id AND pc.schedule_id = pp.schedule_id AND pc.active = 1) AS active_cycle_count
           FROM payroll_pay_periods pp
           JOIN payroll_pay_schedules ps ON ps.id = pp.schedule_id AND ps.tenant_id = pp.tenant_id
          WHERE pp.id = :id AND pp.tenant_id = :tenant_id
          LIMIT 1'
    );
    $periodStmt->execute(['id' => $payPeriodId, 'tenant_id' => $tenantId]);
    $period = $periodStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$period) {
        $summary['errors'][] = "Pay period {$payPeriodId} was not found";
        return $summary;
    }
    if (!in_array((string) ($period['status'] ?? ''), ['draft', 'open'], true)) {
        $summary['errors'][] = 'Payroll can only be imported into a draft or open pay period';
        return $summary;
    }
    $scheduleId = (int) ($period['schedule_id'] ?? 0);
    $cycleId = (int) ($period['cycle_id'] ?? 0);
    $activeCycleCount = (int) ($period['active_cycle_count'] ?? 0);
    if ($cycleId <= 0 && $activeCycleCount > 1) {
        $summary['errors'][] = 'This pay period is not assigned to a pay cycle, but the schedule has multiple active cycles';
        return $summary;
    }

    $existingStmt = $pdo->prepare(
        "SELECT r.id, r.status,
                (SELECT COUNT(*) FROM payroll_line_items li WHERE li.run_id = r.id AND li.tenant_id = r.tenant_id) AS line_count
           FROM payroll_runs r
          WHERE r.tenant_id = :tenant_id AND r.pay_period_id = :period_id
            AND r.run_type = :run_type AND r.status <> 'voided'
          ORDER BY r.id DESC LIMIT 1"
    );
    $existingStmt->execute(['tenant_id' => $tenantId, 'period_id' => $payPeriodId, 'run_type' => $runType]);
    $existingRun = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($existingRun && ((string) $existingRun['status'] !== 'draft' || (int) $existingRun['line_count'] > 0)) {
        $summary['errors'][] = 'A non-empty or already computed ' . $runType . ' run already exists for this pay period';
        return $summary;
    }

    $handle = fopen($filePath, 'rb');
    if (!$handle) {
        $summary['errors'][] = 'Could not open the CSV file';
        return $summary;
    }
    $headers = fgetcsv($handle);
    if (!is_array($headers) || !$headers) {
        fclose($handle);
        $summary['errors'][] = 'CSV file has no header row';
        return $summary;
    }
    if (isset($headers[0]) && str_starts_with((string) $headers[0], "\xEF\xBB\xBF")) {
        $headers[0] = substr((string) $headers[0], 3);
    }

    $columns = [
        'employee_id' => payrollCsvFindColumn($headers, ['employee_id', 'employeeid', 'emp_id']),
        'employee_number' => payrollCsvFindColumn($headers, ['employee_number', 'employee_no', 'employee_num', 'emp_number']),
        'employee_email' => payrollCsvFindColumn($headers, ['employee_email', 'work_email', 'email']),
        'employee_name' => payrollCsvFindColumn($headers, ['employee_name', 'name', 'full_name']),
        'work_state' => payrollCsvFindColumn($headers, ['work_state', 'state']),
        'payment_method' => payrollCsvFindColumn($headers, ['payment_method', 'payment']),
        'pay_type' => payrollCsvFindColumn($headers, ['pay_type', 'type']),
        'pay_rate' => payrollCsvFindColumn($headers, ['pay_rate', 'rate']),
        'pay_frequency' => payrollCsvFindColumn($headers, ['pay_frequency', 'frequency']),
        'hours_regular' => payrollCsvFindColumn($headers, ['hours_regular', 'regular_hours']),
        'hours_overtime' => payrollCsvFindColumn($headers, ['hours_overtime', 'overtime_hours']),
        'gross' => payrollCsvFindColumn($headers, ['gross', 'gross_pay']),
        'employee_taxes' => payrollCsvFindColumn($headers, ['employee_taxes', 'taxes']),
        'pretax_deductions' => payrollCsvFindColumn($headers, ['pretax_deductions', 'pretax']),
        'posttax_deductions' => payrollCsvFindColumn($headers, ['posttax_deductions', 'posttax']),
        'net' => payrollCsvFindColumn($headers, ['net', 'net_pay']),
        'employer_taxes' => payrollCsvFindColumn($headers, ['employer_taxes', 'er_taxes']),
    ];
    if ($columns['employee_id'] === null && $columns['employee_number'] === null
        && $columns['employee_email'] === null && $columns['employee_name'] === null) {
        fclose($handle);
        $summary['errors'][] = 'CSV needs employee_id, employee_number, employee_email, or employee_name';
        return $summary;
    }
    if ($columns['gross'] === null || $columns['net'] === null) {
        fclose($handle);
        $summary['errors'][] = 'CSV needs both gross_pay and net_pay columns';
        return $summary;
    }

    $directory = payrollCsvEmployeeDirectory($pdo, $tenantId);
    $profileStmt = $pdo->prepare(
        'SELECT * FROM payroll_profiles WHERE tenant_id = :tenant_id AND employee_id = :employee_id LIMIT 1'
    );
    $compStmt = $pdo->prepare(
        'SELECT * FROM people_compensation
          WHERE tenant_id = :tenant_id AND employee_id = :employee_id
            AND effective_from <= :period_end
            AND (effective_to IS NULL OR effective_to >= :period_start)
          ORDER BY effective_from DESC, id DESC LIMIT 1'
    );

    $validatedRows = [];
    $seenEmployees = [];
    $rowNumber = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $rowNumber++;
        $allEmpty = true;
        foreach ($row as $value) {
            if (trim((string) $value) !== '') { $allEmpty = false; break; }
        }
        if ($allEmpty) continue;
        $summary['rows_seen']++;

        $identifiers = [];
        foreach (['employee_id', 'employee_number', 'employee_email', 'employee_name'] as $field) {
            $identifiers[$field] = payrollCsvCell($row, $columns[$field]);
        }
        $resolved = payrollCsvResolveEmployee($directory, $identifiers);
        if (!$resolved['employee']) {
            payrollCsvAddError($summary['errors'], "row {$rowNumber}: {$resolved['error']}");
            $summary['rows_skipped']++;
            continue;
        }
        $employee = $resolved['employee'];
        $employeeId = (int) $employee['id'];
        if (isset($seenEmployees[$employeeId])) {
            payrollCsvAddError($summary['errors'], "row {$rowNumber}: employee appears more than once (first seen on row {$seenEmployees[$employeeId]})");
            $summary['rows_skipped']++;
            continue;
        }
        $seenEmployees[$employeeId] = $rowNumber;

        $profileStmt->execute(['tenant_id' => $tenantId, 'employee_id' => $employeeId]);
        $profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$profile || !(int) ($profile['enabled'] ?? 0)) {
            payrollCsvAddError($summary['errors'], "row {$rowNumber}: employee does not have an enabled payroll profile");
            $summary['rows_skipped']++;
            continue;
        }
        if ((int) ($profile['schedule_id'] ?? 0) !== $scheduleId) {
            payrollCsvAddError($summary['errors'], "row {$rowNumber}: employee belongs to a different pay schedule");
            $summary['rows_skipped']++;
            continue;
        }
        $profileCycleId = (int) ($profile['cycle_id'] ?? 0);
        if ($cycleId > 0 && $profileCycleId !== $cycleId && !($profileCycleId === 0 && $activeCycleCount === 1)) {
            payrollCsvAddError($summary['errors'], "row {$rowNumber}: employee belongs to a different pay cycle");
            $summary['rows_skipped']++;
            continue;
        }

        $compStmt->execute([
            'tenant_id' => $tenantId,
            'employee_id' => $employeeId,
            'period_end' => (string) $period['period_end'],
            'period_start' => (string) $period['period_start'],
        ]);
        $compensation = $compStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $stateRaw = strtoupper(payrollCsvCell($row, $columns['work_state']));
        $workState = $stateRaw !== '' ? $stateRaw : strtoupper((string) ($profile['work_state'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/', $workState)) {
            payrollCsvAddError($summary['errors'], "row {$rowNumber}: work_state must be a 2-letter code");
            $summary['rows_skipped']++;
            continue;
        }
        $paymentRaw = strtolower(payrollCsvCell($row, $columns['payment_method']));
        $paymentMethod = $paymentRaw !== '' ? $paymentRaw : (string) ($profile['payment_method'] ?? '');
        if (!in_array($paymentMethod, ['direct_deposit', 'check'], true)) {
            payrollCsvAddError($summary['errors'], "row {$rowNumber}: payment_method must be direct_deposit or check");
            $summary['rows_skipped']++;
            continue;
        }

        $payTypeRaw = strtolower(payrollCsvCell($row, $columns['pay_type']));
        $payType = $payTypeRaw !== '' ? $payTypeRaw : strtolower((string) ($compensation['pay_type'] ?? ''));
        $frequencyRaw = strtolower(payrollCsvCell($row, $columns['pay_frequency']));
        $payFrequency = $frequencyRaw !== '' ? $frequencyRaw : strtolower((string) ($compensation['pay_frequency'] ?? $period['frequency'] ?? ''));
        $rateRaw = payrollCsvCell($row, $columns['pay_rate']);
        $payRateCents = $rateRaw !== '' ? payrollCsvParseDollarsToCents($rateRaw) : ($compensation ? (int) $compensation['pay_rate_cents'] : null);
        if (!in_array($payType, ['salary', 'hourly'], true)) {
            payrollCsvAddError($summary['errors'], "row {$rowNumber}: pay_type is missing or invalid, and no active compensation record supplied it");
            $summary['rows_skipped']++;
            continue;
        }
        if (!in_array($payFrequency, ['weekly', 'biweekly', 'semimonthly', 'monthly'], true)) {
            payrollCsvAddError($summary['errors'], "row {$rowNumber}: pay_frequency is missing or invalid");
            $summary['rows_skipped']++;
            continue;
        }
        if ($payRateCents === null || $payRateCents < 0) {
            payrollCsvAddError($summary['errors'], "row {$rowNumber}: pay_rate is missing or invalid, and no active compensation record supplied it");
            $summary['rows_skipped']++;
            continue;
        }

        $hoursRegular = payrollCsvParseNumber(payrollCsvCell($row, $columns['hours_regular']), 'hours_regular', $rowNumber, $summary['errors']);
        $hoursOvertime = payrollCsvParseNumber(payrollCsvCell($row, $columns['hours_overtime']), 'hours_overtime', $rowNumber, $summary['errors']);
        if ($hoursRegular === null || $hoursOvertime === null || $hoursRegular < 0 || $hoursOvertime < 0) {
            if ($hoursRegular !== null && $hoursOvertime !== null) {
                payrollCsvAddError($summary['errors'], "row {$rowNumber}: hours cannot be negative");
            }
            $summary['rows_skipped']++;
            continue;
        }

        $money = [];
        $moneyFields = [
            'gross_cents' => ['gross', true],
            'employee_taxes_cents' => ['employee_taxes', false],
            'pretax_cents' => ['pretax_deductions', false],
            'posttax_cents' => ['posttax_deductions', false],
            'net_cents' => ['net', true],
            'employer_taxes_cents' => ['employer_taxes', false],
        ];
        $rowMoneyValid = true;
        foreach ($moneyFields as $target => [$source, $required]) {
            $raw = payrollCsvCell($row, $columns[$source]);
            $value = payrollCsvParseDollarsToCents($raw);
            if ($value === null && $required) {
                payrollCsvAddError($summary['errors'], "row {$rowNumber}: {$source} is required and must be numeric");
                $rowMoneyValid = false;
            }
            $money[$target] = $value ?? 0;
        }
        if (!$rowMoneyValid) {
            $summary['rows_skipped']++;
            continue;
        }
        if ($runType !== 'correction' && min($money) < 0) {
            payrollCsvAddError($summary['errors'], "row {$rowNumber}: negative payroll amounts require run_type correction");
            $summary['rows_skipped']++;
            continue;
        }
        $expectedNet = $money['gross_cents'] - $money['pretax_cents']
            - $money['employee_taxes_cents'] - $money['posttax_cents'];
        if (abs($expectedNet - $money['net_cents']) > 1) {
            payrollCsvAddError(
                $summary['errors'],
                "row {$rowNumber}: net pay is out of balance; gross minus deductions and employee taxes must equal net"
            );
            $summary['rows_skipped']++;
            continue;
        }

        $validatedRows[] = [
            'employee_id' => $employeeId,
            'work_state' => $workState,
            'payment_method' => $paymentMethod,
            'pay_type' => $payType,
            'pay_rate_cents' => $payRateCents,
            'pay_frequency' => $payFrequency,
            'hours_regular' => $hoursRegular,
            'hours_overtime' => $hoursOvertime,
            'taxable_cents' => max(0, $money['gross_cents'] - $money['pretax_cents']),
        ] + $money;
    }
    fclose($handle);

    if ($summary['errors']) {
        $summary['rows_skipped'] = max($summary['rows_skipped'], $summary['rows_seen']);
        $summary['warnings'][] = 'No payroll data was changed because the file did not pass validation.';
        return $summary;
    }
    if (!$validatedRows) {
        $summary['errors'][] = 'CSV contains no employee payroll rows';
        return $summary;
    }

    $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
    $ownsTransaction = cf_tx_begin($pdo);
    try {
        if ($existingRun) {
            $runId = (int) $existingRun['id'];
            $summary['reused_draft'] = true;
        } else {
            $insertRun = $pdo->prepare(
                'INSERT INTO payroll_runs
                    (tenant_id, pay_period_id, run_type, created_by_user_id, status,
                     employee_count, gross_total_cents, taxes_total_cents,
                     deductions_total_cents, net_total_cents, employer_taxes_cents,
                     created_at)
                 VALUES
                    (:tenant_id, :period_id, :run_type, :actor_user_id, "draft",
                     0, 0, 0, 0, 0, 0, ' . $now . ')'
            );
            $insertRun->execute([
                'tenant_id' => $tenantId,
                'period_id' => $payPeriodId,
                'run_type' => $runType,
                'actor_user_id' => $actorUserId,
            ]);
            $runId = (int) $pdo->lastInsertId();
        }

        $insertLine = $pdo->prepare(
            'INSERT INTO payroll_line_items
                (tenant_id, run_id, employee_id, work_state, pay_type, pay_rate_cents,
                 pay_frequency, hours_regular, hours_overtime, gross_cents, pretax_cents,
                 taxable_cents, employee_taxes_cents, posttax_cents, net_cents,
                 employer_taxes_cents, payment_method, status, notes, created_at)
             VALUES
                (:tenant_id, :run_id, :employee_id, :work_state, :pay_type, :pay_rate_cents,
                 :pay_frequency, :hours_regular, :hours_overtime, :gross_cents, :pretax_cents,
                 :taxable_cents, :employee_taxes_cents, :posttax_cents, :net_cents,
                 :employer_taxes_cents, :payment_method, "computed", :notes, ' . $now . ')'
        );
        $insertEarning = $pdo->prepare(
            'INSERT INTO payroll_earnings
                (tenant_id, line_item_id, code, hours, rate_cents, amount_cents, taxable, notes, created_at)
             VALUES (:tenant_id, :line_item_id, "regular", :hours, :rate_cents, :amount_cents, 1, :notes, ' . $now . ')'
        );
        $insertDeduction = $pdo->prepare(
            'INSERT INTO payroll_deductions
                (tenant_id, line_item_id, code, is_pretax, amount_cents, notes, created_at)
             VALUES (:tenant_id, :line_item_id, :code, :is_pretax, :amount_cents, :notes, ' . $now . ')'
        );

        foreach ($validatedRows as $validated) {
            $insertLine->execute([
                'tenant_id' => $tenantId,
                'run_id' => $runId,
                'employee_id' => $validated['employee_id'],
                'work_state' => $validated['work_state'],
                'pay_type' => $validated['pay_type'],
                'pay_rate_cents' => $validated['pay_rate_cents'],
                'pay_frequency' => $validated['pay_frequency'],
                'hours_regular' => $validated['hours_regular'],
                'hours_overtime' => $validated['hours_overtime'],
                'gross_cents' => $validated['gross_cents'],
                'pretax_cents' => $validated['pretax_cents'],
                'taxable_cents' => $validated['taxable_cents'],
                'employee_taxes_cents' => $validated['employee_taxes_cents'],
                'posttax_cents' => $validated['posttax_cents'],
                'net_cents' => $validated['net_cents'],
                'employer_taxes_cents' => $validated['employer_taxes_cents'],
                'payment_method' => $validated['payment_method'],
                'notes' => 'Imported payroll register; taxes and gross earnings are aggregate values.',
            ]);
            $lineItemId = (int) $pdo->lastInsertId();
            $insertEarning->execute([
                'tenant_id' => $tenantId,
                'line_item_id' => $lineItemId,
                'hours' => $validated['hours_regular'] + $validated['hours_overtime'],
                'rate_cents' => $validated['pay_rate_cents'],
                'amount_cents' => $validated['gross_cents'],
                'notes' => 'Aggregate gross imported from payroll register.',
            ]);
            if ((int) $validated['pretax_cents'] !== 0) {
                $insertDeduction->execute([
                    'tenant_id' => $tenantId,
                    'line_item_id' => $lineItemId,
                    'code' => 'other_pretax',
                    'is_pretax' => 1,
                    'amount_cents' => $validated['pretax_cents'],
                    'notes' => 'Aggregate pre-tax deductions imported from payroll register.',
                ]);
            }
            if ((int) $validated['posttax_cents'] !== 0) {
                $insertDeduction->execute([
                    'tenant_id' => $tenantId,
                    'line_item_id' => $lineItemId,
                    'code' => 'other_posttax',
                    'is_pretax' => 0,
                    'amount_cents' => $validated['posttax_cents'],
                    'notes' => 'Aggregate post-tax deductions imported from payroll register.',
                ]);
            }

            $summary['totals']['gross_cents'] += $validated['gross_cents'];
            $summary['totals']['taxes_cents'] += $validated['employee_taxes_cents'];
            $summary['totals']['deductions_cents'] += $validated['pretax_cents'] + $validated['posttax_cents'];
            $summary['totals']['net_cents'] += $validated['net_cents'];
            $summary['totals']['employer_taxes_cents'] += $validated['employer_taxes_cents'];
        }

        $updateRun = $pdo->prepare(
            'UPDATE payroll_runs
                SET status = "computed", employee_count = :employee_count,
                    gross_total_cents = :gross, taxes_total_cents = :taxes,
                    deductions_total_cents = :deductions, net_total_cents = :net,
                    employer_taxes_cents = :employer_taxes,
                    computed_at = ' . $now . ', computed_by_user_id = :actor_user_id,
                    updated_at = ' . $now . '
              WHERE id = :run_id AND tenant_id = :tenant_id'
        );
        $updateRun->execute([
            'employee_count' => count($validatedRows),
            'gross' => $summary['totals']['gross_cents'],
            'taxes' => $summary['totals']['taxes_cents'],
            'deductions' => $summary['totals']['deductions_cents'],
            'net' => $summary['totals']['net_cents'],
            'employer_taxes' => $summary['totals']['employer_taxes_cents'],
            'actor_user_id' => $actorUserId,
            'run_id' => $runId,
            'tenant_id' => $tenantId,
        ]);

        cf_tx_commit($pdo, $ownsTransaction);
        $summary['run_id'] = $runId;
        $summary['rows_inserted'] = count($validatedRows);
    } catch (Throwable $e) {
        cf_tx_rollback($pdo, $ownsTransaction);
        $summary['run_id'] = null;
        $summary['rows_inserted'] = 0;
        $summary['errors'][] = 'Payroll register was not imported: ' . $e->getMessage();
    }
    return $summary;
}
