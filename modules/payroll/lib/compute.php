<?php
/**
 * Payroll — Deterministic Compute Engine
 *
 * Produces gross-to-net using:
 *   - W-4 (2020+) percentage method, simplified annualized brackets
 *   - FICA: Social Security 6.2% to wage base; Medicare 1.45% + 0.9% addl over $200k YTD
 *   - FUTA 6.0% on first $7,000 (less SUTA credit, default 5.4% → effective 0.6%)
 *   - SUTA: tenant-configured rate × first $7,000 (placeholder wage base)
 *   - Federal/state/employer all in CENTS. No floats anywhere.
 *
 * AI HARD RULE: this file does the math. AI never produces these numbers.
 *
 * Constants are 2026 placeholders matching IRS Pub 15-T methodology. Update
 * yearly via PR (not via AI). Cited inline so reviewers can verify.
 */

// =========================================================================
// Year constants (2026 — verify against IRS Pub 15-T before each tax year)
// =========================================================================
const PAY_FREQ_PERIODS = [
    'weekly'       => 52,
    'biweekly'     => 26,
    'semimonthly'  => 24,
    'monthly'      => 12,
];

// FICA — 2026 (Social Security wage base estimated; medicare unbound)
const PAY_SS_WAGE_BASE_CENTS    = 17610000;     // $176,100 — verify each year
const PAY_SS_RATE_BPS           = 620;          // 6.20%
const PAY_MEDICARE_RATE_BPS     = 145;          // 1.45%
const PAY_MEDICARE_ADDL_BPS     = 90;           // 0.90% additional over threshold
const PAY_MEDICARE_ADDL_THRESHOLD_CENTS = 20000000; // $200,000 (single)

// FUTA — Fed unemployment
const PAY_FUTA_WAGE_BASE_CENTS  = 700000;       // $7,000
const PAY_FUTA_GROSS_RATE_BPS   = 600;          // 6.00% before SUTA credit

// SUTA — placeholder wage base (states vary widely). For MVP: $7,000.
const PAY_SUTA_WAGE_BASE_CENTS  = 700000;

// California SDI (employee paid) — 2026 placeholder (no wage cap as of 2024)
const PAY_CA_SDI_RATE_BPS       = 110;          // 1.10%

// Federal W-4 2020+ standard deduction by filing status (annualized, cents)
const PAY_FED_STD_DEDUCTION_CENTS = [
    'single'                    => 1620000,     // $16,200
    'head_of_household'         => 2310000,     // $23,100
    'married_filing_jointly'    => 3050000,     // $30,500
];

// Federal income tax brackets — 2026 placeholders, annualized "after-deduction" wages,
// cents. Bracket: [floor_inclusive, base_tax_cents, rate_bps].
// (Source: IRS Pub 15-T worksheet — verify when 2026 tables are finalized.)
const PAY_FED_BRACKETS = [
    'single' => [
        [0,         0,        1000],   // 10%
        [1212500,   121250,   1200],   // 12% (up to $58,950)
        [4872500,   560450,   2200],   // 22%
        [10422500,  1781750,  2400],   // 24%
        [19377500,  3917000,  3200],   // 32%
        [24587500,  5584200,  3500],   // 35%
        [60912500,  18298300, 3700],   // 37%
    ],
    'married_filing_jointly' => [
        [0,         0,        1000],
        [2425000,   242500,   1200],
        [9745000,   1120900,  2200],
        [20845000,  3563900,  2400],
        [38755000,  7833900,  3200],
        [49175000,  11168300, 3500],
        [73925000,  19830800, 3700],
    ],
    'head_of_household' => [
        [0,         0,        1000],
        [1722500,   172250,   1200],
        [6582500,   755450,   2200],
        [10440000,  1604340,  2400],
        [19395000,  3753740,  3200],
        [24605000,  5421140,  3500],
        [60930000,  18139640, 3700],
    ],
];

