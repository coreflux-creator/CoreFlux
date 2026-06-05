<?php
/**
 * Smoke — /api/admin/run_accounting_outbox_now.php (2026-06).
 *
 * Locks the on-demand outbox-flush endpoint surface: master_admin RBAC,
 * POST-only, parses tenant/max_rows/dry_run, mirrors the cron worker's
 * loop, returns a clean diagnostic JSON shape.
 *
 * Static-analyzer only — no DB / network.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

echo "\n── api/admin/run_accounting_outbox_now.php ──\n";
$src = (string) file_get_contents('/app/api/admin/run_accounting_outbox_now.php');

$a('endpoint exists',                                $src !== '');
$a('declares strict_types',                          $c($src, 'declare(strict_types=1)'));
$a('requires api_bootstrap + RBAC',
    $c($src, "core/api_bootstrap.php") && $c($src, "core/RBAC.php"));
$a('requires command_service + provider_adapter',
    $c($src, 'core/accounting/command_service.php')
    && $c($src, 'core/accounting/provider_adapter.php'));
$a('calls api_require_auth',                         $c($src, 'api_require_auth()'));
$a('master_admin OR is_global_admin gated',
    $c($src, "!\$isGlobalAdmin && \$role !== 'master_admin'"));
$a('refuses non-POST (state-mutating)',
    $c($src, "api_method() !== 'POST'"));

echo "\n── query-param parsing ──\n";
$a('parses ?tenant query param (>= 0)',
    $c($src, "isset(\$_GET['tenant'])"));
$a('parses ?max_rows with hard ceiling 200',
    $c($src, "max(1, min(200, (int) \$_GET['max_rows']))"));
$a('parses ?dry_run flag',                           $c($src, "!empty(\$_GET['dry_run'])"));

echo "\n── outbox loop mirrors cron worker ──\n";
$a('selects queued + retrying rows where next_retry_at <= NOW()',
    $c($src, "status IN ('queued','retrying')")
    && $c($src, 'next_retry_at IS NULL OR next_retry_at <= NOW()'));
$a('orders ASC + applies max_rows LIMIT',
    $c($src, 'ORDER BY id ASC LIMIT'));
$a('calls accountingCommandExecute per row',
    $c($src, 'accountingCommandExecute($tid, $cid)'));
$a('counts succeeded when status_after = posted',
    $c($src, "\$statusAfter === 'posted'"));
$a('counts failed when status_after in [retrying, dead_letter]',
    $c($src, "['retrying', 'dead_letter']"));
$a('worker_exception path nudges processing → retrying (same as cron)',
    $c($src, 'worker_exception')
    && $c($src, "SET status = 'retrying'")
    && $c($src, 'next_retry_at = DATE_ADD(NOW(), INTERVAL 60 SECOND)'));
$a('dry_run path skips adapter calls',
    $c($src, 'dry_run_skipped')
    && $c($src, "if (\$dryRun)"));

echo "\n── response shape ──\n";
$a('returns processed + succeeded + failed + skipped counts',
    $c($src, "'processed'  => count(\$rows)")
    && $c($src, "'succeeded'  => \$succeeded")
    && $c($src, "'failed'     => \$failed")
    && $c($src, "'skipped'    => \$skipped"));
$a('returns elapsed_ms timing',
    $c($src, "'elapsed_ms' => \$elapsedMs"));
$a('returns per-row report with status_before + status_after',
    $c($src, "'status_before' => (string) \$r['status']")
    && $c($src, "'status_after'  => \$statusAfter"));
$a('returns next_step hint (empty | some failed | all ok)',
    $c($src, 'Outbox is empty')
    && $c($src, 'Some rows failed')
    && $c($src, 'All processed rows succeeded'));

echo "\n── per-row report fields ──\n";
foreach ([
    "'command_id'",
    "'tenant_id'",
    "'provider'",
    "'command_type'",
    "'status_before'",
    "'status_after'",
    "'error_code'",
    "'error_message'",
] as $field) {
    $a("row carries $field", $c($src, $field));
}

// php -l clean.
exec('php -l /app/api/admin/run_accounting_outbox_now.php 2>&1', $out, $rc);
$a('endpoint passes php -l',                         $rc === 0);

echo "\n=========================================\n";
echo "run_accounting_outbox_now smoke: $pass ✓ / $fail ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
