<?php
/**
 * Payroll — Runs API (the heart of payroll)
 *
 * GET                           → list runs (newest first)
 * GET ?id=<n>                   → run detail with line items, earnings, taxes, deductions
 * POST { pay_period_id }        → create a draft run (status='draft')
 * POST { run_id, action='compute' [, hours_overrides[]] }
 *                               → compute / recompute all line items in a run
 * POST { run_id, action='approve' } → mark run approved (and period 'approved')
 * POST { run_id, action='paid' }   → mark run paid
 *
 * Compute is DETERMINISTIC and produces all numbers. AI never participates.
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/payment_rails.php';
require_once __DIR__ . '/../../../core/payment_rails/originate_helpers.php';
require_once __DIR__ . '/../lib/payroll.php';
require_once __DIR__ . '/../lib/compute.php';
require_once __DIR__ . '/../lib/anomalies.php';

$ctx = api_require_auth();
$user = $ctx['user'];

// --------------------------------------------------------------------
// CSV exports — short-circuit before regular GET handler.
//   ?action=export_gusto&id=<run_id>   → Gusto "Run regular payroll" hours-import CSV
//   ?action=export_run&id=<run_id>     → Full pre-calculated audit dump (every line item)
// Streams text/csv with Content-Disposition: attachment.
// Audit-logged via payrollAudit().
// --------------------------------------------------------------------
if (api_method() === 'GET' && in_array($_GET['action'] ?? '', ['export_gusto', 'export_run', 'export_template'], true)) {
    rbac_legacy_require($user, 'payroll.reports.view');
    $runId  = (int) ($_GET['id'] ?? 0);
    $action = (string) $_GET['action'];
    if ($runId <= 0) api_error('id required', 400);

    $detail = _payrollRunDetail($runId);
    if (!$detail['run']) api_error('Run not found', 404);
    $run   = $detail['run'];
    $lines = $detail['lines'];

    // Tenant-defined template export (Gusto Payroll Import preset, custom CSV
    // for a bank portal, etc.). Replaces NACHA fallback per user direction:
    // when Plaid Transfer can't go live, tenants pick a CSV template instead.
    if ($action === 'export_template') {
        require_once __DIR__ . '/../../../core/export_templates.php';
        $tplId = (int) ($_GET['template_id'] ?? 0);
        if (!$tplId) api_error('template_id required', 400);
        try {
            $tpl = exportTemplateGet($tplId, (int) currentTenantId());
        } catch (\Throwable $e) { api_error($e->getMessage(), 404); }
        if ($tpl['dataset'] !== 'payroll_disbursements') {
            api_error("template's dataset must be payroll_disbursements", 422);
        }
        $rows = exportDatasetFetchPayrollDisbursements((int) currentTenantId(), ['run_id' => $runId]);
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $fname = 'payroll-' . preg_replace('/[^A-Za-z0-9_-]/', '-', strtolower($tpl['name']))
               . '-' . $runId . '-' . ($run['pay_date'] ?? date('Ymd')) . '.csv';
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        $out = fopen('php://output', 'w');
        exportTemplateRenderToStream($tplId, $rows, $out, (int) currentTenantId());
        fclose($out);
        payrollAudit('payroll.run.exported_template', [
            'run_id' => $runId, 'template_id' => $tplId, 'rows' => count($rows),
        ], $runId);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $fname = ($action === 'export_gusto' ? 'payroll-gusto-' : 'payroll-run-')
           . $runId . '-' . ($run['pay_date'] ?? date('Ymd')) . '.csv';
    header('Content-Disposition: attachment; filename="' . $fname . '"');

    $out = fopen('php://output', 'w');

    if ($action === 'export_gusto') {
        // Gusto "Run regular payroll → Import hours from CSV" template.
        // Hours-only — Gusto runs the actual gross-to-net.
        $headers = [
            'first_name', 'last_name', 'employee_id',
            'regular_hours', 'overtime_hours', 'double_overtime_hours',
            'holiday_hours', 'pto_hours', 'sick_hours',
            'bonus', 'commission', 'reimbursement',
        ];
        fputcsv($out, $headers);
        foreach ($lines as $l) {
            $bonus = 0.0; $commission = 0.0; $reimbursement = 0.0;
            foreach (($l['earnings'] ?? []) as $e) {
                $cents = (int) ($e['amount_cents'] ?? 0);
                $kind  = strtolower((string) ($e['kind'] ?? ''));
                if (in_array($kind, ['bonus','spot_bonus','signing_bonus'], true))      $bonus         += $cents / 100;
                elseif (in_array($kind, ['commission','referral'], true))                $commission    += $cents / 100;
                elseif (in_array($kind, ['reimbursement','expense'], true))              $reimbursement += $cents / 100;
            }
            fputcsv($out, [
                $l['legal_first_name'] ?? '',
                $l['legal_last_name']  ?? '',
                $l['employee_number']  ?? '',
                number_format((float) ($l['hours_regular']  ?? 0), 2, '.', ''),
                number_format((float) ($l['hours_overtime'] ?? 0), 2, '.', ''),
                '0.00', '0.00', '0.00', '0.00',
                number_format($bonus,         2, '.', ''),
                number_format($commission,    2, '.', ''),
                number_format($reimbursement, 2, '.', ''),
            ]);
        }
    } else {
        // Full pre-calc audit dump — every dollar we calculated, signed.
        $headers = [
            'run_id','pay_date','employee_number','legal_first_name','legal_last_name',
            'work_state','pay_type','pay_rate','pay_frequency',
            'hours_regular','hours_overtime',
            'gross','pretax_deductions','taxable','employee_taxes','posttax_deductions','net',
            'employer_taxes','payment_method','status',
        ];
        fputcsv($out, $headers);
        $cents = static fn($c) => number_format(((int) $c) / 100, 2, '.', '');
        foreach ($lines as $l) {
            fputcsv($out, [
                $runId, $run['pay_date'] ?? '',
                $l['employee_number'] ?? '',
                $l['legal_first_name'] ?? '', $l['legal_last_name'] ?? '',
                $l['work_state'] ?? '', $l['pay_type'] ?? '',
                $cents($l['pay_rate_cents'] ?? 0), $l['pay_frequency'] ?? '',
                number_format((float) ($l['hours_regular']  ?? 0), 2, '.', ''),
                number_format((float) ($l['hours_overtime'] ?? 0), 2, '.', ''),
                $cents($l['gross_cents']          ?? 0),
                $cents($l['pretax_cents']         ?? 0),
                $cents($l['taxable_cents']        ?? 0),
                $cents($l['employee_taxes_cents'] ?? 0),
                $cents($l['posttax_cents']        ?? 0),
                $cents($l['net_cents']            ?? 0),
                $cents($l['employer_taxes_cents'] ?? 0),
                $l['payment_method'] ?? '', $l['status'] ?? '',
            ]);
        }
    }
    fclose($out);
    payrollAudit($action === 'export_gusto' ? 'payroll.run.exported_gusto' : 'payroll.run.exported_csv',
        ['run_id' => $runId, 'rows' => count($lines)], $runId);
    exit;
}

switch (api_method()) {
    case 'GET': {
        rbac_legacy_require($user, 'payroll.view');
        $id = (int) (api_query('id') ?? 0);
        if ($id) {
            api_ok(_payrollRunDetail($id));
        }
        $rows = scopedQuery(
            'SELECT r.*, pp.period_start, pp.period_end, pp.pay_date, pp.schedule_id
             FROM payroll_runs r
             JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
             WHERE r.tenant_id = :tenant_id
             ORDER BY pp.pay_date DESC, r.id DESC LIMIT 50'
        );
        api_ok(['runs' => $rows, 'count' => count($rows)]);
    }

    case 'POST': {
        $body = api_json_body();
        $action = $body['action'] ?? null;

        if (!$action) {
            rbac_legacy_require($user, 'payroll.run.build');
            api_require_fields($body, ['pay_period_id']);
            $period = scopedFind(
                'SELECT * FROM payroll_pay_periods WHERE tenant_id = :tenant_id AND id = :id',
                ['id' => (int) $body['pay_period_id']]
            );
            if (!$period) api_error('Pay period not found', 404);
            $runId = scopedInsert('payroll_runs', [
                'pay_period_id' => (int) $body['pay_period_id'],
                'run_type'      => $body['run_type'] ?? 'regular',
                'status'        => 'draft',
            ]);
            payrollAudit('payroll.run.created', ['run_id' => $runId, 'pay_period_id' => (int) $body['pay_period_id']], $runId);
            api_ok(['id' => $runId], 201);
        }

        $runId = (int) ($body['run_id'] ?? 0);
        if (!$runId) api_error('Missing run_id', 422);
        $run = scopedFind(
            'SELECT * FROM payroll_runs WHERE tenant_id = :tenant_id AND id = :id',
            ['id' => $runId]
        );
        if (!$run) api_error('Run not found', 404);

        if ($action === 'compute') {
            rbac_legacy_require($user, 'payroll.run.build');
            _payrollRequireStatus($run, ['draft', 'computed'], 'Compute');
            _payrollComputeRun($runId, $body['hours_overrides'] ?? []);
            // Auto-run anomaly cross-checks immediately after a successful
            // compute so the run-detail page has fresh findings to surface.
            // Best-effort: never blocks the response.
            try { payrollAnomaliesDetect($runId, false); }
            catch (\Throwable $e) { error_log('[payroll.runs] anomaly detect skipped: ' . $e->getMessage()); }
            payrollAudit('payroll.run.built', ['run_id' => $runId, 'action' => 'compute'], $runId);
            api_ok(_payrollRunDetail($runId));
        }
        if ($action === 'approve') {
            rbac_legacy_require($user, 'payroll.run.approve');
            _payrollRequireStatus($run, ['computed'], 'Approve');
            _payrollDenyBuildApproveSameActor($runId, $user);
            scopedUpdate('payroll_runs', $runId, [
                'status'       => 'approved',
                'approved_at'  => date('Y-m-d H:i:s'),
                'approved_by'  => $user['id'] ?? null,
            ]);
            scopedUpdate('payroll_line_items', 0, []); // no-op for type-check; per-row updates below:
            $pdo = getDB();
            if ($pdo) {
                $stmt = $pdo->prepare(
                    "UPDATE payroll_line_items SET status='approved', updated_at=NOW()
                     WHERE tenant_id = :tenant_id AND run_id = :rid"
                );
                $stmt->execute(['tenant_id' => currentTenantId(), 'rid' => $runId]);
            }
            scopedUpdate('payroll_pay_periods', (int) $run['pay_period_id'], ['status' => 'approved']);
            payrollAudit('payroll.run.approved', ['run_id' => $runId], $runId);
            api_ok(['ok' => true, 'status' => 'approved']);
        }
        if ($action === 'paid') {
            rbac_legacy_require($user, 'payroll.run.disburse');
            _payrollRequireStatus($run, ['approved'], 'Mark paid');
            _payrollDenySameActor((int) ($run['approved_by'] ?? 0), $user, 'Approver cannot mark the same payroll run paid');
            scopedUpdate('payroll_runs', $runId, [
                'status'  => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
            ]);
            $pdo = getDB();
            if ($pdo) {
                $stmt = $pdo->prepare(
                    "UPDATE payroll_line_items SET status='paid', updated_at=NOW()
                     WHERE tenant_id = :tenant_id AND run_id = :rid"
                );
                $stmt->execute(['tenant_id' => currentTenantId(), 'rid' => $runId]);
            }
            scopedUpdate('payroll_pay_periods', (int) $run['pay_period_id'], ['status' => 'paid']);
            payrollAudit('payroll.run.marked_paid', ['run_id' => $runId], $runId);
            api_ok(['ok' => true, 'status' => 'paid']);
        }

        // ----------------------------------------------------------------
        // Originate disbursement through paymentRails (NACHA / Plaid Transfer).
        // Builds RailItems from each line_item with payment_method='direct_deposit'
        // + the employee's primary active bank account, dispatches the batch,
        // persists rail_external_ref / rail_status on the run.
        // Two-eye: requires payroll.run.disburse, run must be approved, not yet
        // originated. Emits audit `payroll.run.originated`.
        // ----------------------------------------------------------------
        if ($action === 'originate') {
            rbac_legacy_require($user, 'payroll.run.disburse');
            if ($run['status'] !== 'approved') api_error('Originate requires status=approved', 409);
            _payrollDenySameActor((int) ($run['approved_by'] ?? 0), $user, 'Approver cannot originate disbursements for the same payroll run');
            if (!empty($run['rail_external_ref'])) {
                api_error('Already originated on rail ' . $run['disbursement_rail'], 409);
            }

            $pdo = getDB();
            // Pull line items + each employee's primary active bank account.
            $linesStmt = $pdo->prepare(
                "SELECT li.id, li.employee_id, li.net_cents, li.payment_method,
                        e.first_name, e.last_name, e.id AS emp_id,
                        b.id AS bank_id, b.routing_cipher, b.account_cipher, b.account_type
                 FROM payroll_line_items li
                 JOIN people_employees e ON e.id = li.employee_id AND e.tenant_id = li.tenant_id
                 LEFT JOIN people_bank_accounts b ON b.tenant_id = li.tenant_id
                          AND b.employee_id = li.employee_id
                          AND b.status = 'active'
                          AND b.priority = (SELECT MIN(priority) FROM people_bank_accounts
                                             WHERE tenant_id = li.tenant_id AND employee_id = li.employee_id
                                             AND status='active')
                 WHERE li.tenant_id = :t AND li.run_id = :rid AND li.status = 'approved'
                 ORDER BY li.id"
            );
            $linesStmt->execute(['t' => currentTenantId(), 'rid' => $runId]);
            $lines = $linesStmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!$lines) api_error('No approved line items for this run', 422);

            $items = [];
            $skipped = [];
            foreach ($lines as $ln) {
                if ($ln['payment_method'] !== 'direct_deposit') {
                    $skipped[] = ['employee_id' => (int) $ln['employee_id'], 'reason' => 'check'];
                    continue;
                }
                if (empty($ln['bank_id'])) {
                    $skipped[] = ['employee_id' => (int) $ln['employee_id'], 'reason' => 'no_active_bank_account'];
                    continue;
                }
                if ((int) $ln['net_cents'] <= 0) {
                    $skipped[] = ['employee_id' => (int) $ln['employee_id'], 'reason' => 'zero_or_negative_net'];
                    continue;
                }
                try {
                    $bank = paymentRailsDecryptBank(
                        $ln['routing_cipher'], $ln['account_cipher'],
                        'employee ' . $ln['first_name'] . ' ' . $ln['last_name']
                    );
                    $items[] = paymentRailsBuildItem([
                        'external_ref'   => 'payroll_line:' . $ln['id'],
                        'recipient_name' => trim(((string) $ln['first_name']) . ' ' . ((string) $ln['last_name'])),
                        'routing'        => $bank['routing'],
                        'account'        => $bank['account'],
                        'account_type'   => $ln['account_type'] ?: 'checking',
                        'amount_cents'   => (int) $ln['net_cents'],
                        'sec_code'       => 'ppd',  // W-2 employees → consumer credits
                        'description'    => 'PAYROLL',
                    ]);
                } catch (PaymentRailsOriginateException $e) {
                    $skipped[] = ['employee_id' => (int) $ln['employee_id'], 'reason' => $e->getMessage()];
                }
            }
            if (!$items) api_error('No bankable line items', 422, ['skipped' => $skipped]);

            $settings = scopedFind('SELECT * FROM payroll_settings WHERE tenant_id = :tenant_id LIMIT 1') ?: [];
            try {
                $res = paymentRailsDispatch('payroll', $run, $settings, $items);
            } catch (PaymentRailsOriginateException $e) {
                payrollAudit('payroll.run.originate_failed', ['run_id' => $runId, 'error' => $e->getMessage()], $runId);
                api_error($e->getMessage(), 422);
            }

            scopedUpdate('payroll_runs', $runId, [
                'disbursement_rail'  => $res['rail'],
                'rail_external_ref'  => $res['batch_id'],
                'rail_status'        => $res['status'],
                'rail_originated_at' => date('Y-m-d H:i:s'),
            ]);
            payrollAudit('payroll.run.originated', [
                'run_id'  => $runId,
                'rail'    => $res['rail'],
                'batch_id'=> $res['batch_id'],
                'status'  => $res['status'],
                'item_count' => count($items),
                'skipped_count' => count($skipped),
            ], $runId);

            $resp = [
                'ok'          => true,
                'rail'        => $res['rail'],
                'batch_id'    => $res['batch_id'],
                'status'      => $res['status'],
                'item_count'  => count($items),
                'skipped'     => $skipped,
            ];
            if ($res['rail'] === 'nacha' && !empty($res['payload']['content'])) {
                $resp['nacha_file_b64'] = base64_encode((string) $res['payload']['content']);
                $resp['nacha_filename'] = $res['payload']['filename'] ?? null;
            }
            api_ok($resp);
        }

        // ----------------------------------------------------------------
        // Gusto sync polish — track the round-trip after CSV upload.
        //   mark_gusto_synced { run_id, gusto_run_id, gusto_payroll_url? }
        //   mark_gusto_paid   { run_id }   — Gusto reports the run as paid
        //   unlink_gusto      { run_id }   — undo (e.g. wrong ID pasted)
        // ----------------------------------------------------------------
        if ($action === 'mark_gusto_synced') {
            rbac_legacy_require($user, 'payroll.run.disburse');
            _payrollRequireStatus($run, ['approved'], 'Mark Gusto synced');
            api_require_fields($body, ['gusto_run_id']);
            $gid = trim((string) $body['gusto_run_id']);
            if ($gid === '') api_error('gusto_run_id required', 422);
            $url = isset($body['gusto_payroll_url']) ? trim((string) $body['gusto_payroll_url']) : null;
            if ($url !== null && $url !== '' && !preg_match('#^https?://#i', $url)) {
                api_error('gusto_payroll_url must be http(s)', 422);
            }
            scopedUpdate('payroll_runs', $runId, [
                'gusto_run_id'      => $gid,
                'gusto_payroll_url' => $url ?: null,
                'gusto_status'      => 'submitted',
                'gusto_synced_at'   => date('Y-m-d H:i:s'),
                'gusto_synced_by'   => $ctx['user']['id'] ?? null,
            ]);
            payrollAudit('payroll.run.gusto_synced',
                ['gusto_run_id' => $gid, 'has_url' => $url ? true : false], $runId);
            api_ok(['ok' => true, 'gusto_status' => 'submitted', 'gusto_run_id' => $gid]);
        }
        if ($action === 'mark_gusto_paid') {
            rbac_legacy_require($user, 'payroll.run.disburse');
            _payrollDenySameActor((int) ($run['approved_by'] ?? 0), $user, 'Approver cannot mark the same Gusto payroll run paid');
            if (empty($run['gusto_run_id'])) api_error('Run is not linked to Gusto', 409);
            scopedUpdate('payroll_runs', $runId, [
                'gusto_status' => 'paid',
                'gusto_paid_at'=> date('Y-m-d H:i:s'),
            ]);
            // Mirror to local status so the rest of the UI reflects "paid".
            // Local paid_at is left untouched if already set; otherwise we
            // stamp it here so reports / aging / dashboards see this run as
            // paid without us double-posting cash movement (Gusto did it).
            if ($run['status'] !== 'paid') {
                scopedUpdate('payroll_runs', $runId, [
                    'status'  => 'paid',
                    'paid_at' => $run['paid_at'] ?: date('Y-m-d H:i:s'),
                ]);
                $pdo = getDB();
                if ($pdo) {
                    $stmt = $pdo->prepare(
                        "UPDATE payroll_line_items SET status='paid', updated_at=NOW()
                         WHERE tenant_id = :tenant_id AND run_id = :rid"
                    );
                    $stmt->execute(['tenant_id' => currentTenantId(), 'rid' => $runId]);
                }
                scopedUpdate('payroll_pay_periods', (int) $run['pay_period_id'], ['status' => 'paid']);
            }
            payrollAudit('payroll.run.gusto_marked_paid',
                ['gusto_run_id' => $run['gusto_run_id']], $runId);
            api_ok(['ok' => true, 'gusto_status' => 'paid', 'status' => 'paid']);
        }
        if ($action === 'unlink_gusto') {
            rbac_legacy_require($user, 'payroll.run.disburse');
            if (empty($run['gusto_run_id'])) api_error('Run is not linked to Gusto', 409);
            scopedUpdate('payroll_runs', $runId, [
                'gusto_run_id'      => null,
                'gusto_payroll_url' => null,
                'gusto_status'      => null,
                'gusto_synced_at'   => null,
                'gusto_synced_by'   => null,
                'gusto_paid_at'     => null,
            ]);
            payrollAudit('payroll.run.gusto_unlinked',
                ['previous_gusto_run_id' => $run['gusto_run_id']], $runId);
            api_ok(['ok' => true]);
        }

        api_error('Unknown action', 422);
    }
}

api_error('Method not allowed', 405);


// =========================================================================
// Helpers
// =========================================================================

function _payrollRunDetail(int $runId): array {
    $run = scopedFind(
        'SELECT r.*, pp.schedule_id, pp.period_start, pp.period_end, pp.pay_date
         FROM payroll_runs r
         JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
         WHERE r.tenant_id = :tenant_id AND r.id = :id',
        ['id' => $runId]
    );
    if (!$run) return ['run' => null, 'lines' => []];

    $lines = scopedQuery(
        "SELECT li.*,
                e.legal_first_name, e.legal_last_name, e.preferred_name, e.employee_number
         FROM payroll_line_items li
         JOIN people_employees e
           ON e.id = li.employee_id AND e.tenant_id = li.tenant_id
         WHERE li.tenant_id = :tenant_id AND li.run_id = :rid
         ORDER BY e.legal_last_name, e.legal_first_name",
        ['rid' => $runId]
    );

    // Pull components for all line ids in one shot
    $lineIds = array_map(fn($l) => (int) $l['id'], $lines);
    $earnByLine = $taxByLine = $dedByLine = [];
    if ($lineIds) {
        $in = implode(',', array_map('intval', $lineIds));
        $pdo = getDB();
        if ($pdo) {
            foreach ($pdo->query("SELECT * FROM payroll_earnings WHERE line_item_id IN ($in)") as $r) {
                $earnByLine[(int) $r['line_item_id']][] = $r;
            }
            foreach ($pdo->query("SELECT * FROM payroll_taxes WHERE line_item_id IN ($in)") as $r) {
                $taxByLine[(int) $r['line_item_id']][] = $r;
            }
            foreach ($pdo->query("SELECT * FROM payroll_deductions WHERE line_item_id IN ($in)") as $r) {
                $dedByLine[(int) $r['line_item_id']][] = $r;
            }
        }
    }
    foreach ($lines as &$l) {
        $lid = (int) $l['id'];
        $l['earnings']   = $earnByLine[$lid] ?? [];
        $l['taxes']      = $taxByLine[$lid]  ?? [];
        $l['deductions'] = $dedByLine[$lid]  ?? [];
    }
    unset($l);

    return ['run' => $run, 'lines' => $lines];
}

function _payrollRequireStatus(array $run, array $allowed, string $verb): void {
    $status = (string) ($run['status'] ?? '');
    if (!in_array($status, $allowed, true)) {
        api_error($verb . ' requires status ' . implode(' or ', $allowed), 409, ['status' => $status]);
    }
}

function _payrollDenyBuildApproveSameActor(int $runId, array $user): void {
    $actorId = _payrollLatestBuildActor($runId);
    if ($actorId !== null) {
        _payrollDenySameActor($actorId, $user, 'Builder cannot approve the same payroll run');
    }
}

function _payrollLatestBuildActor(int $runId): ?int {
    try {
        $row = scopedFind(
            "SELECT actor_user_id
               FROM audit_log
              WHERE tenant_id = :tenant_id
                AND target_id = :rid
                AND event IN ('payroll.run.built', 'payroll.run.created')
              ORDER BY created_at DESC, id DESC
              LIMIT 1",
            ['rid' => $runId]
        );
        if (!$row || empty($row['actor_user_id'])) return null;
        return (int) $row['actor_user_id'];
    } catch (\Throwable $e) {
        error_log('[payroll.runs] SoD build actor lookup skipped: ' . $e->getMessage());
        return null;
    }
}

function _payrollDenySameActor(int $blockedActorId, array $user, string $message): void {
    if ($blockedActorId > 0 && $blockedActorId === (int) ($user['id'] ?? 0)) {
        api_error('Two-eye control: ' . $message, 403);
    }
}

/**
 * Compute every employee in the run's schedule. Idempotent: deletes prior
 * line items for this run, then re-creates them from current data.
 *
 * $hoursOverrides: optional array keyed by employee_id with
 *   [ 'hours_regular' => float, 'hours_overtime' => float, 'bonus_cents' => int ]
 */
