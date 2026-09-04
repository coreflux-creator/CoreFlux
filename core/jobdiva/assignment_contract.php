<?php
/**
 * Deterministic JobDiva Assignment -> CoreFlux commercial contract adapter.
 *
 * searchStart identifies the assignment. EmployeeAssignmentRecordsDetail owns
 * its economic facts: worker classification, bill/pay rates, payee company,
 * payment frequency and terms, referral economics, and overheads.
 */
declare(strict_types=1);

function jobdivaAssignmentContractNormaliseKey(string $value): string
{
    $value = str_replace('%', ' percent ', $value);
    return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
}

function jobdivaAssignmentContractHasValue(mixed $value): bool
{
    if ($value === null) return false;
    if (is_string($value)) return trim($value) !== '' && strtolower(trim($value)) !== 'null';
    return is_scalar($value);
}

/** @return array<int,array{path:string,key:string,norm_path:string,norm_key:string,value:mixed}> */
function jobdivaAssignmentContractEntries(array $records): array
{
    $entries = [];
    $walk = static function (mixed $node, string $path = '') use (&$walk, &$entries): void {
        if (!is_array($node)) return;

        // Some BI endpoints encode a field as {fieldName: "Bill Rate", value: 71.4}.
        $label = null;
        foreach (['fieldName', 'field_name', 'columnName', 'column_name', 'label', 'name', 'key'] as $labelKey) {
            if (isset($node[$labelKey]) && is_scalar($node[$labelKey])) {
                $label = trim((string) $node[$labelKey]);
                if ($label !== '') break;
            }
        }
        if ($label !== null && $label !== '') {
            foreach (['fieldValue', 'field_value', 'columnValue', 'column_value', 'value', 'val'] as $valueKey) {
                if (array_key_exists($valueKey, $node) && jobdivaAssignmentContractHasValue($node[$valueKey])) {
                    $entryPath = $path === '' ? $label : $path . '.' . $label;
                    $entries[] = [
                        'path' => $entryPath,
                        'key' => $label,
                        'norm_path' => jobdivaAssignmentContractNormaliseKey($entryPath),
                        'norm_key' => jobdivaAssignmentContractNormaliseKey($label),
                        'value' => $node[$valueKey],
                    ];
                    break;
                }
            }
        }

        foreach ($node as $key => $value) {
            $keyString = (string) $key;
            $nextPath = $path === '' ? $keyString : $path . '.' . $keyString;
            if (is_array($value)) {
                $walk($value, $nextPath);
                continue;
            }
            if (!jobdivaAssignmentContractHasValue($value)) continue;
            $entries[] = [
                'path' => $nextPath,
                'key' => $keyString,
                'norm_path' => jobdivaAssignmentContractNormaliseKey($nextPath),
                'norm_key' => jobdivaAssignmentContractNormaliseKey($keyString),
                'value' => $value,
            ];
        }
    };
    $walk($records);
    return $entries;
}

function jobdivaAssignmentContractPick(array $entries, array $labels): mixed
{
    $wanted = array_values(array_filter(array_map(
        static fn(string $label): string => jobdivaAssignmentContractNormaliseKey($label),
        $labels
    )));
    foreach ($wanted as $candidate) {
        foreach ($entries as $entry) {
            if (($entry['norm_key'] ?? '') === $candidate
                && jobdivaAssignmentContractHasValue($entry['value'] ?? null)) {
                return $entry['value'];
            }
        }
    }
    foreach ($wanted as $candidate) {
        foreach ($entries as $entry) {
            $path = (string) ($entry['norm_path'] ?? '');
            if ($path !== '' && str_ends_with($path, $candidate)
                && jobdivaAssignmentContractHasValue($entry['value'] ?? null)) {
                return $entry['value'];
            }
        }
    }
    return null;
}

