<?php
/**
 * tests/jaz_push_409_and_error_surface_smoke.php
 *
 * Locks the 2026-02 fixes to the Jaz CoA push pipeline:
 *
 *   1) `createAccount()` in `core/accounting/jaz_adapter.php` now treats a
 *      Jaz 409 as idempotent-success by inspecting `JazApiException::httpStatus`
 *      (the old `getCode()` check was always 0 and so 409s were thrown as
 *      hard errors — the bug behind "push: 0 created · 15 errors").
 *   2) Non-409 errors are re-thrown with a richer message that includes the
 *      code we were trying to push + an HTTP-status-specific hint
 *      (401/403/422/404).
 *   3) `JazSyncNowCard` now surfaces an expandable per-row error list and
 *      flips the flash banner to `kind:'error'` when the run had any errors
 *      (previously the banner stayed green-styled even when zero accounts
 *      had been pushed).
 *
 * Run:
 *   php -d zend.assertions=1 tests/jaz_push_409_and_error_surface_smoke.php
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

echo "── jaz_adapter.php — 409 idempotency fix ──\n";
$adapter = read('core/accounting/jaz_adapter.php');

ok('Uses JazApiException::httpStatus for 409 detection (not getCode)',
   (bool) preg_match('/\$isConflict\s*=.*JazApiException.*httpStatus.*===\s*409/s', $adapter));

ok('Old buggy getCode() === 409 path is gone',
   !str_contains($adapter, '((int) $e->getCode()) === 409'));

ok('Non-conflict failures get wrapped with createAccount(code=...)',
   str_contains($adapter, "'createAccount(code='"));

ok('422/400 hint mentions accountClass + accountType payload guidance',
   str_contains($adapter, '422') && str_contains($adapter, 'accountClass') && str_contains($adapter, 'accountType') && str_contains($adapter, 'required'));

ok('401/403 hint mentions Jaz Settings → API Keys',
   str_contains($adapter, 'Jaz \u{2192} Settings \u{2192} API Keys') || str_contains($adapter, 'Jaz → Settings → API Keys'));

ok('404 hint mentions JAZ_API_BASE',
   str_contains($adapter, 'JAZ_API_BASE'));

ok('409 path still falls through to GET chart-of-accounts lookup',
   (bool) preg_match("/jazCall.*'GET',\s*'chart-of-accounts'.*code/s", $adapter));

ok('POST payload uses Jaz canonical field `name`',
   (bool) preg_match("/'name'\s*=>/", $adapter) && !str_contains($adapter, "'accountName' =>"));

ok('POST payload uses Jaz canonical field `accountClass` (bucket)',
   str_contains($adapter, "'accountClass' => \$accountClass"));

ok('POST payload uses Jaz canonical field `accountType` (subtype)',
   str_contains($adapter, "'accountType'  => \$subtype"));

ok('POST payload uses flat `currencyCode` string (not currency.code object)',
   str_contains($adapter, "'currencyCode' =>"));

ok('classMap maps CoreFlux enum → Jaz TitleCase bucket',
   (bool) preg_match("/'asset'\s*=>\s*'Asset'/", $adapter)
       && (bool) preg_match("/'expense'\s*=>\s*'Expense'/", $adapter));

ok('Sensible default subtype provided per bucket',
   str_contains($adapter, "'Operating Expense'") && str_contains($adapter, "'Current Asset'"));

ok('409 GET lookup keys on `name` (Jaz primary identity — no codes)',
   (bool) preg_match("/'name'\s*=>\s*\\\$payload\['name'\]/", $adapter));

ok('Wrapper unwraps Jaz `{data: {...}}` envelope before normalizeCoaRow',
   str_contains($adapter, "isset(\$resp['data']) && is_array(\$resp['data'])"));

ok('normalizeCoaRow reads accountClass first for bucket type',
   (bool) preg_match("/strtolower\(\(string\)\s*\(\\\$r\['accountClass'\]/", $adapter));

ok('normalizeCoaRow reads currencyCode (flat)',
   str_contains($adapter, "\$r['currencyCode']"));

ok('normalizeCoaRow surfaces Jaz subtype as `subtype`',
   str_contains($adapter, "'subtype'"));

ok('Status-aware active flag (ACTIVE / INACTIVE / ARCHIVED)',
   str_contains($adapter, "ACTIVE") && str_contains($adapter, "\$status === 'ACTIVE'"));

echo "── JazSyncNowCard — error surface ──\n";
$ui = read('dashboard/src/pages/JazIntegrationSettings.jsx');

ok('JazSyncNowCard expandable error block testid present',
   str_contains($ui, 'jaz-sync-errors-'));

ok('Per-error row testid present',
   str_contains($ui, 'jaz-sync-error-'));

ok('Renders <details>/<summary> for collapsible error list',
   str_contains($ui, '<details>') && str_contains($ui, '<summary'));

ok('Walks r.push.errors[] for the error list',
   (bool) preg_match('/r\.push\?\.errors\b/', $ui));

ok('Error row colspan covers entity/direction/outcome columns',
   str_contains($ui, 'colSpan={3}'));

ok('Slices to a max of 25 errors for the inline list',
   str_contains($ui, '.slice(0, 25)') || str_contains($ui, '.slice(0,25)'));

ok('Flash flips to kind:error when errs > 0',
   (bool) preg_match("/if\s*\(\s*errs\s*>\s*0\s*\)\s*\{[^}]+kind:\s*'error'/s", $ui));

ok('Successful flash kept as kind:success for clean runs',
   (bool) preg_match("/kind:\s*'success'.*Synced\.\s*CoA/s", $ui));

ok('Wraps multi-element output in React.Fragment',
   str_contains($ui, '<React.Fragment'));

echo "\n=========================================\n";
echo "Jaz push 409 + error surface smoke: {$pass} \u{2713} / {$fail} \u{2717}\n";
echo "=========================================\n";
exit($fail > 0 ? 1 : 0);