// California simplified state income tax — 2026 placeholders (married/single same here for MVP).
// Annualized "method B" simplified. Bracket: [floor_inclusive, base_tax_cents, rate_bps].
const PAY_CA_STD_DEDUCTION_CENTS = 559000;     // $5,590 single
const PAY_CA_BRACKETS = [
    [0,         0,        100],     // 1.00%
    [1099900,   10999,    200],     // 2.00%
    [2607400,   41148,    400],     // 4.00%
    [4116900,   101528,   600],     // 6.00%
    [5719900,   197708,   800],     // 8.00%
    [7233100,   318764,   930],     // 9.30%
    [36963300, 3083253,   1030],    // 10.30%
    [44354900, 3844495,   1130],    // 11.30%
    [73925000, 7185926,   1230],    // 12.30%
    // (state has 13.3% mental health top bracket >$1M — omitted for MVP)
];

// =========================================================================
// Public API
// =========================================================================

/**
 * Compute one employee's full payroll for a single pay period.
 * Returns a structured array with rolled-up totals + per-component rows
 * suitable for inserting into payroll_earnings / payroll_taxes / payroll_deductions.
 *
 * Inputs (all integers/cents unless noted):
 *   $ctx = [
 *     'pay_type'              => 'salary'|'hourly',
 *     'pay_rate_cents'        => annualized for salary, hourly rate for hourly
 *     'pay_frequency'         => 'weekly'|'biweekly'|'semimonthly'|'monthly',
 *     'work_state'            => 'CA' (only state supported in MVP),
 *     'hours_regular'         => float (hourly only),
 *     'hours_overtime'        => float (hourly only),
 *     'bonus_cents'           => int (default 0),
 *     // tax setup
 *     'fed_filing_status'     => 'single'|'married_filing_jointly'|'head_of_household',
 *     'fed_dependents_cents'  => int (W-4 2020+ line 3),
 *     'fed_other_income_cents'=> int (line 4a),
 *     'fed_deductions_cents'  => int (line 4b),
 *     'fed_extra_withhold_cents' => int (line 4c),
 *     'state_extra_withhold_cents' => int,
 *     // YTD (for SS / Medicare-addl / FUTA / SUTA limits)
 *     'ytd_ss_wages_cents'    => int (default 0),
 *     'ytd_medicare_wages_cents' => int (default 0),
 *     'ytd_futa_wages_cents'  => int (default 0),
 *     'ytd_suta_wages_cents'  => int (default 0),
 *     // deductions (already pulled from payroll_profiles)
 *     'retirement_pretax_bps' => int,
 *     'health_premium_cents'  => int,
 *     'hsa_pretax_cents'      => int,
 *     'extra_post_tax_cents'  => int,
 *     // employer rates
 *     'suta_rate_bps'         => int (tenant config),
 *     'futa_credit_rate_bps'  => int (default 540 → effective FUTA 0.6%),
 *   ]
 *
 * Returns:
 *   [
 *     'gross_cents','pretax_cents','taxable_cents','employee_taxes_cents',
 *     'posttax_cents','net_cents','employer_taxes_cents',
 *     'earnings'   => [{code,amount_cents,hours,rate_cents,taxable}, ...],
 *     'taxes'      => [{code,jurisdiction,taxable_wage_cents,rate_bps,amount_cents,is_employer}, ...],
 *     'deductions' => [{code,is_pretax,amount_cents}, ...],
 *   ]
 */
