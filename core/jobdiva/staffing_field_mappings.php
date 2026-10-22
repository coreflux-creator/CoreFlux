<?php
/** Read-only inventory of the existing JobDiva adapter, not a new import policy. */
declare(strict_types=1);

function jobdivaStaffingFieldMappings(): array
{
    $fields = [];
    $add = static function (string $key, string $route, string $path, string $transform,
        string $policy, string $implementation, array $details = []) use (&$fields): void {
        $fields[$key]['jobdiva'][] = array_merge([
            'route' => $route, 'path' => $path, 'direction' => 'jobdiva_to_core',
            'transform' => $transform, 'write_policy' => $policy,
            'contract_version' => 'core-adapter-2026-10-22.1',
            'verification_status' => 'adapter_source_verified_live_cohort_pending',
            'source_evidence' => ['core' => $implementation,
                'contract' => 'core/jobdiva/assignment_contract.php'],
            'verification_scope' => 'Existing Core adapter source; representative behavior has synthetic fixture coverage. Provider runtime and selected Thunderhawk records are not verified by this inventory.',
            'path_scope' => 'Response record; section arrays are shown with []. Accepted aliases and tenant overrides require source-record review.',
            'sync_enabled_by_dictionary' => false,
        ], $details);
    };
    $start = '/apiv2/jobdiva/searchStart';
    $detail = '/apiv2/bi/EmployeeAssignmentRecordsDetail';
    $candidate = '/apiv2/jobdiva/searchCandidate';
    $identityPolicy = 'existing_specialized_identity_resolver; never_assign_Core_business_numbers_from_provider_IDs';
    $contractPolicy = 'exact_Start_assignment_contract_before_tenant_enrichment; preserve_approved_rate_history; normal_Core_approval_still_required';
    $assignment = static function (string $key, string $path, string $normalized,
        string $transform, array $details = []) use ($add, $detail, $contractPolicy): void {
        $add($key, $detail, $path, $transform, $contractPolicy, 'core/jobdiva/sync.php',
            array_merge(['normalized_contract_path' => '_jd_contract.' . $normalized,
                'missing_value_policy' => 'Source contract, fallback mappings and existing-row preservation are field-specific; do not interpret absence as an instruction to clear.'], $details));
    };

    $add('placements.external_id', $start, 'id',
        'Exact verified Start identity only; retain opaque provider ID with legacy jd: namespace.',
        $identityPolicy, 'core/jobdiva/assignment_identity.php',
        ['accepted_aliases' => ['startId', 'start_id', 'placementId'],
            'identity_guard' => 'Conflicting identities, candidate/job-only rows and mismatched exact lookups cannot establish a placement.']);
    foreach (['placements.person_id', 'people.external_id'] as $key) {
        $add($key, $start, 'candidate id',
            $key === 'placements.person_id' ? 'Resolve the JobDiva candidate through the scoped person mapping; never copy its numeric value as a Core person ID.'
                : 'Retain JobDiva candidate identity separately from P number and other provider IDs.',
            $identityPolicy, 'core/jobdiva/sync_placements.php',
            ['accepted_aliases' => ['candidateId', 'candidate_id', 'employeeId'],
                'known_limitations' => ['Email/spreadsheet matches require reviewed candidate/person linking; competing durable links are preserved and blocked. JobDiva projection currently requires tenant-local people and placements; shared-directory projection remains pending.']]);
    }
    foreach (['placements.jobdiva_job_id', 'placements.staffing_job_id'] as $key) {
        $add($key, $start, 'job id',
            $key === 'placements.staffing_job_id' ? 'Resolve the canonical staffing job by its scoped JobDiva reference.' : 'Preserve the JobDiva job reference separately from the Start and PL number.',
            $identityPolicy, 'core/jobdiva/sync.php', ['accepted_aliases' => ['jobId', 'job_id', 'jobID']]);
    }
    foreach (['first_name' => 'firstName', 'last_name' => 'lastName',
        'email_primary' => 'email', 'phone_primary' => 'phone'] as $column => $path) {
        $add('people.' . $column, $candidate, $path,
            'Candidate detail is retained under _jd_candidate; person-specific tenant field mappings precede built-in deep lookup aliases.',
            'existing_candidate_bootstrap_and_tenant_field_mapping; not_a_reviewed_bidirectional_profile_write',
            'core/jobdiva/sync_placements.php',
            ['source_evidence' => ['core' => 'core/jobdiva/sync_placements.php',
                'transport' => 'core/jobdiva/sync.php', 'fixture' => 'tests/jobdiva_deep_field_resolution_smoke.php'],
                'known_limitations' => ['Missing names/email can use legacy placeholders during creation; these are not verified contact details.',
                    'Existing email matches require the candidate/person identity review; linking preserves existing contact fields.']]);
    }

    foreach (['placements.end_client_company_id', 'placements.client_id', 'staffing_clients.company_id'] as $key) {
        $assignment($key, 'BILLING[].COMPANY[].ID', 'client_company_id',
            'Resolve the canonical company, then its staffing-client role; retain distinct CO and CL numbers. Never copy the provider ID into a Core foreign key.');
    }
    $assignment('placements.end_client_name', 'BILLING[].COMPANY[].COMPANYNAME', 'client_company_name',
        'Trim the exact assignment billing-company label; a requisition company label cannot override it.');
    $assignment('placements.engagement_type', 'SALARY[].EMPLOYMENT_CATEGORY', 'engagement_type',
        'Hourly/Salaried Employee -> w2; Subcontract -> c2c; independent/1099 -> 1099; explicit referral/direct-hire categories remain separate. Assignment evidence precedes candidate classification.',
        ['known_limitations' => ['Legacy placement fallback can default an unresolved classification to w2; downstream reviewed delivery must require verified classification.']]);
    foreach (['start_date' => 'START_DATE', 'end_date' => 'END_DATE'] as $column => $path) {
        $assignment('placements.' . $column, 'BILLING[].' . $path, $column,
            'Normalize the source business date to YYYY-MM-DD; retain date text from timestamp-without-zone values.',
            $column === 'end_date' ? ['null_policy' => 'Explicit blank Billing END_DATE means open-ended; do not substitute the requisition JOB.ENDDATE.'] : []);
    }
    foreach (['BILLING[].APPROVED', 'BILLING[].ACTUALSTART', 'BILLING[].ACTUALEND',
        'BILLING[].CLOSED', 'BILLING[].END_DATE', 'SALARY[].APPROVED', 'SALARY[].CLOSED'] as $path) {
        $assignment('placements.status', $path, 'placement_status',
            'Combine Billing lifecycle and explicitly approved Salary; Salary STATUS alone is not approval. Terminal/end-date evidence and existing-started-placement transition rules apply.',
            ['null_policy' => 'Missing Salary approval does not establish an active assignment.',
                'source_approval_scope' => 'JobDiva assignment lifecycle only; never Core rate/time/payroll approval.']);
    }
    foreach (['worksite_state' => 'WORKING_STATE', 'worksite_country' => 'WORKING_COUNTRY'] as $column => $path) {
        $assignment('placements.' . $column, 'BILLING[].' . $path, $column,
            $column === 'worksite_country' ? 'Current importer uppercases and truncates to two characters; validate a source ISO alpha-2 code before pilot use.' : 'Preserve the assignment working-state value.',
            $column === 'worksite_country' ? ['known_limitations' => ['Country names are not normalized by a country dictionary; truncation can produce invalid codes.']] : []);
    }
    foreach (['branch' => 'Branch', 'service_line' => 'Service Line',
        'department' => 'Department', 'cost_center' => 'Cost Center'] as $column => $path) {
        $assignment('placements.' . $column, 'BILLING[].' . $path, $column,
            'Trim assignment dimension text; exact contract value precedes tenant enrichment.');
    }
    $assignment('placements.workers_comp_class', 'SALARY[].WC Class', 'workers_comp_class',
        'Preserve the class identifier as text; do not interpret it as a workers-compensation percentage.');
    $assignment('placements.remote_policy', 'BILLING[].WORKING_LOCATION', 'remote_policy',
        'Recognized onsite/on-site/on_site, hybrid, remote/work_from_home/wfh values become Core enums; unknown values yield null.');
    foreach (['BILL_RATE' => 'bill_rate', 'Bill_Rate_in_Beeline' => 'bill_rate_in_vms'] as $path => $normalized) {
        $assignment('placement_rates.bill_rate', 'BILLING[].' . $path, $normalized,
            'Parse the source amount. Use VMS gross only with positive net below gross; otherwise use the exact assignment bill rate. Nonpositive bill rate does not create a placeholder rate.');
    }
    $assignment('placement_rates.bill_discount_pct', 'BILLING[].NET_BILL', 'net_bill_rate',
        'With valid gross/net and no positive mapped discount, derive (VMS gross - net) / VMS gross; store a fraction, not percent points.',
        ['additional_source_paths' => ['BILLING[].Bill_Rate_in_Beeline']]);
    foreach (['SALARY' => 'pay_rate', 'Pay_Rate_to_Vendor' => 'pay_rate_to_vendor'] as $path => $normalized) {
        $assignment('placement_rates.pay_rate', 'SALARY[].' . $path, $normalized,
            'Parse labor pay separately from bill rate; positive vendor pay takes precedence for subcontract/1099. Referral payout belongs to the referral graph and worker pay is zero. Missing labor pay does not copy the bill rate.');
    }
    foreach (['bill_rate_unit' => ['BILLING[].BILL_RATE_PER', 'bill_rate_unit'],
        'pay_rate_unit' => ['SALARY[].SALARY_PER', 'pay_rate_unit']] as $column => [$path, $normalized]) {
        $assignment('placement_rates.' . $column, $path, $normalized,
            'Existing adapter recognizes hour/day/week/month/project text; missing or unrecognized units default to hour.',
            ['known_limitations' => ['Verify the real source unit before reviewed downstream use; legacy default is not source evidence.']]);
    }
    $assignment('placement_rates.currency', 'BILLING[].Currency', 'currency',
        'Billing currency precedes Salary/general currency. Current importer extracts three-letter text and otherwise can default USD.',
        ['known_limitations' => ['Verify source currency; legacy USD fallback is not source evidence.']]);
    foreach (['client_bill_cycle' => ['BILLING[].FREQUENCY_LABEL', 'client_bill_cycle'],
        'vendor_pay_cycle' => ['SALARY[].PAYMENT_FREQUENCY', 'vendor_pay_cycle']] as $column => [$path, $normalized]) {
        $assignment('placements.' . $column, $path, $normalized,
            'Normalize recognized weekly/biweekly/semimonthly/monthly/adhoc cadence labels; Billing and Salary cycles remain distinct. Unsupported labels yield no contract cadence.');
    }
    $assignment('placement_corp_details.ap_vendor_id', 'SALARY[].SUBCONTRACT_COMPANYID', 'corporation_id',
        'Resolve subcontract company and AP-vendor role; retain V separately from provider corporation ID. Employee assignments do not inherit stale C2C payee details.');
    foreach (['vendor_payment_terms_override' => 'vendor_payment_terms', 'vendor_pwp_enabled' => 'paid_when_paid'] as $column => $normalized) {
        $assignment('placements.' . $column, 'SALARY[].Subcontractor_Payment_terms', $normalized,
            'Normalize NET/PWP terms, payment-due evidence and net-day values; PWP is a settlement condition, not a payment receipt.');
    }
    foreach (['adder_pct' => ['Payroll Load %', 'payroll_load_pct'],
        'workers_comp_pct' => ['Workers Comp %', 'workers_comp_pct'],
        'benefits_load_pct' => ['Benefits Load %', 'benefits_load_pct'],
        'c2c_overhead_pct' => ['C2C Overhead %', 'c2c_overhead_pct'],
        'other_cost_flat' => ['Fixed Costs', 'other_cost_flat']] as $column => [$path, $normalized]) {
        $assignment('placement_rates.' . $column, 'OVERHEADS[].' . $path, $normalized,
            $column === 'other_cost_flat' ? 'Parse the source fixed amount, separate from hourly costs.' : 'Parse percent labels into fractions; retain worker-classification-specific overhead meaning.');
    }
    return $fields;
}
