<?php
/**
 * Smoke — Treasury Sweep go-live wiring + audit feed + divergence
 * alert + Mercury dual-leg approval progress + P1.d cleanup.
 *
 * Four shippable slices, validated together because they share the
 * sweep+mercury surface area and a regression in one would break the
 * others.
 *
 * 1. P1.d cleanup: legacy EmployeeDirectory.jsx files are gone.
 * 2. Sweep go-live wiring: migration 075, kind=sweep_destination
 *    accepted by validation + mpCreate, engine Layer 3c calls mpCreate.
 * 3. Sweep audit feed: /api/admin/treasury/sweep_runs.php + UI tab.
 * 4. Divergence alert cron: structure + recipient resolution.
 * 5. Mercury dual-leg approval progress: mpGetApprovalProgress helper
 *    + GET-by-id endpoint wires it.
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/core/treasury_sweep_engine.php';
require_once $ROOT . '/core/mercury_payments.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. P1.d — EmployeeDirectory.jsx legacy code purged\n";
$a('/app/modules/people/ui/EmployeeDirectory.jsx is removed',
   !file_exists($ROOT . '/modules/people/ui/EmployeeDirectory.jsx'));
$a('/app/dashboard/src/modules/EmployeeDirectory.jsx is removed',
   !file_exists($ROOT . '/dashboard/src/modules/EmployeeDirectory.jsx'));
$a('legacy archive copy preserved at /app/legacy/',
   file_exists($ROOT . '/legacy/people_pre_spec_20260429/ui/PeopleModule.jsx'));

echo "\n2. Sweep go-live wiring\n";
$mig = (string) file_get_contents($ROOT . '/core/migrations/075_sweep_destination_recipient.sql');
$a('migration 075 extends mercury_recipients.kind ENUM',
   str_contains($mig, "MODIFY COLUMN kind ENUM('vendor','funding_source','sweep_destination')"));
$a('migration 075 idempotently adds destination_recipient_id column',
   str_contains($mig, 'destination_recipient_id INT UNSIGNED NULL'));
$a('migration 075 adds index on tenant + destination_recipient_id',
   str_contains($mig, 'idx_sweep_dest_recipient (tenant_id, destination_recipient_id)'));

$recipPath = $ROOT . '/core/mercury_recipients.php';
$payPath   = $ROOT . '/core/mercury_payments.php';
$engPath   = $ROOT . '/core/treasury_sweep_engine.php';
$recip = (string) file_get_contents($recipPath);
$a('mercury_recipients.php validation accepts sweep_destination',
   str_contains($recip, "['vendor', 'funding_source', 'sweep_destination']"));
$pay = (string) file_get_contents($payPath);
$a('mpCreate accepts kind=sweep_destination',
   str_contains($pay, "in_array(\$rec['kind'] ?? '', ['vendor', 'sweep_destination'], true)"));

$eng = (string) file_get_contents($engPath);
$a('engine Layer 3c live path requires destination_recipient_id',
   str_contains($eng, '$destRecipientId = isset($rule[\'destination_recipient_id\']) ? (int) $rule[\'destination_recipient_id\'] : 0;'));
$a('engine Layer 3c calls mpCreate with source_module=treasury_sweep',
   str_contains($eng, "mpCreate(\$tenantId, [")
   && str_contains($eng, "'source_module'   => 'treasury_sweep',"));
$a('engine Layer 3c idempotency key embeds rule + calendar day',
   str_contains($eng, "sprintf('sweep:%d:%s', \$ruleId, \$now->format('Y-m-d'))"));
$a('engine Layer 3c records swept+live on mpCreate success',
   (bool) preg_match("/treasurySweepRecordRun\(\s*\\\$tenantId,\s*\\\$ruleId,\s*\\\$balanceCents,\s*\\\$sweepCents,\s*'swept',\s*false,\s*\(int\) \(\\\$pi\['id'\] \?\? 0\)/", $eng));

echo "\n3. Sweep audit feed endpoint + UI\n";
$sweepRunsPath = $ROOT . '/api/admin/treasury/sweep_runs.php';
$ep = (string) file_get_contents($sweepRunsPath);
$a('endpoint requires accounting.bank.manage permission',
   str_contains($ep, "rbac_legacy_require(\$user, 'accounting.bank.manage');"));
$a('endpoint bounds days 1..90',
   str_contains($ep, 'if ($days < 1)  $days = 1;')
   && str_contains($ep, 'if ($days > 90) $days = 90;'));
$a('endpoint supports rule_id filter',
   str_contains($ep, 'rule_id'));
$a('endpoint joins sweep rule name for display',
   str_contains($ep, 'LEFT JOIN tenant_sweep_rules sr ON sr.id = r.rule_id'));
$a('endpoint soft-handles missing migration 074 (returns empty + migration_pending)',
   str_contains($ep, "'migration_pending' => true,"));
$a('summary computes planned-dryrun and swept-live totals separately',
   str_contains($ep, "total_swept_cents_live") && str_contains($ep, "total_planned_cents_dryrun"));
$a('response exposes live_mode flag',
   str_contains($ep, "'live_mode'   => treasurySweepLiveModeEnabled(),"));

$feed = (string) file_get_contents($ROOT . '/modules/treasury/ui/SweepRunsFeed.jsx');
foreach ([
    'sweep-runs-feed', 'sweep-runs-mode-badge', 'sweep-runs-window',
    'sweep-runs-summary', 'sweep-runs-table',
] as $tid) {
    $a("UI testid present: {$tid}", str_contains($feed, "data-testid=\"{$tid}\""));
}
// SummaryCard renders testid prop → data-testid inside an inner div; assert prop wiring.
foreach ([
    'sweep-runs-total', 'sweep-runs-swept-dryrun',
    'sweep-runs-swept-live', 'sweep-runs-fails',
] as $tid) {
    $a("SummaryCard testid prop wired: {$tid}",
       str_contains($feed, "testid=\"{$tid}\""));
}
$a('UI surfaces ready/blocked go-live readiness banners',
   str_contains($feed, 'sweep-runs-readiness-ready')
   && str_contains($feed, 'sweep-runs-readiness-blocked'));
    $a('SweepRulesAdmin mounts the new feed',
    str_contains(
        (string) file_get_contents($ROOT . '/modules/treasury/ui/SweepRulesAdmin.jsx'),
        'import SweepRunsFeed'
    ));

echo "\n4. Divergence alert cron driver\n";
$divPath = $ROOT . '/cron/treasury_sweep_divergence_alert.php';
$div = (string) file_get_contents($divPath);
$a('cron driver scopes to yesterday',
   str_contains($div, '$yesterday = $now->modify(\'-1 day\');'));
$a('cron driver scans treasury_sweep_runs',
   str_contains($div, 'FROM treasury_sweep_runs'));
$a('soft-exit when migration 074 not applied',
   str_contains($div, "fwrite(STDERR, \"[treasury_sweep_divergence] migration 074 not applied?"));
$a('recipients filtered to finance/admin roles',
   str_contains($div, "ut.role IN ('master_admin','tenant_admin','finance_admin','cfo')"));
$a('computes go-live readiness streak up to 14 days',
   str_contains($div, 'for ($i = 1; $i <= 14; $i++)'));
$a('go-live recommendation banner fires at >= 7 clean days',
   str_contains($div, '$streakIncludingToday >= 7'));
$a('failure banner takes precedence over readiness',
   strpos($div, "Action required") > 0
   && strpos($div, "Action required") > strpos($div, 'streakIncludingToday >= 7'));
$a('uses mailerSend purpose=treasury_sweep_divergence',
   str_contains($div, "'purpose'   => 'treasury_sweep_divergence',"));
$a('mailer absence does not 500 — logs MOCKED instead',
   str_contains($div, 'MOCKED — no mailerSend'));

echo "\n5. Mercury dual-leg approval progress\n";
$a('mpGetApprovalProgress function exists',
   function_exists('mpGetApprovalProgress'));
$ref = new ReflectionFunction('mpGetApprovalProgress');
$params = array_map(fn($p) => $p->getName(), $ref->getParameters());
$a('signature: (tenantId, instructionId, viewer)',
   $params === ['tenantId', 'instructionId', 'viewer']);
$a('helper joins users for creator_name and ack user_name',
   str_contains($pay, 'LEFT JOIN users cu ON cu.id = pi.created_by_user_id')
   && str_contains($pay, 'LEFT JOIN users u ON u.id = a.user_id'));
$a('helper consults approvalPolicyResolve',
   str_contains($pay, "approvalPolicyResolve(\n        \$tenantId,"));
$a('helper returns acks_remaining via max(0, min - count)',
   str_contains($pay, '$remaining = max(0, $minApprovers - $ackCount);'));
$a('helper computes cool_off_seconds_remaining from cool_off_until',
   str_contains($pay, 'strtotime((string) $pi[\'cool_off_until\']) - time()'));

// Eligibility branches.
foreach ([
    'no-viewer', 'creator-cannot-approve', 'role-mismatch:', 'already-acked', 'state-',
] as $reason) {
    $a("eligibility reason exposed: {$reason}",
       str_contains($pay, "= '{$reason}"));
}

$mercuryApiPath = $ROOT . '/api/mercury_payments.php';
$api = (string) file_get_contents($mercuryApiPath);
$a('GET-by-id endpoint exposes approval_progress',
   str_contains($api, "'approval_progress' => \$progress"));
$a('endpoint helper call swallows exceptions (no 500 on transient failure)',
   str_contains($api, "try {\n            \$progress = mpGetApprovalProgress(\$tenantId, \$id, \$user);\n        } catch (\\Throwable \$e) { \$progress = []; }"));

echo "\n6. PHP syntax\n";
foreach ([
    $engPath,
    $payPath,
    $recipPath,
    $divPath,
    $sweepRunsPath,
    $mercuryApiPath,
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a('php -l ' . str_replace($ROOT . '/', '', $f), $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Sweep go-live + divergence + dual-leg progress smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
