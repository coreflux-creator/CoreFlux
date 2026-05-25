<?php
/**
 * Smoke — Placements CSV import "person_email not found" bug fix.
 *
 * Reproduces the operator-reported bug: a CSV row with a person email
 * that exists in the tenant's People directory was failing dry-run
 * validation with `person_email: 'foo@bar' not found in this tenant's
 * People`. Two known causes:
 *   1. Hidden Unicode whitespace in the CSV cell (BOM \uFEFF, NBSP
 *      \u00A0, zero-width chars) that survives `trim()`.
 *   2. Single-character typos in the CSV that the operator can't spot
 *      because the error message echoes the typo verbatim.
 *
 * Both are addressed by `placementsCsvNormaliseEmail()` + Levenshtein
 * "did you mean?" hints in the dry-run validator. This smoke locks in
 * both behaviours at the unit + structural level.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

require_once __DIR__ . '/../core/CsvImportService.php';
require_once __DIR__ . '/../modules/placements/lib/csv_helpers.php';

$src = (string) file_get_contents('/app/modules/placements/api/csv_import.php');

echo "\n1. placementsCsvNormaliseEmail() — Unicode-defensive normalisation\n";
$a('function exported',                function_exists('placementsCsvNormaliseEmail'));
$a('strips leading/trailing ASCII whitespace',
    placementsCsvNormaliseEmail("  foo@bar.com  ") === 'foo@bar.com');
$a('lowercases',
    placementsCsvNormaliseEmail('FOO@BAR.COM') === 'foo@bar.com');
$a('strips BOM (U+FEFF) — Excel UTF-8-with-BOM exports',
    placementsCsvNormaliseEmail("\xEF\xBB\xBFfoo@bar.com") === 'foo@bar.com');
$a('strips trailing NBSP (U+00A0) — Sheets alt-space',
    placementsCsvNormaliseEmail("foo@bar.com\xC2\xA0") === 'foo@bar.com');
$a('strips embedded zero-width joiner (U+200D)',
    placementsCsvNormaliseEmail("foo@\xE2\x80\x8Dbar.com") === 'foo@bar.com');
$a('strips zero-width non-joiner (U+200C)',
    placementsCsvNormaliseEmail("foo\xE2\x80\x8C@bar.com") === 'foo@bar.com');
$a('strips embedded tab',
    placementsCsvNormaliseEmail("foo@\tbar.com") === 'foo@bar.com');
$a('strips line-separator (U+2028)',
    placementsCsvNormaliseEmail("foo@bar.com\xE2\x80\xA8") === 'foo@bar.com');
$a('preserves the literal Cecilia case from the bug report',
    placementsCsvNormaliseEmail('cecibelbravo691@gmail.com') === 'cecibelbravo691@gmail.com');
$a('hidden-NBSP variant resolves to the same canonical form',
    placementsCsvNormaliseEmail("cecibelbravo691@gmail.com\xC2\xA0")
    === placementsCsvNormaliseEmail('cecibelbravo691@gmail.com'));

echo "\n2. Dry-run handler — normalises BEFORE looking up\n";
$a('dry-run uses placementsCsvNormaliseEmail in the IN-list',
    str_contains($src, '$em = placementsCsvNormaliseEmail((string) ($r[\'person_email\'] ?? \'\'));'));
$a('dry-run re-normalises in the per-row error loop too',
    str_contains($src, '$em    = placementsCsvNormaliseEmail($rawEm);'));
$a('error message echoes the RAW email so operator can spot a typo',
    str_contains($src, "not found in this tenant's People\""));

echo "\n3. Dry-run handler — \"did you mean?\" suggestions\n";
$a('directory loaded lazily (only on first miss)',
    str_contains($src, '$directoryCache = null;')
    && str_contains($src, "if (\$directoryCache !== null) return \$directoryCache;"));
$a('directory query is tenant-scoped + soft-delete-safe',
    str_contains($src, 'tenant_id = ? AND deleted_at IS NULL AND email_primary IS NOT NULL'));
$a('directory query caps at 5000 rows so a huge tenant stays under control',
    str_contains($src, 'LIMIT 5000'));
$a('Levenshtein scorer threshold ≤ 3',
    str_contains($src, '$d = levenshtein($needle, $cand);')
    && str_contains($src, 'if ($d <= 3)'));
$a('returns at most 3 suggestions',
    str_contains($src, 'array_slice(array_keys($scored), 0, 3)'));
$a('refuses to levenshtein strings > 255 chars (PHP limit)',
    str_contains($src, 'strlen($cand) > 255 || strlen($needle) > 255'));
$a('suggestion list appended to the error message when non-empty',
    str_contains($src, "did you mean: ' . implode(', ', \$suggestions) . '?"));

echo "\n4. Commit handler — same Unicode-defensive normalisation\n";
$a('commit calls placementsCsvNormaliseEmail before scopedFind',
    str_contains($src, "\$emClean = placementsCsvNormaliseEmail((string) (\$row['person_email'] ?? ''));"));
$a('commit uses LOWER(email_primary) = :email (already-lowercased value)',
    str_contains($src, 'LOWER(email_primary) = :email'));
$a('commit error includes the RAW unnormalised input',
    str_contains($src, "throw new \\RuntimeException(\"person_email not found: {\$row['person_email']}\");"));

echo "\n5. PHP syntax\n";
$out = []; $rc = 0;
exec('php -l /app/modules/placements/api/csv_import.php 2>&1', $out, $rc);
$a('php -l /app/modules/placements/api/csv_import.php', $rc === 0, implode("\n", $out));

echo "\n=========================================\n";
echo "Placements CSV email-lookup bug fix smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
