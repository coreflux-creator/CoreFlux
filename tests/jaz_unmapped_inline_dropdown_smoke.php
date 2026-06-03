<?php
/**
 * tests/jaz_unmapped_inline_dropdown_smoke.php
 *
 * Locks the 2026-02 follow-up: an inline "Map this to..." dropdown
 * next to each unmapped CoreFlux row in the Step 3B auto-map
 * telemetry block.  Operators can now resolve unmapped accounts
 * directly from the sync card without navigating to Step 4.
 *
 * Backend contract (account_mapping_service.php):
 *   - `unmapped_sample[]` rows now carry `coreflux_account_id` so the
 *     frontend can target the right CF row on save.
 *   - Response carries `provider_options[]` — a compact list of every
 *     provider account (max 500) for the dropdown.  Each option has
 *     `provider_id, code, name, type, subtype`.
 *
 * Frontend contract (JazSyncNowCard in JazIntegrationSettings.jsx):
 *   - `saveMapping()` POSTs to
 *     `?action=account_mapping_save&provider=jaz` with
 *     source='manual', confidence=100.
 *   - Resolved rows are tracked in local `mappedNow` state and
 *     replaced inline with "✓ Mapped → {name}".
 *   - Per-row testid scheme: `jaz-sync-unmapped-row-{i}`,
 *     `jaz-sync-unmapped-select-{i}`, `jaz-sync-unmapped-mapped-{i}`.
 *
 * Run:
 *   php -d zend.assertions=1 tests/jaz_unmapped_inline_dropdown_smoke.php
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
function ok(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { echo "  \u{2713} {$label}\n"; $pass++; }
    else       { echo "  \u{2717} {$label}\n"; $fail++; }
}
function read(string $rel): string {
    $p = realpath(__DIR__ . '/../' . $rel);
    if (!$p || !is_readable($p)) { fwrite(STDERR, "missing: {$rel}\n"); exit(2); }
    return file_get_contents($p) ?: '';
}

echo "── backend: unmapped_sample carries coreflux_account_id ──\n";
$svc = read('core/accounting/account_mapping_service.php');
ok('Sample row carries coreflux_account_id',
   str_contains($svc, "'coreflux_account_id' => (int) (\$cf['id']"));
ok('Sample row carries normalized form',
   str_contains($svc, "'normalized'          => \$cfName"));

echo "── backend: provider_options[] envelope ──\n";
ok('Returns provider_options only when noMatch > 0',
   (bool) preg_match('/if\s*\(\s*\$noMatch\s*>\s*0\s*\)\s*\{/', $svc));
ok('Caps provider_options at 500 rows',
   str_contains($svc, '++$i > 500'));
ok('Option carries provider_id',  str_contains($svc, "'provider_id'   => \$pid"));
ok('Option carries code',         str_contains($svc, "'code'          =>"));
ok('Option carries name',         str_contains($svc, "'name'          =>"));
ok('Option carries type (bucket)', str_contains($svc, "'type'          =>"));
ok('Option carries subtype',      str_contains($svc, "'subtype'       =>"));
ok('Skips options with empty provider_id', str_contains($svc, "if (\$pid === '') continue;"));
ok('Top-level out array still references provider_options inside the if-block',
   (bool) preg_match("/\\\$out\['provider_options'\]\s*=\s*\\\$opts;/", $svc));

echo "── frontend: JazSyncNowCard inline dropdown ──\n";
$ui = read('dashboard/src/pages/JazIntegrationSettings.jsx');
ok('mappedNow state to track resolved rows',
   str_contains($ui, 'setMappedNow') && str_contains($ui, 'mappedNow'));
ok('savingId state to disable select while in flight',
   str_contains($ui, 'setSavingId'));
ok('saveMapping helper exists',
   str_contains($ui, 'const saveMapping = async'));
ok('saveMapping POSTs to account_mapping_save with provider=jaz',
   str_contains($ui, "/api/accounting.php?action=account_mapping_save&provider=jaz"));
ok('saveMapping body uses source=manual, confidence=100',
   str_contains($ui, "source:                'manual'") && str_contains($ui, "confidence:            100"));
ok('Resolved rows render "✓ Mapped → {name}"',
   str_contains($ui, "✓ Mapped →"));
ok('Resolver block wrapper testid present',
   str_contains($ui, 'data-testid="jaz-sync-unmapped-resolver"'));
ok('Per-row testid scheme: jaz-sync-unmapped-row-{i}',
   str_contains($ui, 'jaz-sync-unmapped-row-${ui}'));
ok('Per-row select testid: jaz-sync-unmapped-select-{i}',
   str_contains($ui, 'jaz-sync-unmapped-select-${ui}'));
ok('Per-row mapped testid: jaz-sync-unmapped-mapped-{i}',
   str_contains($ui, 'jaz-sync-unmapped-mapped-${ui}'));
ok('Select is disabled while saving',
   str_contains($ui, 'disabled={saving}'));
ok('Select shows "Map this to…" placeholder option',
   str_contains($ui, 'Map this to…'));
ok('Select option text shows name + subtype + (type)',
   str_contains($ui, '{o.name}{o.subtype ? ` · ${o.subtype}` : \'\'}{o.type ? ` (${o.type})` : \'\'}'));
ok('Reset mappedNow on every new runSync',
   str_contains($ui, 'setMappedNow({})') && str_contains($ui, 'setBusy(true)'));
ok('Success flash on per-row save mentions Step 4',
   str_contains($ui, 'Visible in Step 4'));
ok('Error flash on per-row save mentions failing row name',
   str_contains($ui, 'Failed to save mapping for'));
ok('Section is gated on chart_of_accounts entity (not invoices etc.)',
   str_contains($ui, "entity === 'chart_of_accounts'\n                            && Array.isArray(r.pull?.unmapped_sample)"));
ok('Resolver only renders when unmapped_sample non-empty',
   str_contains($ui, 'r.pull.unmapped_sample.length > 0'));

echo "\n=========================================\n";
echo "Jaz unmapped inline dropdown smoke: {$pass} \u{2713} / {$fail} \u{2717}\n";
echo "=========================================\n";
exit($fail > 0 ? 1 : 0);
