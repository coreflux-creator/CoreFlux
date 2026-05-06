<?php
/**
 * C1 — Worker-class routing helpers (Sprint 3 / Industry Layer 1: Staffing).
 *
 * Maps a person's `worker_class` to its downstream destinations when
 * approved time lands. Used by the Time→AR/AP/Payroll pipeline.
 *
 *   employee          → Payroll (W-2)
 *   w2_temp           → Payroll (W-2)  + Billing (AR)
 *   contractor_1099   → AP (1099 contractor pay) + Billing (AR)
 *   c2c               → AP (corp-to-corp bill to vendor) + Billing (AR)
 *   eor               → AP (EOR / agency invoice) + Billing (AR)
 *   referral          → AP (one-off referral fee), no time
 *   vendor_backed     → AP (vendor invoice)
 *
 * VERTICAL-AGNOSTIC FALLBACK: tenants that don't use staffing keep
 * `worker_class = 'employee'` and the function simply returns ['payroll'].
 */
declare(strict_types=1);

const PEOPLE_WORKER_CLASSES = [
    'employee',
    'w2_temp',
    'contractor_1099',
    'c2c',
    'eor',
    'referral',
    'vendor_backed',
];

/**
 * @return list<string>  one or more of: 'payroll', 'ap', 'ar'
 */
function peopleWorkerClassRouting(string $workerClass): array {
    switch ($workerClass) {
        case 'employee':         return ['payroll'];
        case 'w2_temp':          return ['payroll', 'ar'];
        case 'contractor_1099':  return ['ap', 'ar'];
        case 'c2c':              return ['ap', 'ar'];
        case 'eor':              return ['ap', 'ar'];
        case 'referral':         return ['ap'];
        case 'vendor_backed':    return ['ap'];
        default:                 return ['payroll'];
    }
}

/**
 * Whether a worker_class earns W-2 pay (Gusto run input vs AP bill).
 */
function peopleWorkerClassIsW2(string $workerClass): bool {
    return in_array($workerClass, ['employee', 'w2_temp'], true);
}

/**
 * Whether a worker_class generates billable time on the AR side.
 */
function peopleWorkerClassIsBillable(string $workerClass): bool {
    return in_array($workerClass, ['w2_temp', 'contractor_1099', 'c2c', 'eor'], true);
}

/**
 * Human label for UI / reports.
 */
function peopleWorkerClassLabel(string $workerClass): string {
    static $labels = [
        'employee'        => 'Employee (W-2)',
        'w2_temp'         => 'W-2 Temp',
        'contractor_1099' => '1099 Contractor',
        'c2c'             => 'Corp-to-Corp',
        'eor'             => 'EOR / Agency',
        'referral'        => 'Referral',
        'vendor_backed'   => 'Vendor-backed',
    ];
    return $labels[$workerClass] ?? $workerClass;
}
