<?php
/**
 * /app/modules/payroll/lib/csv_import.php
 *
 * Upload a payroll register CSV (one row per employee for a single
 * pay period) and create a corresponding `payroll_runs` row +
 * `payroll_line_items` rows for each employee. Mirrors the shape
 * the Gusto sync produces so the existing
 * approval / GL-post / reporting flows work without changes.
 *
 * CSV format (header row required). Aliases are case-insensitive
 * and tolerate `_`, ` `, and special chars:
 *   employee_id | employee_email | employee_name  (one of)
 *   work_state                                    (2-char, defaults to settings default)
 *   pay_type    salary | hourly                   (defaults to 'salary')
 *   pay_rate                                       (dollars; converted to cents)
 *   pay_frequency  weekly|biweekly|semimonthly|monthly  (defaults to 'biweekly')
 *   hours_regular | hours_overtime               (decimals, optional)
 *   gross | gross_pay                              (dollars)
 *   employee_taxes | taxes                         (dollars)
 *   pretax_deductions | pretax                     (dollars, optional)
 *   posttax_deductions | posttax                   (dollars, optional)
 *   net | net_pay                                  (dollars)
 *   employer_taxes                                 (dollars, optional)
 *
 * The importer:
 *   1. Verifies the pay_period_id belongs to the tenant.
 *   2. Creates ONE payroll_runs row in status='computed' with the
 *      run_type provided (default 'regular').
 *   3. Inserts one payroll_line_items row per employee row.
 *   4. Aggregates totals back onto the payroll_runs row.
 *   5. Returns counters + the new run_id.
 *
 * Employees are matched by id → email → name lookup against the
 * `people` table (or whichever employee source the tenant uses).
 * Rows whose employee can't be resolved are skipped + logged.
 *
 *   Returns:
 *   {
 *     run_id, rows_seen, rows_inserted, rows_skipped,
 *     totals: {gross_cents, taxes_cents, deductions_cents, net_cents, employer_taxes_cents},
 *     errors:[...up to 20...]
 *   }
 */
declare(strict_types=1);

function payrollCsvFindColumn(array $headers, array $aliases): ?int
{
    foreach ($headers as $i => $h) {
        $norm = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $h));
        foreach ($aliases as $a) {
            $aNorm = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $a));
            if ($norm === $aNorm) return (int) $i;
        }
    }
    return null;
}

function payrollCsvParseDollarsToCents(?string $raw): ?int
{
    if ($raw === null) return null;
    $raw = trim($raw);
    if ($raw === '') return null;
    $isNegParen = (strlen($raw) >= 2 && $raw[0] === '(' && substr($raw, -1) === ')');
    if ($isNegParen) $raw = substr($raw, 1, -1);
    $raw = preg_replace('/[\$£€,]/', '', $raw) ?? '';
    if ($raw === '' || $raw === '-') return null;
    if (!is_numeric($raw)) return null;
    $cents = (int) round(((float) $raw) * 100);
    return $isNegParen ? -abs($cents) : $cents;
}

function payrollResolveEmployeeId(PDO $pdo, int $tenantId, ?string $idRaw, ?string $email, ?string $name): ?int
{
    if ($idRaw !== null && ctype_digit($idRaw) && (int) $idRaw > 0) {
        $st = $pdo->prepare('SELECT id FROM people WHERE id = :id AND tenant_id = :t LIMIT 1');
        $st->execute(['id' => (int) $idRaw, 't' => $tenantId]);
        $id = $st->fetchColumn();
        if ($id) return (int) $id;
    }
    if ($email !== null && $email !== '') {
        $st = $pdo->prepare(
            'SELECT id FROM people
              WHERE tenant_id = :t
                AND (email_primary = :e1 OR email_secondary = :e2)
              LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'e1' => strtolower($email), 'e2' => strtolower($email)]);
        $id = $st->fetchColumn();
        if ($id) return (int) $id;
    }
    if ($name !== null && $name !== '') {
        // Try first+last split.
        $parts = preg_split('/\s+/', trim($name), 2);
        if (is_array($parts) && count($parts) === 2) {
            $st = $pdo->prepare(
                'SELECT id FROM people
                  WHERE tenant_id = :t
                    AND LOWER(first_name) = LOWER(:f) AND LOWER(last_name) = LOWER(:l)
                  LIMIT 1'
            );
            $st->execute(['t' => $tenantId, 'f' => $parts[0], 'l' => $parts[1]]);
            $id = $st->fetchColumn();
            if ($id) return (int) $id;
        }
    }
    return null;
}

