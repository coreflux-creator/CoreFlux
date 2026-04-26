<?php
/**
 * Payroll Compute Smoke Test
 *
 * No DB required — exercises the deterministic gross-to-net engine with a
 * handful of canonical scenarios. Run with:
 *   php /app/tests/payroll_compute_smoke.php
 *
 * Each assertion compares INTEGER cents. Floats are not used anywhere in the
 * compute path, so results are reproducible across hosts.
 */

declare(strict_types=1);

require_once __DIR__ . '/../modules/payroll/lib/compute.php';

$pass = 0; $fail = 0;
$assert = function(string $what, bool $cond) use (&$pass, &$fail) {
    if ($cond) { $pass++; echo "  ✓ $what\n"; }
    else        { $fail++; echo "  ✗ $what\n"; }
};
$between = function(int $value, int $low, int $high): bool {
    return $value >= $low && $value <= $high;
};

// =========================================================================
// Scenario 1 — California salaried, biweekly, single, no deductions
// =========================================================================
echo "Scenario 1: $80,000 salary, single, biweekly, CA, no deductions\n";
$ctx = [
    'pay_type'                  => 'salary',
    'pay_rate_cents'            => 8000000,        // $80,000
    'pay_frequency'             => 'biweekly',
    'work_state'                => 'CA',
    'fed_filing_status'         => 'single',
    'fed_dependents_cents'      => 0,
    'fed_other_income_cents'    => 0,
    'fed_deductions_cents'      => 0,
    'fed_extra_withhold_cents'  => 0,
    'state_extra_withhold_cents'=> 0,
    'ytd_ss_wages_cents'        => 0,
    'ytd_medicare_wages_cents'  => 0,
    'ytd_futa_wages_cents'      => 0,
    'ytd_suta_wages_cents'      => 0,
    'retirement_pretax_bps'     => 0,
    'health_premium_cents'      => 0,
    'hsa_pretax_cents'          => 0,
    'extra_post_tax_cents'      => 0,
    'suta_rate_bps'             => 340,
    'futa_credit_rate_bps'      => 540,
];
$r = payrollComputeLine($ctx);

// Gross per period: 80000/26 ≈ $3076.92  → 307692 cents (intdiv truncates to 307692)
$assert("gross == \$3,076.92 (307692 cents)", $r['gross_cents'] === 307692);

// SS: 6.2% of gross
$expectedSS = intdiv(307692 * 620, 10000); // 19076 cents = $190.76
$ssRow = current(array_filter($r['taxes'], fn($t) => $t['code'] === 'ss_employee'));
$assert("SS employee = \$190.76 (19076 cents)", $ssRow && $ssRow['amount_cents'] === $expectedSS);

// Medicare: 1.45%
$expectedMed = intdiv(307692 * 145, 10000); // 4461 cents = $44.61
$medRow = current(array_filter($r['taxes'], fn($t) => $t['code'] === 'medicare_employee'));
$assert("Medicare employee = \$44.61 (4461 cents)", $medRow && $medRow['amount_cents'] === $expectedMed);

// FIT should land in a sensible range for $80k single
$fitRow = current(array_filter($r['taxes'], fn($t) => $t['code'] === 'fit'));
$fit = $fitRow ? $fitRow['amount_cents'] : 0;
$assert("FIT in \$200-\$500 per biweekly ($fit cents)", $between($fit, 20000, 50000));

// Net pay > 0 and < gross
$assert("net 0 < net < gross", $r['net_cents'] > 0 && $r['net_cents'] < $r['gross_cents']);

// Taxes accounting check
$totalTaxes = array_sum(array_map(fn($t) => $t['amount_cents'], array_filter($r['taxes'], fn($t) => !$t['is_employer'])));
$assert("sum of employee tax rows == employee_taxes_cents", $totalTaxes === $r['employee_taxes_cents']);
$assert("net == gross - pretax - employee_taxes - posttax",
    $r['net_cents'] === $r['gross_cents'] - $r['pretax_cents'] - $r['employee_taxes_cents'] - $r['posttax_cents']);

