<?php
/**
 * tests/auto_map_telemetry_and_smart_name_smoke.php
 *
 * Locks the 2026-02 follow-up enhancements after the user reported
 * "pull: 0 mapped" with no error and no insight into WHY:
 *
 *   1) Smarter name normaliser in `account_mapping_service.php`:
 *      - ASCII-folds accents (Crédit → credit)
 *      - Strips leading numeric code prefix ("1001 - Cash" → "cash")
 *      - Strips trailing parenthetical qualifier ("Cash (Bank)" → "cash")
 *      - Strips parent-path prefix (already in place)
 *      - Collapses punctuation/whitespace to single spaces
 *
 *   2) Rich pull telemetry returned from the auto-mapper:
 *      - provider_row_count, cf_unmapped_count
 *      - matched_by_code, matched_by_name, no_provider_match
 *      - provider_has_codes flag
 *      - unmapped_sample[] — first 8 unmapped CF rows with their
 *        normalised names so operators can see what didn't match
 *
 *   3) UI surfaces the telemetry in JazSyncNowCard via an info block
 *      that auto-expands when pull.mapped === 0 && cf_unmapped_count > 0.
 *
 *   4) Flash banner flips to kind:'info' (blue) when nothing was done
 *      AND no errors occurred (instead of misleading green "Synced.").
 *
 * Run:
 *   php -d zend.assertions=1 tests/auto_map_telemetry_and_smart_name_smoke.php
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

echo "── account_mapping_service.php — smarter nameNorm ──\n";
$svc = read('core/accounting/account_mapping_service.php');
ok('iconv-based ASCII fold present',
   str_contains($svc, "iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE'"));
ok('Strips leading numeric code prefix (e.g. "1001 - Cash")',
   str_contains($svc, "preg_replace('/^\\s*\\d+\\s*[-:.\\s]+/', ''"));
ok('Strips trailing parenthetical qualifier',
   str_contains($svc, "preg_replace('/\\s*\\([^)]*\\)\\s*\$/'"));
ok('Strips colon-prefixed parent path (carried over)',
   str_contains($svc, "strrchr(\$s, ':')"));
ok('Collapses punctuation + whitespace to single spaces (Unicode-aware)',
   str_contains($svc, "preg_replace('/[\\p{P}\\s]+/u', ' '"));

echo "── pull telemetry envelope ──\n";
ok('Returns provider_row_count',  str_contains($svc, "'provider_row_count'"));
ok('Returns cf_unmapped_count',   str_contains($svc, "'cf_unmapped_count'"));
ok('Returns unmapped_sample[]',   str_contains($svc, "'unmapped_sample'"));
ok('Sample is bounded to first 8 rows',
   str_contains($svc, 'count($unmappedSample) < 8'));
ok('Sample row includes normalized name (so operator sees what we compared)',
   str_contains($svc, "'normalized'") && str_contains($svc, '$cfName'));

echo "── JazSyncNowCard — surfaces telemetry inline ──\n";
$ui = read('dashboard/src/pages/JazIntegrationSettings.jsx');
ok('Reads pull.cf_unmapped_count to gate telemetry block',
   str_contains($ui, 'pullR.cf_unmapped_count'));
ok('Renders "Show auto-map telemetry" expandable block',
   str_contains($ui, 'Show auto-map telemetry'));
ok('Telemetry block auto-opens when mapped===0 AND cf_unmapped_count>0',
   (bool) preg_match('/open=\{\(r\.pull\?\.mapped\s*\?\?\s*0\)\s*===\s*0\s*&&\s*\(r\.pull\?\.cf_unmapped_count\s*\?\?\s*0\)\s*>\s*0\}/', $ui));
ok('Shows matched_by_code / matched_by_name / no_provider_match counters',
   str_contains($ui, 'matched_by_code') && str_contains($ui, 'matched_by_name') && str_contains($ui, 'no_provider_match'));
ok('Lists unmapped sample rows with their normalized form',
   str_contains($ui, 'unmapped_sample') && str_contains($ui, 'u.normalized'));
ok('Info block testid scheme present',
   str_contains($ui, 'jaz-sync-info-') && str_contains($ui, 'jaz-sync-info-${entity}-${bi}-${li}'));

echo "── Flash banner — info kind for empty-success runs ──\n";
ok('runSync flips flash to kind:info when no errors AND nothing done',
   (bool) preg_match("/else\s+if\s*\(\s*pull\s*===\s*0\s*&&\s*push\s*===\s*0\s*&&\s*skp\s*===\s*0\s*\)[^}]*kind:\s*'info'/s", $ui));
ok('Flash component renders info kind with blue palette',
   str_contains($ui, "flash.kind === 'info'") && str_contains($ui, '#eff6ff'));
ok('Info message points operators at the telemetry block',
   str_contains($ui, 'auto-map telemetry'));

echo "\n=========================================\n";
echo "Auto-map telemetry + smart name smoke: {$pass} \u{2713} / {$fail} \u{2717}\n";
echo "=========================================\n";
exit($fail > 0 ? 1 : 0);