function payrollImportRunCsv(
    PDO $pdo,
    int $tenantId,
    int $payPeriodId,
    string $filePath,
    string $runType = 'regular'
): array {
    $summary = [
        'run_id'         => null,
        'rows_seen'      => 0,
        'rows_inserted'  => 0,
        'rows_skipped'   => 0,
        'totals'         => [
            'gross_cents'          => 0,
            'taxes_cents'          => 0,
            'deductions_cents'     => 0,
            'net_cents'            => 0,
            'employer_taxes_cents' => 0,
        ],
        'errors'         => [],
    ];
    if ($tenantId <= 0 || $payPeriodId <= 0) {
        $summary['errors'][] = 'tenant_id + pay_period_id are required';
        return $summary;
    }
    if (!in_array($runType, ['regular', 'off_cycle', 'correction', 'final'], true)) {
        $summary['errors'][] = "invalid run_type '{$runType}'";
        return $summary;
    }
    if (!is_readable($filePath)) {
        $summary['errors'][] = 'csv file not readable';
        return $summary;
    }

    // Verify pay_period belongs to this tenant.
    $chk = $pdo->prepare('SELECT id FROM payroll_pay_periods WHERE id = :id AND tenant_id = :t LIMIT 1');
    $chk->execute(['id' => $payPeriodId, 't' => $tenantId]);
    if (!$chk->fetchColumn()) {
        $summary['errors'][] = "pay_period_id {$payPeriodId} not found for this tenant";
        return $summary;
    }

    $h = fopen($filePath, 'rb');
    if (!$h) { $summary['errors'][] = 'fopen failed'; return $summary; }
    $headers = fgetcsv($h);
    if (!is_array($headers) || empty($headers)) {
        fclose($h);
        $summary['errors'][] = 'no header row';
        return $summary;
    }
    if (isset($headers[0]) && str_starts_with((string) $headers[0], "\xEF\xBB\xBF")) {
        $headers[0] = substr((string) $headers[0], 3);
    }

    $idCol     = payrollCsvFindColumn($headers, ['employee_id', 'employeeid', 'emp_id']);
    $emailCol  = payrollCsvFindColumn($headers, ['employee_email', 'email']);
    $nameCol   = payrollCsvFindColumn($headers, ['employee_name', 'name', 'full_name']);
    $stateCol  = payrollCsvFindColumn($headers, ['work_state', 'state']);
    $typeCol   = payrollCsvFindColumn($headers, ['pay_type', 'type']);
    $rateCol   = payrollCsvFindColumn($headers, ['pay_rate', 'rate']);
    $freqCol   = payrollCsvFindColumn($headers, ['pay_frequency', 'frequency']);
    $hRegCol   = payrollCsvFindColumn($headers, ['hours_regular', 'regular_hours']);
    $hOtCol    = payrollCsvFindColumn($headers, ['hours_overtime', 'overtime_hours']);
    $grossCol  = payrollCsvFindColumn($headers, ['gross', 'gross_pay']);
    $taxCol    = payrollCsvFindColumn($headers, ['employee_taxes', 'taxes']);
    $preCol    = payrollCsvFindColumn($headers, ['pretax_deductions', 'pretax']);
    $postCol   = payrollCsvFindColumn($headers, ['posttax_deductions', 'posttax']);
    $netCol    = payrollCsvFindColumn($headers, ['net', 'net_pay']);
    $ertaxCol  = payrollCsvFindColumn($headers, ['employer_taxes', 'er_taxes']);

    if ($idCol === null && $emailCol === null && $nameCol === null) {
        fclose($h);
        $summary['errors'][] = 'CSV needs at least one of: employee_id, employee_email, employee_name';
        return $summary;
    }
    if ($grossCol === null || $netCol === null) {
        fclose($h);
        $summary['errors'][] = 'CSV needs both gross and net pay columns';
        return $summary;
    }

    // Create the payroll_runs row in a single transaction with the
    // line-item inserts so a failure leaves no half-state.
    $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
    $ownsTxn = cf_tx_begin($pdo);
    try {
        $insRun = $pdo->prepare(
            'INSERT INTO payroll_runs
                (tenant_id, pay_period_id, run_type, status, employee_count,
                 gross_total_cents, taxes_total_cents, deductions_total_cents,
                 net_total_cents, employer_taxes_cents, computed_at, created_at)
             VALUES
                (:t, :pp, :rt, "computed", 0, 0, 0, 0, 0, 0, ' . $now . ', ' . $now . ')'
        );
        $insRun->execute(['t' => $tenantId, 'pp' => $payPeriodId, 'rt' => $runType]);
        $runId = (int) $pdo->lastInsertId();

        $insLine = $pdo->prepare(
            'INSERT INTO payroll_line_items
                (tenant_id, run_id, employee_id, work_state, pay_type, pay_rate_cents,
                 pay_frequency, hours_regular, hours_overtime,
                 gross_cents, pretax_cents, taxable_cents,
                 employee_taxes_cents, posttax_cents, net_cents,
                 employer_taxes_cents, payment_method, status, created_at)
             VALUES
                (:t, :run, :emp, :st, :pt, :pr, :pf, :hr, :hot,
                 :g, :pre, :tx, :etax, :post, :n, :er, "direct_deposit", "computed", ' . $now . ')'
        );

        $rowNum = 1;
        while (($row = fgetcsv($h)) !== false) {
            $rowNum++;
            $summary['rows_seen']++;
            if ($row === [null] || $row === false) { $summary['rows_skipped']++; continue; }
            // Skip entirely-empty rows.
            $allEmpty = true;
            foreach ($row as $v) { if (trim((string) $v) !== '') { $allEmpty = false; break; } }
            if ($allEmpty) { $summary['rows_skipped']++; continue; }

            $idRaw   = $idCol    !== null ? trim((string) ($row[$idCol]    ?? '')) : null;
            $email   = $emailCol !== null ? strtolower(trim((string) ($row[$emailCol] ?? ''))) : null;
            $name    = $nameCol  !== null ? trim((string) ($row[$nameCol]  ?? '')) : null;
            $empId = payrollResolveEmployeeId($pdo, $tenantId, $idRaw, $email, $name);
            if ($empId === null) {
                $summary['rows_skipped']++;
                if (count($summary['errors']) < 20) {
                    $summary['errors'][] = "row {$rowNum}: employee not found ('{$idRaw}'/'{$email}'/'{$name}')";
                }
                continue;
            }

            $state = $stateCol !== null ? strtoupper(substr(trim((string) ($row[$stateCol] ?? '')), 0, 2)) : '';
            if (strlen($state) !== 2) $state = 'XX'; // placeholder if unknown — operator can fix later

            $payType = $typeCol !== null ? strtolower(trim((string) ($row[$typeCol] ?? 'salary'))) : 'salary';
            if (!in_array($payType, ['salary', 'hourly'], true)) $payType = 'salary';

            $payRateCents = $rateCol !== null ? (payrollCsvParseDollarsToCents((string) ($row[$rateCol] ?? '')) ?? 0) : 0;

            $freq = $freqCol !== null ? strtolower(trim((string) ($row[$freqCol] ?? 'biweekly'))) : 'biweekly';
            if (!in_array($freq, ['weekly', 'biweekly', 'semimonthly', 'monthly'], true)) $freq = 'biweekly';

            $hReg = $hRegCol !== null ? (float) ($row[$hRegCol] ?? 0) : 0.0;
            $hOt  = $hOtCol  !== null ? (float) ($row[$hOtCol]  ?? 0) : 0.0;

            $grossC = payrollCsvParseDollarsToCents((string) ($row[$grossCol] ?? ''));
            $netC   = payrollCsvParseDollarsToCents((string) ($row[$netCol]   ?? ''));
            if ($grossC === null || $netC === null) {
                $summary['rows_skipped']++;
                if (count($summary['errors']) < 20) {
                    $summary['errors'][] = "row {$rowNum}: missing gross or net";
                }
                continue;
            }
            $taxC   = $taxCol  !== null ? (payrollCsvParseDollarsToCents((string) ($row[$taxCol]  ?? '')) ?? 0) : 0;
            $preC   = $preCol  !== null ? (payrollCsvParseDollarsToCents((string) ($row[$preCol]  ?? '')) ?? 0) : 0;
            $postC  = $postCol !== null ? (payrollCsvParseDollarsToCents((string) ($row[$postCol] ?? '')) ?? 0) : 0;
            $erC    = $ertaxCol!== null ? (payrollCsvParseDollarsToCents((string) ($row[$ertaxCol]?? '')) ?? 0) : 0;

            try {
                $insLine->execute([
                    't'    => $tenantId,
                    'run'  => $runId,
                    'emp'  => $empId,
                    'st'   => $state,
                    'pt'   => $payType,
                    'pr'   => $payRateCents,
                    'pf'   => $freq,
                    'hr'   => $hReg,
                    'hot'  => $hOt,
                    'g'    => $grossC,
                    'pre'  => $preC,
                    'tx'   => max(0, $grossC - $preC), // taxable approximation
                    'etax' => $taxC,
                    'post' => $postC,
                    'n'    => $netC,
                    'er'   => $erC,
                ]);
                $summary['rows_inserted']++;
                $summary['totals']['gross_cents']          += $grossC;
                $summary['totals']['taxes_cents']          += $taxC;
                $summary['totals']['deductions_cents']     += ($preC + $postC);
                $summary['totals']['net_cents']            += $netC;
                $summary['totals']['employer_taxes_cents'] += $erC;
            } catch (\Throwable $e) {
                $summary['rows_skipped']++;
                if (count($summary['errors']) < 20) {
                    $summary['errors'][] = "row {$rowNum}: " . $e->getMessage();
                }
            }
        }

        // Roll up totals onto the run.
        $up = $pdo->prepare(
            'UPDATE payroll_runs
                SET employee_count = :ec,
                    gross_total_cents = :g,
                    taxes_total_cents = :tx,
                    deductions_total_cents = :d,
                    net_total_cents = :n,
                    employer_taxes_cents = :er
              WHERE id = :id AND tenant_id = :t'
        );
        $up->execute([
            'ec' => $summary['rows_inserted'],
            'g'  => $summary['totals']['gross_cents'],
            'tx' => $summary['totals']['taxes_cents'],
            'd'  => $summary['totals']['deductions_cents'],
            'n'  => $summary['totals']['net_cents'],
            'er' => $summary['totals']['employer_taxes_cents'],
            'id' => $runId,
            't'  => $tenantId,
        ]);

        cf_tx_commit($pdo, $ownsTxn);
        $summary['run_id'] = $runId;
    } catch (\Throwable $e) {
        cf_tx_rollback($pdo, $ownsTxn);
        $summary['errors'][] = 'transaction failed: ' . $e->getMessage();
    }

    fclose($h);
    return $summary;
}
