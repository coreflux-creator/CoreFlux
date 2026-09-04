<?php
/**
 * Canonical JobDiva assignment contract proposal.
 *
 * This is the single semantic boundary between JobDiva's Assignment detail
 * and CoreFlux's placement, rate, participant, cycle, and attribution graphs.
 * It is deliberately database-free so preview and apply can evaluate the
 * same facts before any row is written.
 */
declare(strict_types=1);

function jobdivaContractProjectionDate(mixed $value): ?string
{
    if ($value === null || trim((string) $value) === '') return null;
    if (function_exists('jobdivaNormaliseDate')) return jobdivaNormaliseDate($value);
    $timestamp = strtotime((string) $value);
    return $timestamp === false ? null : date('Y-m-d', $timestamp);
}

function jobdivaContractProjectionContract(array $payload): array
{
    if (is_array($payload['_jd_contract'] ?? null)) return $payload['_jd_contract'];
    foreach (['assignment', '_jd_start'] as $key) {
        $candidate = $payload[$key] ?? null;
        if (is_array($candidate) && !array_is_list($candidate)
            && (($candidate['source'] ?? '') === 'EmployeeAssignmentRecordsDetail'
                || isset($candidate['contract_version']))) {
            return $candidate;
        }
    }
    if (is_array($payload['_jd_assignment_detail'] ?? null)
        && function_exists('jobdivaAssignmentContractBuild')) {
        $startId = function_exists('jobdivaAssignmentRowId')
            ? jobdivaAssignmentRowId($payload)
            : (string) ($payload['startId'] ?? $payload['start_id'] ?? '');
        return jobdivaAssignmentContractBuild(
            $payload['_jd_assignment_detail'],
            $payload,
            (string) $startId
        );
    }
    return [];
}

function jobdivaContractProjectionScalar(array $payload, array $keys): string
{
    if (function_exists('jobdivaPluckFieldDeep')) {
        return trim((string) jobdivaPluckFieldDeep($payload, $keys));
    }
    foreach ($keys as $key) {
        if (array_key_exists($key, $payload) && is_scalar($payload[$key])) {
            $value = trim((string) $payload[$key]);
            if ($value !== '') return $value;
        }
    }
    return '';
}

function jobdivaContractProjectionAmount(mixed $value): ?float
{
    if (function_exists('jobdivaAssignmentContractAmount')) {
        return jobdivaAssignmentContractAmount($value);
    }
    if ($value === null || trim((string) $value) === '') return null;
    $clean = str_replace([',', '$'], '', trim((string) $value));
    return is_numeric($clean) ? (float) $clean : null;
}

function jobdivaContractProjectionCurrentRate(array $currentGraph): array
{
    $rates = array_values(array_filter($currentGraph['rates'] ?? [], 'is_array'));
    if ($rates === []) return [];
    usort($rates, static function (array $left, array $right): int {
        $leftOpenDraft = empty($left['effective_to']) && empty($left['approved_at']) ? 1 : 0;
        $rightOpenDraft = empty($right['effective_to']) && empty($right['approved_at']) ? 1 : 0;
        if ($leftOpenDraft !== $rightOpenDraft) return $rightOpenDraft <=> $leftOpenDraft;
        return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
    });
    return $rates[0] ?? [];
}

function jobdivaContractProjectionField(
    string $group,
    string $field,
    string $label,
    mixed $current,
    mixed $proposed,
    string $source,
    string $authority = 'exact_assignment'
): array {
    $normalise = static function (mixed $value): mixed {
        if (is_float($value)) return round($value, 6);
        if (is_string($value)) return trim($value);
        return $value;
    };
    return [
        'group' => $group,
        'field' => $field,
        'label' => $label,
        'current' => $current,
        'proposed' => $proposed,
        'source' => $source,
        'authority' => $authority,
        'changes' => $normalise($current) !== $normalise($proposed),
    ];
}