// =========================================================================
// Scenario 2 — Hourly with overtime and 401(k) + health
// =========================================================================
echo "\nScenario 2: \$30/hr × 80 reg + 5 OT, biweekly, CA, MFJ, 5% 401(k), \$200 health\n";
$ctx2 = $ctx;
$ctx2['pay_type']            = 'hourly';
$ctx2['pay_rate_cents']      = 3000;          // $30/hr
$ctx2['hours_regular']       = 80.0;
$ctx2['hours_overtime']      = 5.0;
$ctx2['fed_filing_status']   = 'married_filing_jointly';
$ctx2['retirement_pretax_bps'] = 500;          // 5%
$ctx2['health_premium_cents']  = 20000;        // $200
$ctx2['hsa_pretax_cents']      = 5000;         // $50

$r2 = payrollComputeLine($ctx2);

// Gross = 30*80 + 30*1.5*5 = 2400 + 225 = 2625
$assert("gross == \$2,625.00 (262500 cents)", $r2['gross_cents'] === 262500);

// 401(k) = 5% of gross = 13125 cents
$retRow = current(array_filter($r2['deductions'], fn($d) => $d['code'] === 'retirement_401k'));
$assert("401(k) pre-tax = \$131.25 (13125 cents)", $retRow && $retRow['amount_cents'] === 13125);

// Pre-tax total = 401k + health + HSA = 13125 + 20000 + 5000 = 38125
$assert("pretax total = \$381.25 (38125 cents)", $r2['pretax_cents'] === 38125);

// FICA wages = gross - health - HSA = 262500 - 25000 = 237500 (401k IS FICA-taxable)
$ssRow2 = current(array_filter($r2['taxes'], fn($t) => $t['code'] === 'ss_employee'));
$expectedSS2 = intdiv(237500 * 620, 10000);
$assert("SS on FICA-wages 237500 (excludes health+HSA but not 401k)", $ssRow2 && $ssRow2['amount_cents'] === $expectedSS2);

$assert("net positive and < gross", $r2['net_cents'] > 0 && $r2['net_cents'] < $r2['gross_cents']);

// =========================================================================
// Scenario 3 — SS wage base cap kicks in
// =========================================================================
echo "\nScenario 3: high earner past SS wage base — no SS deducted\n";
$ctx3 = $ctx;
$ctx3['ytd_ss_wages_cents'] = PAY_SS_WAGE_BASE_CENTS;  // already at cap
$r3 = payrollComputeLine($ctx3);
$ssRow3 = current(array_filter($r3['taxes'], fn($t) => $t['code'] === 'ss_employee'));
$assert("SS = 0 once YTD already at wage base", $ssRow3 === false || $ssRow3 === null);

// Medicare still applies (no cap)
$medRow3 = current(array_filter($r3['taxes'], fn($t) => $t['code'] === 'medicare_employee'));
$assert("Medicare still > 0 (no cap)", $medRow3 && $medRow3['amount_cents'] > 0);

// =========================================================================
// Scenario 4 — Medicare additional 0.9% over $200k YTD
// =========================================================================
echo "\nScenario 4: Medicare additional 0.9% kicks in over \$200k YTD\n";
$ctx4 = $ctx;
$ctx4['ytd_medicare_wages_cents'] = 19950000; // $199,500 YTD — next period will cross $200k
$r4 = payrollComputeLine($ctx4);
$addlRow = current(array_filter($r4['taxes'], fn($t) => $t['code'] === 'medicare_addl_employee'));
$assert("medicare_addl_employee row present when crossing \$200k",
    $addlRow && $addlRow['amount_cents'] > 0);

// =========================================================================
// Scenario 5 — Employer FUTA capped at $7,000 wage base
// =========================================================================
echo "\nScenario 5: FUTA stops once \$7,000 wage base reached\n";
$ctx5 = $ctx;
$ctx5['ytd_futa_wages_cents'] = PAY_FUTA_WAGE_BASE_CENTS;
$r5 = payrollComputeLine($ctx5);
$futaRow = current(array_filter($r5['taxes'], fn($t) => $t['code'] === 'futa'));
$assert("FUTA = 0 once at wage base", $futaRow === false || $futaRow === null);

// =========================================================================
echo "\n";
echo "Total: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
