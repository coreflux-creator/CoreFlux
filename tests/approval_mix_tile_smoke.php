<?php
/**
 * Smoke — /modules/time/api/approval_mix.php (CFO dashboard tile).
 *
 * Validates the contract that ApprovalMixTile.jsx reads against:
 *   • response payload carries the expected top-level keys
 *   • weeks-window bounds are enforced (4 ≤ weeks ≤ 26)
 *   • channels object pre-seeds the three known channels (so the
 *     sparkline never visually drops a series)
 *   • out-of-window rows are ignored (safety against future emitter changes)
 *   • _other bucket catches any future channel without dropping data
 *   • RBAC + GET-only enforcement
 *   • PHP syntax green
 *
 * Source-level + payload-shape coverage. Channel-by-channel math is
 * deterministic from the SQL output so a future end-to-end test can
 * stand the audit_log up and assert exact counts; for now we cover the
 * shape contract the frontend depends on.
 */
declare(strict_types=1);

require_once '/app/core/api_bootstrap.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$src = (string) file_get_contents('/app/modules/time/api/approval_mix.php');

echo "\n1. Endpoint security + method gate\n";
$a('require_once api_bootstrap',  str_contains($src, "require_once __DIR__ . '/../../../core/api_bootstrap.php';"));
$a('require_once RBAC',           str_contains($src, "require_once __DIR__ . '/../../../core/RBAC.php';"));
$a('rejects non-GET methods',     str_contains($src, "if (api_method() !== 'GET') api_error('Method not allowed', 405);"));
$a('rbac gate: time.view',        str_contains($src, "rbac_legacy_require(\$user, 'time.view');"));

echo "\n2. Weeks-window bounds enforcement\n";
$a('lower bound: weeks < 4 → 4',  str_contains($src, 'if ($weeks < 4)  $weeks = 4;'));
$a('upper bound: weeks > 26 → 26',str_contains($src, 'if ($weeks > 26) $weeks = 26;'));

echo "\n3. Query shape\n";
$a('events filtered to time.entry.approved',
   str_contains($src, "AND event     = 'time.entry.approved'"));
$a('tenant_id scoped',            str_contains($src, "WHERE tenant_id = :t"));
$a('groups by iso_week + channel',str_contains($src, "GROUP BY iso_week, channel"));
$a('approved_via pulled from meta_json',
   str_contains($src, "JSON_UNQUOTE(JSON_EXTRACT(meta_json, '\$.approved_via'))"));

echo "\n4. Pre-seed: known channels always emit a series\n";
foreach (['manual', 'tokenized_client_email', 'bulk_pre_approved'] as $ch) {
    $a("channel '{$ch}' present in pre-seed",
       str_contains($src, "'manual', 'tokenized_client_email', 'bulk_pre_approved'") || str_contains($src, "'{$ch}'"));
}
$a('_other bucket catches unknown future channels',
   str_contains($src, "\$channels['_other']") && str_contains($src, "\$ch = '_other';"));

echo "\n5. Response shape (top-level keys)\n";
foreach ([
    'weeks', 'channels', 'totals_by_week', 'totals_by_channel',
    'grand_total', 'window_weeks', 'last_week_pct',
] as $k) {
    $a("response carries '{$k}'", str_contains($src, "'{$k}'"));
}

echo "\n6. Safety: out-of-window rows skipped (no array index errors)\n";
$a('weekIndex-flip + continue on miss',
   str_contains($src, '$weekIndex = array_flip($weekLabels);')
   && str_contains($src, 'if (!isset($weekIndex[$w])) continue;'));
$a('last_week div-by-zero guarded with max(1, ...)',
   str_contains($src, '$lastWeekTotal = max(1, $totalsByWeek[$lastIdx] ?? 0);'));

echo "\n7. CFO tile wiring\n";
$tile = (string) file_get_contents('/app/dashboard/src/components/ApprovalMixTile.jsx');
$a('tile fetches the endpoint',
   str_contains($tile, "useApi('/modules/time/api/approval_mix.php?weeks=12')"));
$a('tile has data-testid for testing',
   str_contains($tile, "data-testid=\"cfo-approval-mix-tile\""));
$a('per-channel sparklines testid scaffold',
   str_contains($tile, 'data-testid={`cfo-approval-mix-channel-${s.channel}`}'));
$a('alert threshold ≥ 70% bulk',
   str_contains($tile, 'const BULK_ALERT_THRESHOLD = 0.7;'));
$a('warn threshold ≥ 40% bulk',
   str_contains($tile, 'const BULK_WARN_THRESHOLD = 0.4;'));
$a('hides tile when grandTotal is 0 (no data → no chrome)',
   str_contains($tile, 'view.grandTotal === 0'));
$cfo = (string) file_get_contents('/app/dashboard/src/pages/CFODashboard.jsx');
$a('mounted in CFODashboard.jsx',
   str_contains($cfo, "import ApprovalMixTile from '../components/ApprovalMixTile';")
   && str_contains($cfo, '<ApprovalMixTile />'));
$a('rendered AFTER QboSyncHealthTile (visual ordering)',
   strpos($cfo, '<QboSyncHealthTile />') < strpos($cfo, '<ApprovalMixTile />'));

echo "\n8. PHP syntax\n";
$out = []; $rc = 0;
exec('php -l /app/modules/time/api/approval_mix.php 2>&1', $out, $rc);
$a('approval_mix.php syntax clean', $rc === 0, implode("\n", $out));

echo "\n=========================================\n";
echo "Approval Mix endpoint + tile smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
