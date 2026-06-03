<?php
/**
 * tests/account_mapping_name_fallback_smoke.php
 *
 * Locks the 2026-02 enhancement to `accountingAccountMappingsAutoMap()`
 * in `core/accounting/account_mapping_service.php`:
 *
 * Providers like Jaz don't expose account codes — every row is keyed by
 * `resourceId` (UUID) and human name.  Previously the auto-mapper
 * bailed out with `"Provider accounts carry no codes — auto-map by
 * code unavailable"` and operators were stuck mapping 15+ accounts by
 * hand.  The mapper now:
 *
 *   1. Builds BOTH a `byCode` and a `byName` lookup over the provider
 *      CoA.  Name keys are normalised (lowercase, trimmed, parent-path
 *      prefix stripped, multi-space collapsed).
 *   2. For each unmapped CF account, tries code first (confidence=80,
 *      source=auto_code), then name (confidence=60, source=auto_name).
 *   3. Returns rich telemetry: `matched_by_code`, `matched_by_name`,
 *      `provider_has_codes`, plus an operator-friendly `note`.
 *
 * Run:
 *   php -d zend.assertions=1 tests/account_mapping_name_fallback_smoke.php
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

$src = read('core/accounting/account_mapping_service.php');

echo "── auto-mapper builds both code + name lookups ──\n";
ok('Declares $byName lookup',         (bool) preg_match('/\$byName\s*=\s*\[\];/', $src));
ok('Declares $nameNorm closure',      str_contains($src, '$nameNorm'));
ok('nameNorm strips colon-prefixed parent path',
   str_contains($src, "strrchr(\$s, ':')"));
ok('nameNorm collapses punctuation + whitespace runs (Unicode-aware)',
   str_contains($src, "preg_replace('/[\\p{P}\\s]+/u', ' '"));
ok('First-write-wins on $byName (dup-name shadow safe)',
   str_contains($src, "!isset(\$byName[\$n])"));

echo "── match loop honours codes first, then names ──\n";
ok('Tries $byCode first',
   (bool) preg_match("/if\s*\(\\\$cfCode\s*!==\s*''\s*&&\s*isset\(\\\$byCode\[\\\$cfCode\]\)/", $src));
ok('Falls back to $byName when no code match',
   (bool) preg_match("/elseif\s*\(\\\$cfName\s*!==\s*''\s*&&\s*isset\(\\\$byName\[\\\$cfName\]\)/", $src));
ok('auto_code carries confidence=80', str_contains($src, "'auto_code'; \$confidence = 80;"));
ok('auto_name carries confidence=60', str_contains($src, "'auto_name'; \$confidence = 60;"));

echo "── richer return envelope ──\n";
ok('Returns matched_by_code count',    str_contains($src, "'matched_by_code'"));
ok('Returns matched_by_name count',    str_contains($src, "'matched_by_name'"));
ok('Returns provider_has_codes flag',  str_contains($src, "'provider_has_codes' => \$hasCodes"));
ok('Surfaces operator-friendly note when name matches happened',
   (bool) preg_match('/Auto-mapped\s*\{\$matchedByCode\}\s*by\s*code\s*\+\s*\{\$matchedByName\}\s*by\s*name/', $src));
ok('Graceful empty result when no codes AND no names',
   str_contains($src, 'no codes or names'));
ok('Note when name lookup ran but yielded zero matches',
   str_contains($src, 'tried name-match against'));

echo "── schema supports source=auto_name ──\n";
$mig = read('core/migrations/098_jaz_sync_config_and_account_mappings.sql');
ok('Mapping table ENUM allows auto_name',
   str_contains($mig, "'manual','auto_code','auto_name','imported'"));

echo "\n=========================================\n";
echo "Auto-map name fallback smoke: {$pass} \u{2713} / {$fail} \u{2717}\n";
echo "=========================================\n";
exit($fail > 0 ? 1 : 0);
