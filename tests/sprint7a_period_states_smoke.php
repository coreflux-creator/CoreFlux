<?php
/**
 * Sprint 7a smoke — period state machine extension (spec §6).
 *
 * Verifies:
 *   - migration 011 adds 'locked' to the enum + 4 audit columns
 *   - periods.php accepts the 'lock' action with reason
 *   - reopen from 'locked' is gated to master_admin
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');

echo "Migration 011 — period_states\n";
$mig = (string) file_get_contents("{$ROOT}/modules/accounting/migrations/011_period_states.sql");
$assert('migration exists',                            strlen($mig) > 0);
$assert("status enum extended with 'locked'",          stripos($mig, "'locked'") !== false);
$assert('full enum order present',                     stripos($mig, "ENUM('future','open','soft_closed','closed','reopened','locked')") !== false);
$assert('closed_at column guard',                      stripos($mig, "column_name = 'closed_at'") !== false);
$assert('closed_by_user_id column guard',              stripos($mig, "column_name = 'closed_by_user_id'") !== false);
$assert('locked_at column guard',                      stripos($mig, "column_name = 'locked_at'") !== false);
$assert('locked_by_user_id column guard',              stripos($mig, "column_name = 'locked_by_user_id'") !== false);
$assert('uses information_schema for idempotency',     stripos($mig, 'information_schema.columns') !== false);

echo "\nperiods.php — lock action\n";
$api = (string) file_get_contents("{$ROOT}/modules/accounting/api/periods.php");
$assert("api whitelists 'lock' action",                stripos($api, "in_array(\$action, ['soft_close','close','lock','reopen']") !== false);
$assert('lock requires reason',                        stripos($api, 'reason required to lock a period') !== false);
$assert('lock requires period status=closed',          stripos($api, "Period must be 'closed' before lock") !== false);
$assert('lock writes locked_at/locked_by_user_id',     stripos($api, 'locked_at = :ts') !== false
                                                     && stripos($api, 'locked_by_user_id = :u') !== false);
$assert("audit event emitted: accounting.period.locked", stripos($api, "'accounting.period.locked'") !== false);

echo "\nperiods.php — reopen handles locked + gates to master_admin\n";
$assert('reopen status whitelist now includes locked', stripos($api, "['closed','soft_closed','locked']") !== false);
$assert('locked reopen is master_admin only',          stripos($api, "Only master_admin can reopen a locked period") !== false);

echo "\nperiods.php — perm wiring includes accounting.period.lock\n";
$assert('lock perm wired',                             stripos($api, "'accounting.period.lock'") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