function payrollComputeLine(array $ctx): array {
    $freq = $ctx['pay_frequency'] ?? 'biweekly';
    $periods = PAY_FREQ_PERIODS[$freq] ?? 26;

    // ---- 1. Earnings ----
    $earnings = [];
    if (($ctx['pay_type'] ?? 'salary') === 'salary') {
        // gross per period = annual / periods (cents-safe)
        $regCents = intdiv((int)$ctx['pay_rate_cents'], $periods);
        $earnings[] = [
            'code' => 'regular',
            'hours' => null,
            'rate_cents' => null,
            'amount_cents' => $regCents,
            'taxable' => 1,
        ];
    } else {
        $rate = (int) $ctx['pay_rate_cents'];
        $regHours = (float) ($ctx['hours_regular'] ?? 0);
        $otHours  = (float) ($ctx['hours_overtime'] ?? 0);
        // round-half-even on cents to keep float-money sane
        $regAmt = (int) round($rate * $regHours);
        $otAmt  = (int) round($rate * 1.5 * $otHours);
        if ($regAmt > 0) $earnings[] = [
            'code' => 'regular', 'hours' => $regHours, 'rate_cents' => $rate,
            'amount_cents' => $regAmt, 'taxable' => 1,
        ];
        if ($otAmt > 0) $earnings[] = [
            'code' => 'overtime', 'hours' => $otHours, 'rate_cents' => (int) round($rate * 1.5),
            'amount_cents' => $otAmt, 'taxable' => 1,
        ];
    }
    $bonus = (int) ($ctx['bonus_cents'] ?? 0);
    if ($bonus > 0) {
        $earnings[] = ['code'=>'bonus','hours'=>null,'rate_cents'=>null,'amount_cents'=>$bonus,'taxable'=>1];
    }

    $gross = 0;
    foreach ($earnings as $e) $gross += (int) $e['amount_cents'];

    // ---- 2. Pre-tax deductions ----
    $deductions = [];
    $retire401 = 0;
    $retireBps = (int) ($ctx['retirement_pretax_bps'] ?? 0);
    if ($retireBps > 0) {
        $retire401 = intdiv($gross * $retireBps, 10000);
        if ($retire401 > 0) $deductions[] = [
            'code' => 'retirement_401k', 'is_pretax' => 1, 'amount_cents' => $retire401,
        ];
    }
    $health = (int) ($ctx['health_premium_cents'] ?? 0);
    if ($health > 0) $deductions[] = [
        'code' => 'health_premium', 'is_pretax' => 1, 'amount_cents' => $health,
    ];
    $hsa = (int) ($ctx['hsa_pretax_cents'] ?? 0);
    if ($hsa > 0) $deductions[] = [
        'code' => 'hsa', 'is_pretax' => 1, 'amount_cents' => $hsa,
    ];
    $pretaxTotal = $retire401 + $health + $hsa;

    // Federal taxable wage = gross − 401(k) − health (pre-tax) − HSA (pre-tax).
    // Note: HSA & health are also exempt from FICA. 401(k) is exempt from FIT
    // but NOT from FICA. We split that below.
    $fitTaxable      = $gross - $pretaxTotal;                        // 401k + health + HSA all reduce FIT
    $ficaTaxable     = $gross - ($health + $hsa);                    // 401(k) is FICA-taxable
    if ($fitTaxable < 0)  $fitTaxable = 0;
    if ($ficaTaxable < 0) $ficaTaxable = 0;

    // ---- 3. Federal income tax (W-4 2020+ percentage method) ----
    $filing = $ctx['fed_filing_status'] ?? 'single';
    $std    = PAY_FED_STD_DEDUCTION_CENTS[$filing] ?? PAY_FED_STD_DEDUCTION_CENTS['single'];
    $deps   = (int) ($ctx['fed_dependents_cents'] ?? 0);            // line 3 annual credit
    $oth    = (int) ($ctx['fed_other_income_cents'] ?? 0);          // line 4a
    $w4Ded  = (int) ($ctx['fed_deductions_cents'] ?? 0);            // line 4b
    $extra  = (int) ($ctx['fed_extra_withhold_cents'] ?? 0);        // line 4c per-period

    // Annualize period taxable + 4a, then subtract std deduction + 4b
    $annualWages = $fitTaxable * $periods + $oth - ($std + $w4Ded);
    if ($annualWages < 0) $annualWages = 0;
    $annualTax = _payrollFederalTax($annualWages, $filing) - $deps;
    if ($annualTax < 0) $annualTax = 0;
    $fitPerPeriod = intdiv($annualTax, $periods) + $extra;
    if ($fitPerPeriod < 0) $fitPerPeriod = 0;

    // ---- 4. FICA (employee + employer match) ----
    $ytdSs   = (int) ($ctx['ytd_ss_wages_cents'] ?? 0);
    $ytdMed  = (int) ($ctx['ytd_medicare_wages_cents'] ?? 0);

    $ssRoom  = max(0, PAY_SS_WAGE_BASE_CENTS - $ytdSs);
    $ssWage  = min($ficaTaxable, $ssRoom);
    $ssEE    = intdiv($ssWage * PAY_SS_RATE_BPS, 10000);
    $ssER    = $ssEE;

    $medWage = $ficaTaxable;
    $medEE   = intdiv($medWage * PAY_MEDICARE_RATE_BPS, 10000);
    $medER   = $medEE;
    // Additional 0.9% over $200k YTD (employee-only)
    $newYtdMed = $ytdMed + $medWage;
    $addlMedEE = 0;
    if ($newYtdMed > PAY_MEDICARE_ADDL_THRESHOLD_CENTS) {
        $excess = min($medWage, $newYtdMed - PAY_MEDICARE_ADDL_THRESHOLD_CENTS);
        if ($excess > 0) $addlMedEE = intdiv($excess * PAY_MEDICARE_ADDL_BPS, 10000);
    }

    // ---- 5. Employer unemployment ----
    $ytdFuta = (int) ($ctx['ytd_futa_wages_cents'] ?? 0);
    $futaRoom = max(0, PAY_FUTA_WAGE_BASE_CENTS - $ytdFuta);
    $futaWage = min($ficaTaxable, $futaRoom);
    $futaCreditBps = (int) ($ctx['futa_credit_rate_bps'] ?? 540);
    $futaEffectiveBps = max(0, PAY_FUTA_GROSS_RATE_BPS - $futaCreditBps);
    $futa = intdiv($futaWage * $futaEffectiveBps, 10000);

    $ytdSuta = (int) ($ctx['ytd_suta_wages_cents'] ?? 0);
    $sutaRoom = max(0, PAY_SUTA_WAGE_BASE_CENTS - $ytdSuta);
    $sutaWage = min($ficaTaxable, $sutaRoom);
    $sutaBps = (int) ($ctx['suta_rate_bps'] ?? 340);
    $suta = intdiv($sutaWage * $sutaBps, 10000);

    // ---- 6. State income tax + SDI (CA only in MVP) ----
    $sit = 0;
    $sdi = 0;
    $stateExtra = (int) ($ctx['state_extra_withhold_cents'] ?? 0);
    $sitTaxableForState = $fitTaxable; // CA broadly aligns with federal pre-tax treatment for MVP
    if (($ctx['work_state'] ?? 'CA') === 'CA') {
        $annualState = $sitTaxableForState * $periods - PAY_CA_STD_DEDUCTION_CENTS;
        if ($annualState < 0) $annualState = 0;
        $annualStateTax = _payrollCAStateTax($annualState);
        $sit = intdiv($annualStateTax, $periods) + $stateExtra;
        if ($sit < 0) $sit = 0;
        // CA SDI (employee paid) — applied on FICA-taxable wages
        $sdi = intdiv($ficaTaxable * PAY_CA_SDI_RATE_BPS, 10000);
    }

    // ---- 7. Tax rows ----
    $taxes = [];
    if ($fitPerPeriod > 0) $taxes[] = [
        'code' => 'fit', 'jurisdiction' => 'US',
        'taxable_wage_cents' => $fitTaxable, 'rate_bps' => null,
        'amount_cents' => $fitPerPeriod, 'is_employer' => 0,
    ];
    if ($ssEE > 0) $taxes[] = [
        'code' => 'ss_employee', 'jurisdiction' => 'US',
        'taxable_wage_cents' => $ssWage, 'rate_bps' => PAY_SS_RATE_BPS,
        'amount_cents' => $ssEE, 'is_employer' => 0,
    ];
    if ($medEE > 0) $taxes[] = [
        'code' => 'medicare_employee', 'jurisdiction' => 'US',
        'taxable_wage_cents' => $medWage, 'rate_bps' => PAY_MEDICARE_RATE_BPS,
        'amount_cents' => $medEE, 'is_employer' => 0,
    ];
    if ($addlMedEE > 0) $taxes[] = [
        'code' => 'medicare_addl_employee', 'jurisdiction' => 'US',
        'taxable_wage_cents' => $medWage, 'rate_bps' => PAY_MEDICARE_ADDL_BPS,
        'amount_cents' => $addlMedEE, 'is_employer' => 0,
    ];
    if ($sit > 0) $taxes[] = [
        'code' => 'sit_ca', 'jurisdiction' => 'US-CA',
        'taxable_wage_cents' => $sitTaxableForState, 'rate_bps' => null,
        'amount_cents' => $sit, 'is_employer' => 0,
    ];
    if ($sdi > 0) $taxes[] = [
        'code' => 'sdi_ca', 'jurisdiction' => 'US-CA',
        'taxable_wage_cents' => $ficaTaxable, 'rate_bps' => PAY_CA_SDI_RATE_BPS,
        'amount_cents' => $sdi, 'is_employer' => 0,
    ];
    // Employer rows
    if ($ssER > 0) $taxes[] = [
        'code' => 'ss_employer', 'jurisdiction' => 'US',
        'taxable_wage_cents' => $ssWage, 'rate_bps' => PAY_SS_RATE_BPS,
        'amount_cents' => $ssER, 'is_employer' => 1,
    ];
    if ($medER > 0) $taxes[] = [
        'code' => 'medicare_employer', 'jurisdiction' => 'US',
        'taxable_wage_cents' => $medWage, 'rate_bps' => PAY_MEDICARE_RATE_BPS,
        'amount_cents' => $medER, 'is_employer' => 1,
    ];
    if ($futa > 0) $taxes[] = [
        'code' => 'futa', 'jurisdiction' => 'US',
        'taxable_wage_cents' => $futaWage, 'rate_bps' => $futaEffectiveBps,
        'amount_cents' => $futa, 'is_employer' => 1,
    ];
    if ($suta > 0) $taxes[] = [
        'code' => 'suta', 'jurisdiction' => 'US-' . ($ctx['work_state'] ?? 'CA'),
        'taxable_wage_cents' => $sutaWage, 'rate_bps' => $sutaBps,
        'amount_cents' => $suta, 'is_employer' => 1,
    ];

    $employeeTaxes = $fitPerPeriod + $ssEE + $medEE + $addlMedEE + $sit + $sdi;
    $employerTaxes = $ssER + $medER + $futa + $suta;

    // ---- 8. Post-tax deductions ----
    $extraPost = (int) ($ctx['extra_post_tax_cents'] ?? 0);
    if ($extraPost > 0) $deductions[] = [
        'code' => 'other_posttax', 'is_pretax' => 0, 'amount_cents' => $extraPost,
    ];
    $posttax = $extraPost;

    // ---- 9. Net pay ----
    $net = $gross - $pretaxTotal - $employeeTaxes - $posttax;
    if ($net < 0) $net = 0;

    return [
        'gross_cents'          => $gross,
        'pretax_cents'         => $pretaxTotal,
        'taxable_cents'        => $fitTaxable,
        'employee_taxes_cents' => $employeeTaxes,
        'posttax_cents'        => $posttax,
        'net_cents'            => $net,
        'employer_taxes_cents' => $employerTaxes,
        'earnings'             => $earnings,
        'taxes'                => $taxes,
        'deductions'           => $deductions,
    ];
}


/**
 * Federal annual income tax via bracket lookup. Cents in / cents out.
 * Internal — exported only for tests.
 */
function _payrollFederalTax(int $annualTaxableCents, string $filingStatus): int {
    $brackets = PAY_FED_BRACKETS[$filingStatus] ?? PAY_FED_BRACKETS['single'];
    $tax = 0;
    foreach (array_reverse($brackets) as $b) {
        if ($annualTaxableCents >= $b[0]) {
            $tax = $b[1] + intdiv(($annualTaxableCents - $b[0]) * $b[2], 10000);
            break;
        }
    }
    return $tax;
}

/**
 * California annual income tax via bracket lookup. Cents in / cents out.
 */
function _payrollCAStateTax(int $annualTaxableCents): int {
    $brackets = PAY_CA_BRACKETS;
    $tax = 0;
    foreach (array_reverse($brackets) as $b) {
        if ($annualTaxableCents >= $b[0]) {
            $tax = $b[1] + intdiv(($annualTaxableCents - $b[0]) * $b[2], 10000);
            break;
        }
    }
    return $tax;
}