/** @return array<int,array<string,mixed>> */
function jobdivaAssignmentContractSectionRows(array $records, string $section): array
{
    $wanted = jobdivaAssignmentContractNormaliseKey($section);
    $rows = [];
    $walk = static function (mixed $node) use (&$walk, &$rows, $wanted): void {
        if (!is_array($node)) return;
        foreach ($node as $key => $value) {
            if (!is_array($value)) continue;
            if (jobdivaAssignmentContractNormaliseKey((string) $key) === $wanted) {
                if (array_is_list($value)) {
                    foreach ($value as $row) {
                        if (is_array($row)) $rows[] = $row;
                    }
                } else {
                    $rows[] = $value;
                }
            }
            $walk($value);
        }
    };
    $walk($records);
    return $rows;
}

function jobdivaAssignmentContractAmount(mixed $raw): ?float
{
    if ($raw === null) return null;
    if (is_int($raw) || is_float($raw)) return (float) $raw;
    $value = str_replace([',', '$'], '', trim((string) $raw));
    if ($value === '') return null;
    if (is_numeric($value)) return (float) $value;
    return preg_match('/-?\d+(?:\.\d+)?/', $value, $match) ? (float) $match[0] : null;
}

function jobdivaAssignmentContractPercent(mixed $raw): ?float
{
    if ($raw === null || trim((string) $raw) === '') return null;
    $text = trim((string) $raw);
    $number = jobdivaAssignmentContractAmount($raw);
    if ($number === null) return null;
    if (str_contains($text, '%') || abs($number) > 1) $number /= 100;
    return round($number, 6);
}

function jobdivaAssignmentContractBool(mixed $raw): ?bool
{
    if ($raw === null || trim((string) $raw) === '') return null;
    if (is_bool($raw)) return $raw;
    if (is_int($raw) || is_float($raw)) return (float) $raw > 0;
    $value = strtolower(trim((string) $raw));
    if (in_array($value, ['1', 'true', 'yes', 'y', 'on', 'checked'], true)) return true;
    if (in_array($value, ['0', 'false', 'no', 'n', 'off', 'unchecked'], true)) return false;
    return null;
}

function jobdivaAssignmentContractEngagement(string $category, ?bool $w2, ?bool $c2c): string
{
    $value = strtolower(trim($category));
    $value = preg_replace('/[\s_\-\/]+/', ' ', $value) ?: $value;
    if ($value !== '') {
        if (str_contains($value, 'subcontract') || str_contains($value, 'corp to corp')
            || str_contains($value, 'c2c')) return 'c2c';
        if (str_contains($value, 'independent contractor') || str_contains($value, '1099')) return '1099';
        if (str_contains($value, 'hourly employee') || str_contains($value, 'salaried employee')
            || $value === 'employee' || str_contains($value, 'w2')) return 'w2';
        if (str_contains($value, 'temp to perm') || str_contains($value, 'contract to hire')) return 'temp_to_perm';
        if (str_contains($value, 'direct hire') || str_contains($value, 'direct placement')) return 'direct_hire';
    }
    if ($c2c === true) return 'c2c';
    if ($w2 === true) return 'w2';
    return '';
}