function jobdivaContractProjectionCheck(
    string $code,
    string $label,
    bool $passes,
    string $detail,
    string $severity = 'blocking'
): array {
    return [
        'code' => $code,
        'label' => $label,
        'status' => $passes ? 'pass' : ($severity === 'blocking' ? 'blocked' : 'warning'),
        'severity' => $severity,
        'detail' => $detail,
    ];
}

/**
 * @return array<string,mixed>
 */
function jobdivaContractProjectionBuild(
    array $payload,
    array $currentGraph = [],
    string $expectedStartId = ''
): array {
    $contract = jobdivaContractProjectionContract($payload);
    $startId = trim((string) ($contract['start_id'] ?? $expectedStartId));
    $engagement = strtolower(trim((string) ($contract['engagement_type'] ?? '')));
    $startDate = jobdivaContractProjectionDate($contract['start_date'] ?? null);
    $endDate = jobdivaContractProjectionDate($contract['end_date'] ?? null);
    $candidateId = jobdivaContractProjectionScalar($payload, [
        'candidate id', 'candidateId', 'candidate_id', 'candidateID', 'CANDIDATEID', 'employeeId',
    ]);
    $candidateName = jobdivaContractProjectionScalar($payload, [
        'candidateName', 'candidate_name', 'candidate name', 'fullName', 'full_name',
    ]);
    $jobId = jobdivaContractProjectionScalar($payload, [
        'job id', 'jobId', 'job_id', 'jobID', 'JOBID', 'reqId', 'req_id',
    ]);
    $clientName = function_exists('jobdivaEndClientNameFromPayload')
        ? trim((string) jobdivaEndClientNameFromPayload($payload))
        : jobdivaContractProjectionScalar($payload, ['companyName', 'company_name', 'customerName']);
    $clientExternalId = jobdivaContractProjectionScalar($payload, [
        'companyId', 'company_id', 'company id', 'companyID', 'COMPANYID', 'endClientCompanyId',
    ]);

    $sourceBill = jobdivaContractProjectionAmount($contract['bill_rate'] ?? null) ?? 0.0;
    $vmsBill = jobdivaContractProjectionAmount($contract['bill_rate_in_vms'] ?? null) ?? 0.0;
    $netBill = jobdivaContractProjectionAmount($contract['net_bill_rate'] ?? null) ?? 0.0;
    $laborRate = jobdivaContractProjectionAmount(
        in_array($engagement, ['c2c', '1099'], true)
            ? ($contract['pay_rate_to_vendor'] ?? $contract['pay_rate'] ?? null)
            : ($contract['pay_rate'] ?? null)
    ) ?? 0.0;
    $grossBill = $vmsBill > 0 ? $vmsBill : $sourceBill;
    $invoiceRate = $netBill > 0 ? $netBill : $sourceBill;
    if ($invoiceRate <= 0) $invoiceRate = $grossBill;
    $discountAmount = max(0.0, $grossBill - $invoiceRate);
    $discountPct = $grossBill > 0 ? $discountAmount / $grossBill : 0.0;
    $payrollLoadPct = max(0.0, (float) ($contract['payroll_load_pct'] ?? 0));
    $workersCompPct = max(0.0, (float) ($contract['workers_comp_pct'] ?? 0));
    $benefitsLoadPct = max(0.0, (float) ($contract['benefits_load_pct'] ?? 0));
    $otherHourly = max(0.0, (float) ($contract['other_cost_per_hour'] ?? 0));
    $fixedCosts = max(0.0, (float) ($contract['other_cost_flat'] ?? 0));
    $hourlyCosts = $laborRate * (1 + $payrollLoadPct + $workersCompPct + $benefitsLoadPct)
        + $otherHourly;
    $margin = $invoiceRate - $hourlyCosts;
    $marginPct = $invoiceRate > 0 ? $margin / $invoiceRate : 0.0;

    $clientCadence = (string) ($contract['client_bill_cycle'] ?? '');
    $payCadence = (string) ($contract['vendor_pay_cycle'] ?? '');
    $clientTerms = (string) ($contract['client_payment_terms'] ?? '');
    $vendorTerms = (string) ($contract['vendor_payment_terms'] ?? '');
    $pwp = !empty($contract['paid_when_paid']) || str_starts_with($vendorTerms, 'PWP');
    $corporationId = trim((string) ($contract['corporation_id'] ?? ''));
    $corporationName = trim((string) ($contract['corporation_name'] ?? ''));

    $participants = [];
    if ($clientName !== '' || $clientExternalId !== '') {
        $participants[] = [
            'role' => 'end_client',
            'name' => $clientName,
            'external_id' => $clientExternalId,
            'money_flow' => 'receivable',
            'settlement_channel' => 'ar',
            'calculation' => 'invoice_rate',
            'cadence' => $clientCadence ?: 'monthly',
            'payment_terms' => $clientTerms ?: 'tenant_or_client_default',
            'paid_when_paid' => false,
            'source' => 'Start/Job company identity + Assignment BILLING',
        ];
    }
    if (in_array($engagement, ['w2', 'temp_to_perm', 'internal'], true)) {
        $participants[] = [
            'role' => 'worker', 'name' => $candidateName, 'external_id' => $candidateId,
            'money_flow' => 'payable', 'settlement_channel' => 'payroll',
            'calculation' => 'pay_rate', 'cadence' => $payCadence ?: 'biweekly',
            'payment_terms' => null, 'paid_when_paid' => false,
            'source' => 'Start candidate identity + Assignment SALARY',
        ];
    } elseif ($engagement === 'c2c') {
        $participants[] = [
            'role' => 'c2c_vendor', 'name' => $corporationName, 'external_id' => $corporationId,
            'money_flow' => 'payable', 'settlement_channel' => 'ap',
            'calculation' => 'pay_rate', 'cadence' => $payCadence ?: 'biweekly',
            'payment_terms' => $vendorTerms ?: 'NET30', 'paid_when_paid' => $pwp,
            'source' => 'Assignment SALARY.SUBCONTRACT_COMPANYID',
        ];
    } elseif ($engagement === '1099') {
        $participants[] = [
            'role' => 'worker', 'name' => $candidateName, 'external_id' => $candidateId,
            'money_flow' => 'payable', 'settlement_channel' => 'ap',
            'calculation' => 'pay_rate', 'cadence' => $payCadence ?: 'biweekly',
            'payment_terms' => $vendorTerms ?: 'NET30', 'paid_when_paid' => $pwp,
            'source' => 'Start candidate identity + Assignment SALARY',
        ];
    }

    $referralName = trim((string) ($contract['referral_vendor'] ?? ''));
    $referralAmount = jobdivaContractProjectionAmount($contract['referral_fee_amount'] ?? null) ?? 0.0;
    if ($referralName !== '' && $referralAmount > 0) {
        $participants[] = [
            'role' => 'referrer', 'name' => $referralName, 'external_id' => null,
            'money_flow' => 'payable', 'settlement_channel' => 'ap',
            'calculation' => 'one_time', 'amount' => $referralAmount,
            'cadence' => $payCadence ?: 'biweekly',
            'payment_terms' => (string) ($contract['referral_payment_terms'] ?? 'NET30'),
            'paid_when_paid' => str_starts_with((string) ($contract['referral_payment_terms'] ?? ''), 'PWP'),
            'source' => 'Assignment referral fields',
        ];
    }

    $attributions = [];
    foreach ([
        ['source_recruiter', 'primary_recruiter', 'recruiter_allocation_pct'],
        ['source_sales', 'primary_sales', 'account_manager_allocation_pct'],
    ] as [$role, $nameField, $pctField]) {
        $name = trim((string) ($contract[$nameField] ?? ''));
        if ($name === '') continue;
        $attributions[] = [
            'role' => $role,
            'name_or_id' => $name,
            'allocation_pct' => isset($contract[$pctField]) ? (float) $contract[$pctField] : null,
            'creates_payment' => false,
            'source' => 'Assignment allocation fields',
        ];
    }

    $currentPlacement = is_array($currentGraph['placement'] ?? null) ? $currentGraph['placement'] : [];
    $currentRate = jobdivaContractProjectionCurrentRate($currentGraph);
    $provenance = is_array($contract['_provenance'] ?? null) ? $contract['_provenance'] : [];
    $sourceFor = static function (string $field, string $fallback) use ($provenance): string {
        return (string) ($provenance[$field]['path'] ?? $fallback);
    };
    $fields = [
        jobdivaContractProjectionField('Placement', 'engagement_type', 'Worker classification', $currentPlacement['engagement_type'] ?? null, $engagement ?: null, $sourceFor('engagement_type', 'Assignment.EMPLOYMENT_CATEGORY')),
        jobdivaContractProjectionField('Placement', 'status', 'Lifecycle status', $currentPlacement['status'] ?? null, $contract['placement_status'] ?? null, $sourceFor('placement_status', 'Assignment.BILLING lifecycle flags')),
        jobdivaContractProjectionField('Placement', 'start_date', 'Start date', $currentPlacement['start_date'] ?? null, $startDate, $sourceFor('start_date', 'Assignment.BILLING.START_DATE')),
        jobdivaContractProjectionField('Placement', 'end_date', 'End date', $currentPlacement['end_date'] ?? null, $endDate, $sourceFor('end_date', 'Assignment.BILLING.END_DATE')),
        jobdivaContractProjectionField('Rate', 'bill_rate', 'Gross client rate', $currentRate['bill_rate'] ?? null, $grossBill > 0 ? round($grossBill, 4) : null, $sourceFor($vmsBill > 0 ? 'bill_rate_in_vms' : 'bill_rate', 'Assignment.BILLING.BILL_RATE')),
        jobdivaContractProjectionField('Rate', 'bill_discount_pct', 'VMS / client discount', $currentRate['bill_discount_pct'] ?? null, $discountPct > 0 ? round($discountPct, 6) : null, $sourceFor('net_bill_rate', 'Assignment.BILLING.NET_BILL')),
        jobdivaContractProjectionField('Rate', 'invoice_bill_rate', 'Net invoice rate', $currentRate['adjusted_bill_rate'] ?? null, $invoiceRate > 0 ? round($invoiceRate, 4) : null, $sourceFor('net_bill_rate', 'Assignment.BILLING.NET_BILL')),
        jobdivaContractProjectionField('Rate', 'pay_rate', 'Labor pay / vendor rate', $currentRate['pay_rate'] ?? null, $laborRate > 0 ? round($laborRate, 4) : null, $sourceFor(in_array($engagement, ['c2c', '1099'], true) ? 'pay_rate_to_vendor' : 'pay_rate', 'Assignment.SALARY')),
        jobdivaContractProjectionField('Overhead', 'payroll_load_pct', 'Payroll / employer load', $currentRate['adder_pct'] ?? null, $payrollLoadPct ?: null, $sourceFor('payroll_load_pct', 'Assignment.OVERHEADS')),
        jobdivaContractProjectionField('Overhead', 'workers_comp_pct', 'Workers compensation', $currentRate['workers_comp_pct'] ?? null, $workersCompPct ?: null, $sourceFor('workers_comp_pct', 'Assignment.OVERHEADS')),
        jobdivaContractProjectionField('Overhead', 'benefits_load_pct', 'Benefits load', $currentRate['benefits_load_pct'] ?? null, $benefitsLoadPct ?: null, $sourceFor('benefits_load_pct', 'Assignment.OVERHEADS')),
        jobdivaContractProjectionField('Overhead', 'other_cost_per_hour', 'Other recurring cost', $currentRate['other_cost_per_hour'] ?? null, $otherHourly ?: null, $sourceFor('other_cost_per_hour', 'Assignment.OVERHEADS')),
        jobdivaContractProjectionField('Overhead', 'other_cost_flat', 'Fixed costs', $currentRate['other_cost_flat'] ?? null, $fixedCosts ?: null, $sourceFor('other_cost_flat', 'Assignment.OVERHEADS')),
        jobdivaContractProjectionField('Settlement', 'client_bill_cycle', 'Client billing frequency', $currentPlacement['client_bill_cycle'] ?? null, $clientCadence ?: null, $sourceFor('client_bill_cycle', 'Assignment.BILLING.FREQUENCY_LABEL')),
        jobdivaContractProjectionField('Settlement', 'vendor_pay_cycle', 'Labor payment frequency', $currentPlacement['vendor_pay_cycle'] ?? null, $payCadence ?: null, $sourceFor('vendor_pay_cycle', 'Assignment.SALARY.PAYMENT_FREQUENCY')),
        jobdivaContractProjectionField('Settlement', 'vendor_payment_terms', 'Vendor payment terms', $currentPlacement['vendor_payment_terms_override'] ?? null, $vendorTerms ?: null, $sourceFor('vendor_payment_terms', 'Assignment.SALARY.Subcontractor_Payment_terms')),
        jobdivaContractProjectionField('Settlement', 'paid_when_paid', 'Paid when paid', isset($currentPlacement['vendor_pwp_enabled']) ? (bool) $currentPlacement['vendor_pwp_enabled'] : null, $pwp, $sourceFor('paid_when_paid', 'Assignment.SALARY payment terms')),
    ];

    $knownEngagement = in_array($engagement, ['w2', 'c2c', '1099', 'temp_to_perm', 'direct_hire', 'internal'], true);
    $requiresHourlyEconomics = !in_array($engagement, ['direct_hire', 'internal'], true);
    $datesValid = $startDate !== null && ($endDate === null || $endDate >= $startDate);
    $c2cPayeePresent = $engagement !== 'c2c' || $corporationId !== '' || $corporationName !== '';
    $existingLaborPayees = array_values(array_filter(
        $currentGraph['economic_parties'] ?? [],
        static function (array $party): bool {
            if (isset($party['active']) && (int) $party['active'] !== 1) return false;
            return ($party['money_flow'] ?? '') === 'payable'
                && ($party['fee_basis'] ?? '') === 'pay_rate';
        }
    ));
    $operatorLaborPayees = array_values(array_filter(
        $existingLaborPayees,
        static function (array $party): bool {
            $sourceType = (string) ($party['source_type'] ?? 'manual');
            return $sourceType === 'manual' || empty($party['source_managed']);
        }
    ));
    $laborPayeeCheck = count($existingLaborPayees) <= 1
        ? jobdivaContractProjectionCheck(
            'single_labor_payee',
            'Single labor payee',
            true,
            'No conflicting labor recipient.'
        )
        : (count($operatorLaborPayees) > 0
            ? jobdivaContractProjectionCheck(
                'single_labor_payee',
                'Single labor payee',
                false,
                count($existingLaborPayees) . ' current labor recipients include an operator-managed row and require review.'
            )
            : jobdivaContractProjectionCheck(
                'single_labor_payee',
                'Single labor payee',
                false,
                count($existingLaborPayees) . ' stale source-owned labor recipients will be reconciled to the exact assignment payee.',
                'warning'
            ));
    $checks = [
        jobdivaContractProjectionCheck('exact_assignment_contract', 'Exact assignment financial contract', $contract !== [] && $startId !== '' && ($expectedStartId === '' || $startId === $expectedStartId), $contract === [] ? 'EmployeeAssignmentRecordsDetail has not been stored for this Start ID.' : "Start ID {$startId}"),
        jobdivaContractProjectionCheck('candidate_identity', 'Candidate identity', $candidateId !== '', $candidateId !== '' ? "Candidate {$candidateId}" : 'Candidate ID is missing.'),
        jobdivaContractProjectionCheck('job_identity', 'Job identity', $jobId !== '', $jobId !== '' ? "Job {$jobId}" : 'Job ID is missing.'),
        jobdivaContractProjectionCheck('client_identity', 'Bill-to client', $clientName !== '' || $clientExternalId !== '', $clientName !== '' ? $clientName : ($clientExternalId !== '' ? "Company {$clientExternalId}" : 'Client is missing.')),
        jobdivaContractProjectionCheck('classification', 'Worker classification', $knownEngagement, $knownEngagement ? strtoupper($engagement) : 'Assignment employment category is missing or unsupported.'),
        jobdivaContractProjectionCheck('business_dates', 'Business dates', $datesValid, $datesValid ? trim(($startDate ?? '') . ' through ' . ($endDate ?? 'open')) : 'Start/end dates are missing or reversed.'),
        jobdivaContractProjectionCheck('client_rate', 'Client rate', !$requiresHourlyEconomics || $grossBill > 0, $grossBill > 0 ? number_format($grossBill, 2) : 'No positive assignment bill rate.'),
        jobdivaContractProjectionCheck('labor_rate', 'Labor pay rate', !$requiresHourlyEconomics || $laborRate > 0, $laborRate > 0 ? number_format($laborRate, 2) : 'No positive salary/vendor pay rate.'),
        jobdivaContractProjectionCheck('rate_arithmetic', 'Gross-to-net arithmetic', $grossBill <= 0 || ($invoiceRate >= 0 && $invoiceRate <= $grossBill + 0.0001), $grossBill > 0 ? number_format($grossBill, 2) . ' gross - ' . number_format($discountAmount, 2) . ' adjustment = ' . number_format($invoiceRate, 2) . ' invoice' : 'No hourly client rate.'),
        jobdivaContractProjectionCheck('c2c_payee', 'C2C vendor identity', $c2cPayeePresent, $c2cPayeePresent ? ($corporationName ?: "JobDiva company {$corporationId}") : 'Subcontract company ID and name are both missing.'),
        jobdivaContractProjectionCheck('billing_frequency', 'Client billing frequency', $clientCadence !== '' || !$requiresHourlyEconomics, $clientCadence !== '' ? $clientCadence : 'Will use the client/tenant default.', 'warning'),
        jobdivaContractProjectionCheck('payment_frequency', 'Labor payment frequency', $payCadence !== '' || !$requiresHourlyEconomics, $payCadence !== '' ? $payCadence : 'Will use the worker/vendor default.', 'warning'),
        $laborPayeeCheck,
    ];
    $blocking = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] === 'blocked'));
    $warnings = array_values(array_filter($checks, static fn(array $check): bool => $check['status'] === 'warning'));

    return [
        'contract_version' => 2,
        'source_system' => 'jobdiva',
        'source_record' => 'EmployeeAssignmentRecordsDetail',
        'start_id' => $startId,
        'complete' => $blocking === [],
        'placement' => [
            'engagement_type' => $engagement ?: null,
            'status' => $contract['placement_status'] ?? null,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ],
        'economics' => [
            'gross_client_rate' => round($grossBill, 4),
            'client_adjustment_amount' => round($discountAmount, 4),
            'client_adjustment_pct' => round($discountPct, 6),
            'invoice_rate' => round($invoiceRate, 4),
            'labor_rate' => round($laborRate, 4),
            'hourly_costs' => round($hourlyCosts, 4),
            'gross_margin' => round($margin, 4),
            'gross_margin_pct' => round($marginPct, 6),
            'fixed_costs' => round($fixedCosts, 2),
        ],
        'participants' => $participants,
        'attributions' => $attributions,
        'overheads' => is_array($contract['overheads'] ?? null) ? $contract['overheads'] : [],
        'fields' => $fields,
        'changes' => array_values(array_filter($fields, static fn(array $field): bool => !empty($field['changes']))),
        'checks' => $checks,
        'blocking_issues' => $blocking,
        'warnings' => $warnings,
        'source_contract' => $contract,
    ];
}
