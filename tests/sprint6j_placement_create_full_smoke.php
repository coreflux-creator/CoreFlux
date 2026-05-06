<?php
/**
 * Sprint 6j smoke — PlacementCreate Bundle C: full SPEC §3 coverage in form
 * + 'internal' engagement type + UX disabled-button affordances.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Migration 003 — internal engagement type\n";
$mig = (string) file_get_contents("{$ROOT}/modules/placements/migrations/003_internal_engagement_type.sql");
$assert('migration file exists',                strlen($mig) > 0);
$assert("'internal' added to ENUM",             stripos($mig, "'internal'") !== false);
$assert('uses MODIFY COLUMN',                   stripos($mig, 'MODIFY COLUMN') !== false);
$assert('targets placements table',             stripos($mig, 'ALTER TABLE placements') !== false);

echo "\nplacements.php API — accepts 'internal'\n";
$api = (string) file_get_contents("{$ROOT}/modules/placements/api/placements.php");
$assert('ALLOWED_ETYPE includes internal',      stripos($api, "'internal'") !== false
                                              && stripos($api, 'ALLOWED_ETYPE') !== false);

echo "\nPlacementCreate.jsx — Bundle C UI\n";
$ui = (string) file_get_contents("{$ROOT}/modules/placements/ui/PlacementCreate.jsx");
$assert('PlacementCreate.jsx exists',           strlen($ui) > 0);

// UX: required hint + missing-fields explanation
$assert('required-fields banner testid',        strpos($ui, 'data-testid="placement-create-required-hint"') !== false);
$assert('disabled button uses missing[] gate',  strpos($ui, 'missing.length > 0') !== false);
$assert('missing-fields hint rendered',         strpos($ui, 'data-testid="placement-create-missing-hint"') !== false);
$assert('required fields list (Person, Title, Start date, Engagement type)',
    strpos($ui, "'Person'") !== false
    && strpos($ui, "'Title'") !== false
    && strpos($ui, "'Start date'") !== false
    && strpos($ui, "'Engagement type'") !== false);
$assert('button has tooltip explaining disabled state',
    strpos($ui, 'Fill required:') !== false);

// Internal-hire toggle
$assert('internal-hire toggle testid',          strpos($ui, 'data-testid="placement-create-internal-toggle"') !== false);
$assert('internal toggle hides end-client + chain',
    strpos($ui, '!internalHire && (') !== false);
$assert('internal toggle clears endClient + chain',
    strpos($ui, 'setEndClient(null)') !== false
    && strpos($ui, 'setChain([])') !== false);
$assert("internal sets engagement_type = 'internal'",
    strpos($ui, "engagement_type: 'internal'") !== false);

// Engagement type list now has 'internal'
$assert("ETYPES includes 'internal'",            strpos($ui, "'internal'") !== false
                                               && strpos($ui, 'ETYPES') !== false);

// Rate fields: currency, units, adder, background
$assert('bill rate unit testid',                strpos($ui, 'data-testid="placement-create-rate-bill-unit"') !== false);
$assert('pay rate unit testid',                 strpos($ui, 'data-testid="placement-create-rate-pay-unit"') !== false);
$assert('currency testid',                      strpos($ui, 'data-testid="placement-create-rate-currency"') !== false);
$assert('adder_pct testid',                     strpos($ui, 'data-testid="placement-create-rate-adder"') !== false);
$assert('background fee testid',                strpos($ui, 'data-testid="placement-create-rate-bgfee"') !== false);
$assert('rate currency posted to API',          strpos($ui, "currency: rate.currency") !== false);
$assert('adder_pct converted to fraction',      strpos($ui, 'rate.adder_pct ? Number(rate.adder_pct) / 100') !== false);
$assert('bill_rate_unit + pay_rate_unit posted',
    strpos($ui, 'bill_rate_unit:') !== false
    && strpos($ui, 'pay_rate_unit:') !== false);

// Commissions inline rows
$assert('commission row testids',               strpos($ui, 'placement-create-commission-row-') !== false
                                              && strpos($ui, 'placement-create-commission-add') !== false);
$assert('commission posts to commissions API',  strpos($ui, "/modules/placements/api/commissions.php") !== false);
$assert('commission split_pct → fraction',      strpos($ui, 'c.split_pct ? Number(c.split_pct) / 100') !== false);
$assert('commission roles defined',             strpos($ui, "'account_manager'") !== false
                                              && strpos($ui, "'recruiter'") !== false
                                              && strpos($ui, "'team'") !== false);

// Referral row
$assert('referral add/remove testids',          strpos($ui, 'placement-create-referral-add') !== false
                                              && strpos($ui, 'placement-create-referral-remove') !== false);
$assert('referrer types vendor/person/user',    strpos($ui, "'vendor'") !== false
                                              && strpos($ui, "'person'") !== false
                                              && strpos($ui, "'user'") !== false
                                              && strpos($ui, 'REFERRER_TYPES') !== false);
$assert('fee_basis dropdown testid',            strpos($ui, 'data-testid="placement-create-referral-basis"') !== false);
$assert('fee bases per_hour/per_invoice/one_time/pct_bill/pct_margin',
    strpos($ui, "'per_hour'") !== false
    && strpos($ui, "'per_invoice'") !== false
    && strpos($ui, "'one_time'") !== false
    && strpos($ui, "'pct_bill'") !== false
    && strpos($ui, "'pct_margin'") !== false);
$assert('referral posts to referrals API',      strpos($ui, "/modules/placements/api/referrals.php") !== false);
$assert('referral fee_pct → fraction',          preg_match('#fee_pct\s*:\s*referral\.fee_pct\s*\?\s*Number\(referral\.fee_pct\)\s*/\s*100#', $ui) === 1);

// C2C corp full coverage
$assert('corp address fields',                  strpos($ui, 'placement-create-corp-addr1') !== false
                                              && strpos($ui, 'placement-create-corp-city') !== false
                                              && strpos($ui, 'placement-create-corp-state') !== false
                                              && strpos($ui, 'placement-create-corp-postal') !== false
                                              && strpos($ui, 'placement-create-corp-country') !== false);
$assert('corp contact fields',                  strpos($ui, 'placement-create-corp-contact-name') !== false
                                              && strpos($ui, 'placement-create-corp-contact-email') !== false
                                              && strpos($ui, 'placement-create-corp-contact-phone') !== false);
$assert('corp gated to engagement_type=c2c',   strpos($ui, "form.engagement_type === 'c2c'") !== false);
$assert('corp posts to corp API',               strpos($ui, "/modules/placements/api/corp.php") !== false);

// Advanced toggle
$assert('show-advanced toggle testid',          strpos($ui, 'data-testid="placement-create-toggle-advanced"') !== false);
$assert('advanced section is collapsible',      strpos($ui, 'showAdvanced && (') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