function jobdivaAssignmentContractCadence(mixed $raw): ?string
{
    $value = strtolower(trim((string) $raw));
    if ($value === '') return null;
    $value = str_replace(['_', '-'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?: $value;
    if (str_contains($value, 'semi monthly') || str_contains($value, 'twice a month')) return 'semimonthly';
    if (str_contains($value, 'bi weekly') || str_contains($value, 'every two week')) return 'biweekly';
    if (str_contains($value, 'weekly')) return 'weekly';
    if (str_contains($value, 'monthly')) return 'monthly';
    if (str_contains($value, 'adhoc') || str_contains($value, 'ad hoc')
        || str_contains($value, 'milestone')) return 'adhoc';
    return null;
}

function jobdivaAssignmentContractTerms(mixed $raw, mixed $paymentDue = null, mixed $netDays = null): ?string
{
    $text = trim((string) $raw);
    $due = trim((string) $paymentDue);
    $combined = strtolower($text . ' ' . $due);
    $hasPwp = str_contains($combined, 'pay when paid')
        || str_contains($combined, 'paid when paid')
        || str_contains($combined, 'p-w-p')
        || preg_match('/\bpwp\b/', $combined) === 1
        || str_contains($combined, 'pay on remittance');
    $days = null;
    if (preg_match('/\bnet\s*[-_ ]?(\d{1,3})\b/i', $text, $match)) {
        $days = (int) $match[1];
    } elseif (jobdivaAssignmentContractAmount($netDays) !== null) {
        $days = max(0, (int) round((float) jobdivaAssignmentContractAmount($netDays)));
    }
    if ($hasPwp && $days !== null) return 'PWP_NET' . $days;
    if ($hasPwp) return 'PWP';
    if ($days !== null) return $days === 0 ? 'DUE_ON_RECEIPT' : 'NET' . $days;
    if (str_contains($combined, 'upon approval') || str_contains($combined, 'due on receipt')
        || str_contains($combined, 'immediate')) return 'DUE_ON_RECEIPT';
    return null;
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<string,mixed>
 */
function jobdivaAssignmentContractBuild(array $rows, array $fallback = [], string $expectedStartId = ''): array
{
    $expectedStartId = trim($expectedStartId);
    $matching = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $rowEntries = jobdivaAssignmentContractEntries($row);
        $rowStartId = trim((string) jobdivaAssignmentContractPick($rowEntries, [
            'Start ID', 'startId', 'start_id', 'Assignment ID', 'assignmentId',
        ]));
        if ($expectedStartId !== '' && $rowStartId !== '' && $rowStartId !== $expectedStartId) continue;
        $matching[] = $row;
    }
    // Never fall back to explicitly mismatched assignment rows. Identifier-free
    // field/value rows are already retained above; only an unscoped call may
    // consume the full response as a fallback.
    if (!$matching && $expectedStartId === '') {
        $matching = array_values(array_filter($rows, 'is_array'));
    }
    if ($fallback) $matching[] = $fallback;
    if (!$matching) return [];
    $entries = jobdivaAssignmentContractEntries($matching);

    // EmployeeAssignmentRecordsDetail repeats generic keys such as END_DATE,
    // FREQUENCY and STATUS across sections. BILLING owns the assignment's
    // client/lifecycle facts; SALARY owns labor-pay and subcontractor facts.
    // Keep these entry sets separate so array order cannot change semantics.
    $billingEntries = jobdivaAssignmentContractEntries(
        jobdivaAssignmentContractSectionRows($matching, 'BILLING')
    );
    $salaryEntries = jobdivaAssignmentContractEntries(
        jobdivaAssignmentContractSectionRows($matching, 'SALARY')
    );

    $pick = static fn(array $labels): mixed => jobdivaAssignmentContractPick($entries, $labels);
    $billingPick = static fn(array $labels): mixed => jobdivaAssignmentContractPick($billingEntries, $labels);
    $salaryPick = static fn(array $labels): mixed => jobdivaAssignmentContractPick($salaryEntries, $labels);
    $startId = trim((string) ($pick(['Start ID', 'startId', 'start_id', 'Assignment ID', 'assignmentId']) ?? $expectedStartId));
    $employmentCategory = trim((string) $pick([
        'Employment Category', 'employmentCategory', 'employment_category', 'Employee Category',
    ]));
    $w2 = jobdivaAssignmentContractBool($pick(['W2', 'W-2', 'W2 Overheads', 'W2 Overhead']));
    $c2c = jobdivaAssignmentContractBool($pick(['C2C', 'Corp To Corp', 'Crop To Crop', 'C2C Overheads', 'C2C Overhead']));
    $engagement = jobdivaAssignmentContractEngagement($employmentCategory, $w2, $c2c);

    $paymentFrequencyRaw = $salaryPick([
        'Payment Frequency', 'paymentFrequency', 'payment_frequency', 'Vendor Payment Frequency',
    ]) ?? $pick(['Payment Frequency', 'paymentFrequency', 'payment_frequency', 'Vendor Payment Frequency']);
    $billingFrequencyRaw = $billingPick([
        'Frequency Label', 'FREQUENCY_LABEL', 'Billing Frequency', 'billingFrequency',
        'billing_frequency', 'Invoice Frequency',
    ]) ?? $pick(['Billing Frequency', 'billingFrequency', 'billing_frequency', 'Invoice Frequency']);
    $paymentDue = $pick(['Payment Due', 'paymentDue', 'payment_due', 'Payment Method']);
    $netDays = $pick(['Payment Terms Days', 'paymentTermsDays', 'payment_terms_days', 'Net Days']);
    $vendorTermsRaw = $pick([
        'Subcontractor Payment Terms', 'subcontractorPaymentTerms', 'subcontractor_payment_terms',
        'Vendor Payment Terms', 'vendorPaymentTerms', 'vendor_payment_terms', 'Payment Terms',
    ]);
    $vendorTerms = jobdivaAssignmentContractTerms($vendorTermsRaw, $paymentDue, $netDays);
    $clientTermsRaw = $pick([
        'Client Payment Terms', 'clientPaymentTerms', 'client_payment_terms',
        'Billing Payment Terms', 'billingPaymentTerms', 'billing_payment_terms',
    ]);
    $clientTerms = jobdivaAssignmentContractTerms($clientTermsRaw);

    $overheadEntries = [];
    $overheadNeedles = [
        'overhead', 'perdiem', 'otherexpense', 'outsidecommission', 'fixedcost',
        'workerscomp', 'payrollprofile', 'passthrough', 'passdiscount', 'benefitload',
    ];
    foreach ($entries as $entry) {
        $haystack = (string) ($entry['norm_path'] ?? '');
        if ($haystack === '') continue;
        $matched = false;
        foreach ($overheadNeedles as $needle) {
            if (str_contains($haystack, $needle)) { $matched = true; break; }
        }
        if (!$matched) continue;
        $key = (string) ($entry['path'] ?? $entry['key'] ?? 'overhead');
        $overheadEntries[$key] = $entry['value'];
    }

    $overheadRaw = $pick(['Overheads', 'Overhead', 'Payroll Overheads', 'Payroll Load']);
    $overheadPercentRaw = $pick([
        'Overhead %', 'Overheads %', 'Overhead Percent', 'Payroll Load %', 'Payroll Load Percent',
    ]);
    $overheadPct = jobdivaAssignmentContractPercent($overheadPercentRaw);
    $workersCompPct = jobdivaAssignmentContractPercent($pick([
        'Workers Comp %', 'Workers Comp Percent', 'workersCompPct', 'workers_comp_pct',
    ]));
    $benefitsLoadPct = jobdivaAssignmentContractPercent($pick([
        'Benefits Load %', 'Benefits Load Percent', 'benefitsLoadPct', 'benefits_load_pct',
    ]));

    $billRate = jobdivaAssignmentContractAmount(
        $billingPick(['Bill Rate', 'billRate', 'bill_rate', 'Final Bill Rate'])
        ?? $pick(['Bill Rate', 'billRate', 'bill_rate', 'Final Bill Rate'])
    );
    $basePayRate = jobdivaAssignmentContractAmount(
        $salaryPick(['Salary', 'Pay Rate', 'payRate', 'pay_rate', 'Agreed Pay Rate'])
        ?? $pick(['Pay Rate', 'payRate', 'pay_rate', 'Agreed Pay Rate'])
    );
    $vendorPayRate = jobdivaAssignmentContractAmount($salaryPick([
        'Pay Rate to Vendor', 'payRateToVendor', 'pay_rate_to_vendor', 'Vendor Pay Rate',
    ]) ?? $pick([
        'Pay Rate to Vendor', 'payRateToVendor', 'pay_rate_to_vendor', 'Vendor Pay Rate',
    ]));
    $payRate = in_array($engagement, ['c2c', '1099'], true) && $vendorPayRate !== null && $vendorPayRate > 0
        ? $vendorPayRate
        : $basePayRate;
    $primarySales = trim((string) $pick([
        'Primary Sales', 'primarySales', 'primary_sales',
        'Primary Salesperson', 'PRIMARY_SALESPERSON', 'primarySalesperson',
    ]));
    $primaryRecruiter = trim((string) $pick(['Primary Recruiter', 'primaryRecruiter', 'primary_recruiter']));

    $startDateRaw = $billingPick(['Start Date', 'START_DATE', 'startDate', 'start_date'])
        ?? $pick(['Start Date', 'START_DATE', 'startDate', 'start_date']);
    $endDateRaw = $billingPick(['End Date', 'END_DATE', 'endDate', 'end_date'])
        ?? $pick(['End Date', 'END_DATE', 'endDate', 'end_date']);
    $actualStart = jobdivaAssignmentContractBool($billingPick(['Actual Start', 'ACTUALSTART', 'actualStart']));
    $actualEnd = jobdivaAssignmentContractBool($billingPick(['Actual End', 'ACTUALEND', 'actualEnd']));
    $approved = jobdivaAssignmentContractBool($billingPick(['Approved', 'APPROVED', 'approved']));
    $closed = jobdivaAssignmentContractBool($billingPick(['Closed', 'CLOSED', 'closed']));
    $endDate = function_exists('jobdivaNormaliseDate') ? jobdivaNormaliseDate($endDateRaw) : null;
    if ($closed === true || $actualEnd === true || ($endDate !== null && $endDate < date('Y-m-d'))) {
        $placementStatus = 'ended';
    } elseif ($actualStart === true && $approved !== false) {
        $placementStatus = 'active';
    } elseif ($approved === true) {
        $placementStatus = 'pending_start';
    } else {
        $placementStatus = '';
    }

    $vmsBillRate = jobdivaAssignmentContractAmount($billingPick([
        'Bill Rate in Beeline', 'Bill Rate in VMS', 'billRateInBeeline', 'bill_rate_in_vms',
    ]) ?? $pick([
        'Bill Rate in Beeline', 'Bill Rate in VMS', 'billRateInBeeline', 'bill_rate_in_vms',
    ]));
    $netBillRate = jobdivaAssignmentContractAmount($billingPick([
        'Net Bill', 'NET_BILL', 'Net Bill Rate', 'netBillRate', 'net_bill_rate',
    ]) ?? $pick(['Net Bill', 'NET_BILL', 'Net Bill Rate', 'netBillRate', 'net_bill_rate']));
    if (($netBillRate === null || $netBillRate <= 0) && $billRate !== null && $billRate > 0) {
        $netBillRate = $billRate;
    }
    $vmsFeePct = $vmsBillRate !== null && $vmsBillRate > 0
        && $netBillRate !== null && $netBillRate > 0 && $netBillRate < $vmsBillRate
        ? round(($vmsBillRate - $netBillRate) / $vmsBillRate, 6)
        : null;

    $contract = [
        'contract_version' => 1,
        'source' => 'EmployeeAssignmentRecordsDetail',
        'start_id' => $startId,
        'employment_category' => $employmentCategory,
        'engagement_type' => $engagement,
        'placement_status' => $placementStatus,
        'start_date' => $startDateRaw,
        'end_date' => $endDateRaw,
        'actual_start' => $actualStart,
        'actual_end' => $actualEnd,
        'approved' => $approved,
        'closed' => $closed,
        'worksite_city' => $billingPick(['Working City', 'WORKING_CITY', 'worksiteCity', 'worksite_city']),
        'worksite_state' => $billingPick(['Working State', 'WORKING_STATE', 'worksiteState', 'worksite_state']),
        'worksite_country' => $billingPick(['Working Country', 'WORKING_COUNTRY', 'worksiteCountry', 'worksite_country']),
        'remote_policy' => $billingPick(['Working Location', 'WORKING_LOCATION', 'workLocation', 'remotePolicy']),
        'w2_flag' => $w2,
        'c2c_flag' => $c2c,
        'bill_rate' => $billRate,
        'pay_rate' => $payRate,
        'bill_rate_unit' => $billingPick([
            'Bill Rate Unit', 'billRateUnit', 'bill_rate_unit', 'Bill Rate Per', 'BILL_RATE_PER',
        ]) ?? $pick(['Bill Rate Unit', 'billRateUnit', 'bill_rate_unit', 'Bill Rate Per', 'BILL_RATE_PER']),
        'pay_rate_unit' => $salaryPick([
            'Pay Rate Unit', 'payRateUnit', 'pay_rate_unit', 'Pay Rate Per', 'PAY_RATE_PER',
            'Salary Per', 'SALARY_PER',
        ]) ?? $pick([
            'Pay Rate Unit', 'payRateUnit', 'pay_rate_unit', 'Pay Rate Per', 'PAY_RATE_PER',
            'Salary Per', 'SALARY_PER',
        ]),
        'bill_start' => $pick(['Bill Start', 'billStart', 'bill_start']),
        'bill_end' => $pick(['Bill End', 'billEnd', 'bill_end']),
        'pay_start' => $pick(['Pay Start', 'payStart', 'pay_start']),
        'pay_end' => $pick(['Pay End', 'payEnd', 'pay_end']),
        'currency' => $billingPick([
            'Currency', 'currencyCode', 'currency_code', 'Bill Currency', 'BILL_CURRENCY',
        ]) ?? $salaryPick([
            'Currency', 'currencyCode', 'currency_code', 'Pay Currency', 'PAY_CURRENCY',
        ]) ?? $pick(['Currency', 'currencyCode', 'currency_code']),
        'net_bill_rate' => $netBillRate,
        'bill_rate_in_vms' => $vmsBillRate,
        'vms_fee_pct' => $vmsFeePct,
        'pay_rate_to_vendor' => $vendorPayRate,
        'spread' => jobdivaAssignmentContractAmount($pick(['Spread', 'spread'])),
        'corporation_name' => trim((string) $pick([
            'Corporation', 'Corporation Name', 'corporationName', 'corporation_name',
            'Subcontractor Company', 'Vendor Company',
        ])),
        'corporation_id' => trim((string) $pick([
            'Corporation ID', 'corporationId', 'corporation_id', 'Vendor ID', 'vendorId',
            'Subcontract Company ID', 'SUBCONTRACT_COMPANYID', 'subcontractCompanyId',
        ])),
        'payment_frequency' => $paymentFrequencyRaw,
        'vendor_pay_cycle' => jobdivaAssignmentContractCadence($paymentFrequencyRaw),
        'billing_frequency' => $billingFrequencyRaw,
        'client_bill_cycle' => jobdivaAssignmentContractCadence($billingFrequencyRaw),
        'payment_due' => $paymentDue,
        'vendor_payment_terms_raw' => $vendorTermsRaw,
        'vendor_payment_terms' => $vendorTerms,
        'paid_when_paid' => $vendorTerms !== null && str_starts_with($vendorTerms, 'PWP'),
        'client_payment_terms_raw' => $clientTermsRaw,
        'client_payment_terms' => $clientTerms,
        'payment_discount_pct' => jobdivaAssignmentContractPercent($pick([
            'Payment Discount %', 'Payment Discount Percent', 'paymentDiscountPct', 'payment_discount_pct',
        ])),
        'background_fee_total' => jobdivaAssignmentContractAmount($pick([
            'Background Fees', 'Background Fee', 'backgroundFees', 'background_fee_total',
        ])),
        'referral_vendor' => trim((string) $pick(['Referral Vendor', 'referralVendor', 'referral_vendor'])),
        'referral_fee_amount' => jobdivaAssignmentContractAmount($pick([
            'Referral Fee Amount', 'referralFeeAmount', 'referral_fee_amount',
        ])),
        'referral_vendor_payment_terms' => jobdivaAssignmentContractTerms($pick([
            'Referral Vendor Payment terms', 'Referral Vendor Payment Terms', 'referralVendorPaymentTerms',
        ])),
        'referral_payment_terms' => jobdivaAssignmentContractTerms($pick([
            'Referral Vendor Payment terms', 'Referral Vendor Payment Terms', 'referralVendorPaymentTerms',
        ])),
        'primary_sales' => $primarySales,
        'account_manager' => $primarySales,
        'account_manager_commission_pct' => jobdivaAssignmentContractPercent($pick([
            'Primary Sales %', 'Primary Sales Percent', 'Primary Sales Split %', 'primarySalesPct',
        ])),
        'account_manager_allocation_pct' => jobdivaAssignmentContractPercent($pick([
            'PRISALE COMM PERCENT', 'PRISALE_COMM_PERCENT',
        ])),
        'secondary_sales' => trim((string) $pick(['Secondary Sales', 'secondarySales', 'secondary_sales'])),
        'tertiary_sales' => trim((string) $pick(['Tertiary Sales', 'tertiarySales', 'tertiary_sales'])),
        'primary_recruiter' => $primaryRecruiter,
        'recruiter_name' => $primaryRecruiter,
        'recruiter_commission_pct' => jobdivaAssignmentContractPercent($pick([
            'Primary Recruiter %', 'Primary Recruiter Percent', 'Primary Recruiter Split %', 'primaryRecruiterPct',
        ])),
        'recruiter_allocation_pct' => jobdivaAssignmentContractPercent($pick([
            'PRIREC COMM PERCENT', 'PRIREC_COMM_PERCENT',
        ])),
        'secondary_recruiter' => trim((string) $pick(['Secondary Recruiter', 'secondaryRecruiter', 'secondary_recruiter'])),
        'tertiary_recruiter' => trim((string) $pick(['Tertiary Recruiter', 'tertiaryRecruiter', 'tertiary_recruiter'])),
        'payroll_load_pct' => $overheadPct,
        'workers_comp_pct' => $workersCompPct,
        'benefits_load_pct' => $benefitsLoadPct,
        'other_cost_per_hour' => jobdivaAssignmentContractAmount($pick([
            'Other Cost Per Hour', 'Other Costs Per Hour', 'otherCostPerHour', 'other_cost_per_hour',
        ])),
        'other_cost_flat' => jobdivaAssignmentContractAmount($pick([
            'Fixed Costs', 'Fixed Cost', 'fixedCosts', 'other_cost_flat',
        ])),
        'overheads' => [
            'w2' => $w2,
            'c2c' => $c2c,
            'raw' => $overheadRaw,
            'payroll_load_pct' => $overheadPct,
            'workers_comp_pct' => $workersCompPct,
            'benefits_load_pct' => $benefitsLoadPct,
            'per_diem' => jobdivaAssignmentContractAmount($pick(['Per Diem', 'perDiem', 'per_diem'])),
            'other_expenses' => jobdivaAssignmentContractAmount($pick(['Other Expenses', 'otherExpenses', 'other_expenses'])),
            'outside_commission' => jobdivaAssignmentContractAmount($pick([
                'Outside Commission', 'outsideCommission', 'outside_commission',
            ])),
            'fixed_costs' => jobdivaAssignmentContractAmount($pick(['Fixed Costs', 'Fixed Cost', 'fixedCosts'])),
            'payroll_profile_id' => trim((string) $pick(['Payroll Profile ID', 'payrollProfileId', 'payroll_profile_id'])),
            'pass_through' => jobdivaAssignmentContractBool($pick(['Pass Through', 'passThrough', 'pass_through'])),
            'pass_discount' => jobdivaAssignmentContractBool($pick(['Pass Discount', 'passDiscount', 'pass_discount'])),
            'source_fields' => $overheadEntries,
        ],
    ];

    $prune = static function (mixed $value) use (&$prune): mixed {
        if (!is_array($value)) return $value;
        $out = [];
        foreach ($value as $key => $item) {
            $item = $prune($item);
            if ($item === null || $item === '' || (is_array($item) && $item === [])) continue;
            $out[$key] = $item;
        }
        return $out;
    };
    return $prune($contract);
}
