<?php
/**
 * Smoke — Jaz spec freshness.
 *
 * Compares the vendored `spec/jaz_openapi.json` against the upstream
 * head and flags drift (added/removed required fields, renamed keys
 * on the schemas our mappers consume, version bumps). When the
 * sandbox has no network access — common in CI/headless containers —
 * the smoke marks itself as SKIPPED rather than failing.
 *
 * What it locks in:
 *   1. The vendored spec exists, is well-formed JSON, and carries
 *      the three Create*ClientRequest schemas our mappers need.
 *   2. The refresh tool exists, is executable, and (when network is
 *      available) can download the upstream successfully.
 *   3. Drift detection on the three schemas our mappers touch:
 *      diff `required[]` and the keyset of `properties[]` between
 *      local + upstream. Any change becomes an actionable warning so
 *      we patch the mappers BEFORE Jaz starts rejecting payloads.
 *
 * Run: php -d zend.assertions=1 /app/tests/jaz_spec_freshness_smoke.php
 */
declare(strict_types=1);

$passes  = 0;
$failures = [];
$warnings = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}
function warn(string $msg) {
    global $warnings;
    $warnings[] = $msg;
    echo "  ⚠ {$msg}\n";
}

echo "\nJaz spec freshness smoke\n";
echo "========================\n\n";

$specPath = __DIR__ . '/../spec/jaz_openapi.json';
$tool     = __DIR__ . '/../tools/refresh_jaz_spec.sh';

echo "── vendored spec is sane ──\n";
check('spec/jaz_openapi.json exists',            is_file($specPath));
check('spec parses as JSON',                     json_decode((string) file_get_contents($specPath), true) !== null);
$spec = json_decode((string) file_get_contents($specPath), true);
$watched = ['CreateBillClientRequest', 'CreateInvoiceClientRequest', 'CreateJournalClientRequest'];
foreach ($watched as $name) {
    check("spec defines {$name}", isset($spec['definitions'][$name]['properties']));
}

echo "\n── refresh tool ──\n";
check('tools/refresh_jaz_spec.sh exists',         is_file($tool));
check('refresh tool is executable on Unix or local Windows checkout',
    is_executable($tool) || DIRECTORY_SEPARATOR === '\\');
check('refresh tool documents --diff and --check', str_contains((string) file_get_contents($tool), '--diff') &&
                                                   str_contains((string) file_get_contents($tool), '--check'));

echo "\n── upstream drift check ──\n";
// Skip gracefully when curl is missing OR network is unreachable —
// common in CI containers and the sandbox we test in.
$curlProbe = DIRECTORY_SEPARATOR === '\\' ? 'where curl 2>NUL' : 'command -v curl 2>/dev/null';
$bashProbe = DIRECTORY_SEPARATOR === '\\' ? 'where bash 2>NUL' : 'command -v bash 2>/dev/null';
$haveCurl = trim((string) shell_exec($curlProbe)) !== '';
$haveBash = trim((string) shell_exec($bashProbe)) !== '';
if (!$haveCurl || !$haveBash) {
    echo "  ⚠ curl not present — drift check SKIPPED (run on a host with network)\n";
    goto summary;
}

$toolOut = [];
exec("bash " . escapeshellarg($tool) . " --check 2>/dev/null", $toolOut);
$tmp = trim((string) end($toolOut));
if (!$tmp || !is_file($tmp)) {
    echo "  ⚠ upstream fetch failed (offline?) — drift check SKIPPED\n";
    goto summary;
}

$upstream = json_decode((string) file_get_contents($tmp), true);
@unlink($tmp);
if (!is_array($upstream)) {
    warn("upstream payload was not valid JSON — investigate {$tool}");
    goto summary;
}

// Coarse version drift indicator.
$localVer = (string) ($spec['info']['version'] ?? '?');
$upVer    = (string) ($upstream['info']['version'] ?? '?');
if ($localVer !== $upVer) {
    warn("upstream version drifted: local={$localVer} upstream={$upVer} — run `bash tools/refresh_jaz_spec.sh` and re-run the contract smoke");
} else {
    echo "  ✓ version matches ({$localVer})\n";
    $passes++;
}

// Per-schema diff on the fields that actually feed our mappers.
$drift = 0;
foreach ($watched as $name) {
    $localSch = $spec['definitions'][$name]    ?? null;
    $upSch    = $upstream['definitions'][$name] ?? null;
    if (!$localSch || !$upSch) {
        warn("schema {$name} present in only one side — major drift");
        $drift++;
        continue;
    }
    $localReq = $localSch['required'] ?? [];
    $upReq    = $upSch['required']    ?? [];
    sort($localReq); sort($upReq);
    if ($localReq !== $upReq) {
        warn("{$name}.required changed — local=[" . implode(',', $localReq) . "] upstream=[" . implode(',', $upReq) . "] → may require mapper fix");
        $drift++;
    }
    $localProps = array_keys($localSch['properties'] ?? []);
    $upProps    = array_keys($upSch['properties']    ?? []);
    $added   = array_diff($upProps,    $localProps);
    $removed = array_diff($localProps, $upProps);
    if ($added)   warn("{$name}: NEW upstream fields — " . implode(',', $added));
    if ($removed) warn("{$name}: REMOVED upstream fields — " . implode(',', $removed));
    if (!$added && !$removed && $localReq === $upReq) {
        echo "  ✓ {$name} matches upstream (required + properties)\n";
        $passes++;
    } else {
        $drift++;
    }
}

if ($drift === 0) {
    echo "  ✓ all watched schemas in sync\n";
    $passes++;
}

summary:
$total = $passes + count($failures);
echo "\n=========================================\n";
echo "jaz_spec_freshness smoke: {$passes} ✓ / " . count($failures) . " ✗";
if ($warnings) echo " / " . count($warnings) . " ⚠";
echo "\n=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
foreach ($warnings as $msg) echo "  WARN: {$msg}\n";
exit($failures ? 1 : 0);
