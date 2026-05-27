<?php
/**
 * Smoke — AP 3-way match HARD enforcement (P1.6).
 *
 * Spec re-audit decision: 3-way match is a HARD rule, not a soft warn.
 * Bills failing PO + receipt + bill reconciliation cannot be approved
 * without an explicit override + mandatory reason. Override is
 * audit-logged.
 *
 * Asserts:
 *   1. Migration 079 flips ap_three_way_match_enforce default to 1
 *      AND bulk-lifts existing tenants.
 *   2. apThreeWayMatch() defaults enforce=1 when the tenant config
 *      row is missing (was 0 before this fork).
 *   3. The bills.php approve action calls apThreeWayMatch(), refuses
 *      with HTTP 409 + code='3wm_block' when warnings present and
 *      enforce=true UNLESS three_way_match_override=true AND a
 *      mandatory three_way_match_override_reason is supplied.
 *   4. Override is audit-logged via apAudit('ap.bill.three_way_match_override').
 *   5. Gate fires BEFORE the UPDATE that flips bill status to 'approved'.
 *   6. PHP syntax stays clean.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$m   = (string) file_get_contents('/app/core/migrations/079_three_way_match_hard_default.sql');
$lib = (string) file_get_contents('/app/modules/ap/lib/three_way_match.php');
$api = (string) file_get_contents('/app/modules/ap/api/bills.php');

echo "\n1. Migration 079 — flips default + lifts existing tenants\n";
$a('alters column default to 1',
    str_contains($m, 'MODIFY COLUMN ap_three_way_match_enforce TINYINT(1) NOT NULL DEFAULT 1'));
$a('bulk-lifts existing tenants',
    str_contains($m, 'UPDATE tenants
   SET ap_three_way_match_enforce = 1
 WHERE ap_three_way_match_enforce = 0;'));

echo "\n2. Library default fallback flipped to enforce=1\n";
$a('fallback row defaults ap_three_way_match_enforce to 1',
    str_contains($lib, "'ap_three_way_match_enforce' => 1, 'ap_three_way_match_tolerance_pct' => 5.0"));
$a('null-coalesce on enforce defaults to 1',
    str_contains($lib, "(int) (\$cfg['ap_three_way_match_enforce'] ?? 1)"));

echo "\n3. Approve path enforces the gate\n";
$a('approve action requires the 3wm library',
    str_contains($api, "require_once __DIR__ . '/../lib/three_way_match.php';"));
$a('approve calls apThreeWayMatch',
    str_contains($api, '$match = apThreeWayMatch($tid, $id);'));
$a('refuses when warnings present and enforce=true',
    str_contains($api, "if (!empty(\$match['warnings']) && !empty(\$match['enforce']))"));
$a('reads three_way_match_override + reason from body',
    str_contains($api, "\$overrideOk    = !empty(\$body['three_way_match_override']);")
    && str_contains($api, "\$overrideReason = trim((string) (\$body['three_way_match_override_reason'] ?? ''));"));
$a('returns HTTP 409 + code=3wm_block + warnings/totals',
    str_contains($api, "'code'                  => '3wm_block'")
    && str_contains($api, "'warnings'              => \$match['warnings']")
    && str_contains($api, "'po_total'              => \$match['po_total']")
    && str_contains($api, "'receipt_total'         => \$match['receipt_total']")
    && str_contains($api, "'bill_total'            => \$match['bill_total']"));
$a('mandatory override reason — empty string is refused',
    str_contains($api, "if (!\$overrideOk || \$overrideReason === '')"));
$a('override is audit-logged with reason + warnings',
    str_contains($api, "apAudit('ap.bill.three_way_match_override', [")
    && str_contains($api, "'warnings'  => \$match['warnings']")
    && str_contains($api, "'reason'    => \$overrideReason"));

echo "\n4. Gate fires before the bill status flips to 'approved'\n";
$a('match check appears BEFORE the UPDATE',
    strpos($api, '$match = apThreeWayMatch($tid, $id);')
    < strpos($api, 'UPDATE ap_bills SET status = "approved"'));

echo "\n5. PHP syntax\n";
foreach ([
    '/app/modules/ap/lib/three_way_match.php',
    '/app/modules/ap/api/bills.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "AP 3-way match HARD enforcement smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