function _payrollComputeRun(int $runId, array $hoursOverrides = []): void {
    $tenant = currentTenantId();
    $run = scopedFind(
        'SELECT r.*, pp.schedule_id, pp.period_start, pp.period_end, pp.pay_date
         FROM payroll_runs r
         JOIN payroll_pay_periods pp ON pp.id = r.pay_period_id AND pp.tenant_id = r.tenant_id
         WHERE r.tenant_id = :tenant_id AND r.id = :id',
        ['id' => $runId]
    );
    if (!$run) throw new RuntimeException('Run not found');
    $period = [
        'period_start' => $run['period_start'],
        'period_end'   => $run['period_end'],
        'pay_date'     => $run['pay_date'],
    ];

    $settings = payrollGetTenantSettings();
    $emps = payrollEmployeesForSchedule((int) $run['schedule_id']);

    $pdo = getDB();
    if (!$pdo) throw new RuntimeException('No database');

    $pdo->beginTransaction();
    try {
        // Wipe previous line items + components for this run (idempotent recompute)
        $oldLines = $pdo->prepare(
            'SELECT id FROM payroll_line_items WHERE tenant_id = :t AND run_id = :r'
        );
        $oldLines->execute(['t' => $tenant, 'r' => $runId]);
        $oldIds = array_map(fn($r) => (int) $r['id'], $oldLines->fetchAll());
        if ($oldIds) {
            $in = implode(',', array_map('intval', $oldIds));
            $pdo->exec("DELETE FROM payroll_earnings   WHERE line_item_id IN ($in)");
            $pdo->exec("DELETE FROM payroll_taxes      WHERE line_item_id IN ($in)");
            $pdo->exec("DELETE FROM payroll_deductions WHERE line_item_id IN ($in)");
            $pdo->exec("DELETE FROM payroll_line_items WHERE id IN ($in)");
        }

        $totals = ['gross'=>0,'taxes'=>0,'ded'=>0,'net'=>0,'er'=>0,'count'=>0];
        foreach ($emps as $e) {
            $empId = (int) $e['employee_id'];
            $extras = $hoursOverrides[$empId] ?? [];
            $cctx = payrollBuildComputeContext($empId, $period, $settings, $extras);
            if (!$cctx) continue; // skip employees without comp/tax/profile

            $result = payrollComputeLine($cctx);

            $lineId = scopedInsert('payroll_line_items', [
                'run_id'              => $runId,
                'employee_id'         => $empId,
                'work_state'          => $cctx['work_state'],
                'pay_type'            => $cctx['pay_type'],
                'pay_rate_cents'      => $cctx['pay_rate_cents'],
                'pay_frequency'       => $cctx['pay_frequency'],
                'hours_regular'       => $cctx['hours_regular'],
                'hours_overtime'      => $cctx['hours_overtime'],
                'gross_cents'         => $result['gross_cents'],
                'pretax_cents'        => $result['pretax_cents'],
                'taxable_cents'       => $result['taxable_cents'],
                'employee_taxes_cents'=> $result['employee_taxes_cents'],
                'posttax_cents'       => $result['posttax_cents'],
                'net_cents'           => $result['net_cents'],
                'employer_taxes_cents'=> $result['employer_taxes_cents'],
                'payment_method'      => $e['payment_method'] ?? 'direct_deposit',
                'status'              => 'computed',
            ]);

            foreach ($result['earnings']   as $row) scopedInsert('payroll_earnings',   $row + ['line_item_id' => $lineId]);
            foreach ($result['taxes']      as $row) scopedInsert('payroll_taxes',      $row + ['line_item_id' => $lineId]);
            foreach ($result['deductions'] as $row) scopedInsert('payroll_deductions', $row + ['line_item_id' => $lineId]);

            $totals['gross'] += $result['gross_cents'];
            $totals['taxes'] += $result['employee_taxes_cents'];
            $totals['ded']   += $result['pretax_cents'] + $result['posttax_cents'];
            $totals['net']   += $result['net_cents'];
            $totals['er']    += $result['employer_taxes_cents'];
            $totals['count']++;
        }

        scopedUpdate('payroll_runs', $runId, [
            'status'                 => 'computed',
            'employee_count'         => $totals['count'],
            'gross_total_cents'      => $totals['gross'],
            'taxes_total_cents'      => $totals['taxes'],
            'deductions_total_cents' => $totals['ded'],
            'net_total_cents'        => $totals['net'],
            'employer_taxes_cents'   => $totals['er'],
            'computed_at'            => date('Y-m-d H:i:s'),
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
