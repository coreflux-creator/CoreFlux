<?php
/**
 * Smoke — Treasury Sweep Destination UI + In-app Divergence Alert
 *
 * Validates the P1 "sweep destination UI + in-app divergence" feature
 * shipped after the spec re-audit:
 *
 *   1. /api/admin/treasury/sweep_destinations.php — full GET/POST/DELETE
 *      surface wraps mercuryRecipientCreate(kind=sweep_destination)
 *      plus optional Mercury push and optional rule wiring.
 *   2. /api/admin/treasury/sweep_divergence.php — same divergence
 *      signal the daily email cron computes, served live in-app.
 *   3. SweepDestinations.jsx renders a CRUD table + form with the
 *      right testids the e2e suite hooks into.
 *   4. SweepDivergenceBanner.jsx renders the right tones for
 *      error / warn / clear states with refresh + drill list.
 *   5. TreasuryModule.jsx mounts the new tab + route.
 *   6. SweepRulesAdmin.jsx surfaces the divergence banner inline.
 *   7. mercuryRecipientList() now attaches account_type so the
 *      destinations table can render type without an N+1 lookup.
 *
 * Static + string-presence assertions (no DB, no HTTP).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$destApi  = (string) file_get_contents('/app/api/admin/treasury/sweep_destinations.php');
$divApi   = (string) file_get_contents('/app/api/admin/treasury/sweep_divergence.php');
$destUi   = (string) file_get_contents('/app/modules/treasury/ui/SweepDestinations.jsx');
$bannerUi = (string) file_get_contents('/app/modules/treasury/ui/SweepDivergenceBanner.jsx');
$modUi    = (string) file_get_contents('/app/modules/treasury/ui/TreasuryModule.jsx');
$rulesUi  = (string) file_get_contents('/app/modules/treasury/ui/SweepRulesAdmin.jsx');
$recCore  = (string) file_get_contents('/app/core/mercury_recipients.php');

echo "\n1. /api/admin/treasury/sweep_destinations.php — contract\n";
$a('declares strict_types',
    str_contains($destApi, 'declare(strict_types=1);'));
$a('requires api_bootstrap + RBAC + mercury_recipients',
    str_contains($destApi, 'api_bootstrap.php')
    && str_contains($destApi, 'RBAC.php')
    && str_contains($destApi, 'mercury_recipients.php'));
$a('gated on accounting.bank.manage',
    str_contains($destApi, "rbac_legacy_require(\$user, 'accounting.bank.manage')"));
$a('GET returns rows + rules + live_mode',
    str_contains($destApi, "'rows'      => \$recipients")
    && str_contains($destApi, "'rules'     => \$rules")
    && str_contains($destApi, "'live_mode' =>"));
$a('GET attaches wired_rule_ids per destination',
    str_contains($destApi, "\$rec['wired_rule_ids']"));
$a('POST validates name required',
    str_contains($destApi, "if (\$name === '')           api_error('name required', 422)"));
$a('POST refuses sweep loop (destination == source)',
    str_contains($destApi, 'cannot loop on itself'));
$a('POST calls mercuryRecipientCreate with kind=sweep_destination',
    str_contains($destApi, "'kind' => 'sweep_destination'"));
$a('POST best-effort Mercury counterparty push',
    str_contains($destApi, 'mercuryRecipientPushToMercury')
    && str_contains($destApi, "\$pushResult = ['ok' => false, 'error' => \$e->getMessage()]"));
$a('POST optional rule wiring updates destination_recipient_id + destination_account_id',
    str_contains($destApi, 'destination_recipient_id = :r,')
    && str_contains($destApi, 'destination_account_id   = :acct'));
$a('DELETE rejects non-sweep recipient kind',
    str_contains($destApi, "if ((\$check['kind'] ?? '') !== 'sweep_destination')"));
$a('DELETE unwires rules before revoke',
    strpos($destApi, 'UPDATE tenant_sweep_rules')
    < strpos($destApi, 'mercuryRecipientRevoke'));

echo "\n2. /api/admin/treasury/sweep_divergence.php — contract\n";
$a('declares strict_types + GET only',
    str_contains($divApi, 'declare(strict_types=1);')
    && str_contains($divApi, "if (api_method() !== 'GET') api_error('Method not allowed', 405);"));
$a('gated on accounting.bank.manage',
    str_contains($divApi, "rbac_legacy_require(\$user, 'accounting.bank.manage')"));
$a('hours param clamped 1..168',
    str_contains($divApi, 'if ($hours < 1)   $hours = 1;')
    && str_contains($divApi, 'if ($hours > 168) $hours = 168;'));
$a('outcome=failed flagged as error severity',
    str_contains($divApi, "\$severity = 'error'"));
$a('outcome=swept + dry_run flagged as warn severity',
    str_contains($divApi, "\$severity = 'warn'"));
$a('soft-degrades on migration-pending (treasury_sweep_runs missing)',
    str_contains($divApi, "'migration_pending' => true"));
$a('totals include divergence_count rollup',
    str_contains($divApi, "'divergence_count'           => 0,")
    && str_contains($divApi, "\$totals['divergence_count']++;"));

echo "\n3. SweepDestinations.jsx — UI surface\n";
$a('section root has data-testid',
    str_contains($destUi, 'data-testid="sweep-destinations"'));
$a('"New destination" toggle button',
    str_contains($destUi, 'data-testid="sweep-destinations-new-btn"'));
$a('form has name + routing + account + type + wire + push checkbox',
    str_contains($destUi, 'data-testid="sweep-destinations-form-name"')
    && str_contains($destUi, 'data-testid="sweep-destinations-form-routing"')
    && str_contains($destUi, 'data-testid="sweep-destinations-form-account"')
    && str_contains($destUi, 'data-testid="sweep-destinations-form-type"')
    && str_contains($destUi, 'data-testid="sweep-destinations-form-wire"')
    && str_contains($destUi, 'data-testid="sweep-destinations-form-push"'));
$a('POST endpoint targeted',
    str_contains($destUi, "api.post('/api/admin/treasury/sweep_destinations.php'"));
$a('DELETE endpoint targeted with id',
    str_contains($destUi, "api.delete(`/api/admin/treasury/sweep_destinations.php?id=\${id}`"));
$a('Available-rules filter excludes already-wired',
    str_contains($destUi, "rules.filter(r => !r.destination_recipient_id)"));
$a('row exposes per-row testid + counterparty + wiring badges',
    str_contains($destUi, 'data-testid={`sweep-destinations-row-${r.id}`}')
    && str_contains($destUi, 'data-testid={`sweep-destinations-counterparty-${r.id}`}')
    && str_contains($destUi, 'data-testid={`sweep-destinations-wiring-${r.id}`}'));
$a('empty state renders when no rows',
    str_contains($destUi, 'data-testid="sweep-destinations-empty"'));
$a('reads mercury_id field from list payload (not the wrong name)',
    str_contains($destUi, 'r.mercury_id'));

echo "\n4. SweepDivergenceBanner.jsx — UI surface\n";
$a('banner root testid',
    str_contains($bannerUi, 'data-testid="sweep-divergence-banner"'));
$a('data-tone reflects error/warn/clear state',
    str_contains($bannerUi, 'data-tone={tone.kind}'));
$a('headline testid is wired',
    str_contains($bannerUi, 'data-testid="sweep-divergence-banner-headline"'));
$a('refresh + toggle buttons present',
    str_contains($bannerUi, 'data-testid="sweep-divergence-banner-refresh"')
    && str_contains($bannerUi, 'data-testid="sweep-divergence-banner-toggle"'));
$a('per-alert testids carry severity',
    str_contains($bannerUi, 'data-testid={`sweep-divergence-banner-alert-${a.id}`}')
    && str_contains($bannerUi, 'data-severity={a.severity}'));
$a('auto-refresh every 5 minutes',
    str_contains($bannerUi, '5 * 60 * 1000'));
$a('targets the new API endpoint',
    str_contains($bannerUi, '/api/admin/treasury/sweep_divergence.php?hours='));
$a('DRY-RUN mode pill rendered when live_mode is false',
    str_contains($bannerUi, 'data-testid="sweep-divergence-banner-mode"')
    && str_contains($bannerUi, 'data.live_mode === false'));

echo "\n5. TreasuryModule.jsx wiring\n";
$a('imports SweepDestinations',
    str_contains($modUi, "import SweepDestinations      from './SweepDestinations';"));
$a('renders sweep-destinations tab',
    str_contains($modUi, 'data-testid={`treasury-tab-${to}`}')
    && str_contains($modUi, '"sweep-destinations"'));
$a('mounts route',
    str_contains($modUi, '<Route path="sweep-destinations" element={<SweepDestinations />} />'));

echo "\n6. SweepRulesAdmin.jsx surfaces the banner\n";
$a('imports SweepDivergenceBanner',
    str_contains($rulesUi, "import SweepDivergenceBanner from './SweepDivergenceBanner';"));
$a('renders banner inside the section root',
    (bool) preg_match('/data-testid="sweep-rules-admin"[^>]*>\s*<SweepDivergenceBanner/s', $rulesUi));

echo "\n7. mercuryRecipientList now attaches account_type\n";
$a('subquery pulls account_type from default bank method',
    str_contains($recCore, 'AS account_type,'));

echo "\n8. PHP syntax\n";
foreach ([
    '/app/api/admin/treasury/sweep_destinations.php',
    '/app/api/admin/treasury/sweep_divergence.php',
    '/app/core/mercury_recipients.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Sweep destination UI + divergence smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
