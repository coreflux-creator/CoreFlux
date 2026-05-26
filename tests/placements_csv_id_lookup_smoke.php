<?php
/**
 * Smoke — Placements CSV import by person_id / placement_id (the
 * "skip the email lookup entirely" fix).
 *
 * Operator-reported bug: even after Unicode normalisation + "did you
 * mean" suggestions, the email-based person lookup kept missing for
 * legitimate rows. Solution: surface the numeric primary keys
 * (people.id + placements.id) and let CSVs reference them directly,
 * bypassing the fuzzy-email surface entirely.
 *
 * This smoke locks in:
 *   1. CSV schema accepts person_id, placement_id (both optional ints)
 *   2. CsvImportService validates type='integer' (digits only)
 *   3. Dry-run validation: person_id beats person_email, missing both
 *      is a clear error, person_id miss has a tenant-scoped error
 *   4. Commit path uses the same precedence (id > email)
 *   5. Update-existing path lookups placement_id first
 *   6. UI surfaces IDs via <IdBadge /> + click-to-copy
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/CsvImportService.php';
require_once __DIR__ . '/../modules/placements/lib/csv_helpers.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$svc      = (string) file_get_contents('/app/modules/placements/api/csv_import.php');
$csvSvc   = (string) file_get_contents('/app/core/CsvImportService.php');
$badgeUi  = (string) file_get_contents('/app/dashboard/src/components/IdBadge.jsx');
$listUi   = (string) file_get_contents('/app/modules/placements/ui/List.jsx');
$detailUi = (string) file_get_contents('/app/modules/placements/ui/PlacementDetail.jsx');
$personUi = (string) file_get_contents('/app/modules/people/ui/PersonDetail.jsx');

echo "\n1. CSV schema — new optional integer columns\n";
$a("person_id registered with type=integer (not required)",
    preg_match(
        "/'person_id'\\s*=>\\s*\\['label'[^]]*'type'\\s*=>\\s*'integer'[^]]*\\]/",
        $svc
    ) === 1);
$a("placement_id registered with type=integer (not required)",
    preg_match(
        "/'placement_id'\\s*=>\\s*\\['label'[^]]*'type'\\s*=>\\s*'integer'[^]]*\\]/",
        $svc
    ) === 1);
$a("person_email no longer hard-required (id is the preferred key)",
    !preg_match("/'person_email'.*'required'\\s*=>\\s*true/", $svc));
$a("placement_id added to unique_within_batch",
    str_contains($svc, "'unique_within_batch' => ['external_id', 'placement_id']"));

echo "\n2. CsvImportService — 'integer' type validation\n";
$a("integer type strips alphabetic prefix before digit-check",
    str_contains($csvSvc, "preg_replace("));
$a("integer type coerces stripped cell to int after validation",
    str_contains($csvSvc, "\$row[\$field] = (int) \$stripped;"));

// Synthetic CSV exercising the integer parser end-to-end. The
// /api/csv_import.php file registers the full schema but pulling it
// in triggers api_bootstrap — register a slim equivalent here so the
// integer validator runs in pure isolation.
Core\CsvImportService::registerSchema('placements', [
    'fields' => [
        'person_id'       => ['label' => 'Person ID',       'type' => 'integer'],
        'title'           => ['label' => 'Title',           'required' => true],
        'engagement_type' => ['label' => 'Engagement type', 'required' => true,
                              'enum' => ['w2','1099','c2c','temp_to_perm','direct_hire']],
        'start_date'      => ['label' => 'Start date',      'required' => true, 'type' => 'date'],
    ],
]);
$csv = "person_id,title,engagement_type,start_date\n"
     . "1042,Sample Engineer,w2,2026-05-01\n"
     . "abc,Bad Row,w2,2026-05-01\n"
     . "1042.5,Also Bad,w2,2026-05-01\n";
$result = Core\CsvImportService::dryRun('placements', $csv, [
    'person_id' => 'person_id', 'title' => 'title',
    'engagement_type' => 'engagement_type', 'start_date' => 'start_date',
]);
$a('dry-run accepts digit-only person_id',
    isset($result['rows'][2]) && (int) $result['rows'][2]['person_id'] === 1042);
$a("dry-run rejects non-integer 'abc'",
    isset($result['errors'][3])
    && str_contains(implode(' ', $result['errors'][3]), 'not an integer'));
$a("dry-run rejects decimal '1042.5'",
    isset($result['errors'][4])
    && str_contains(implode(' ', $result['errors'][4]), 'not an integer'));

echo "\n3. Dry-run validator — id beats email\n";
$a('lookup gathers BOTH idsWanted and emailsWanted in one pass',
    str_contains($svc, '$idsWanted    = [];')
    && str_contains($svc, '$emailsWanted = [];'));
$a('person_id query is tenant-scoped + soft-delete-aware',
    str_contains($svc, 'WHERE tenant_id = ? AND deleted_at IS NULL')
    && str_contains($svc, "id IN ({\$placeholders})"));
$a('when id is set, email is informational (skipped)',
    str_contains($svc, 'person_id wins → email is informational only'));
$a('rows with neither id NOR email emit the right error',
    str_contains($svc, "'either person_id or person_email is required'"));
$a('person_id miss message is tenant-scoped',
    str_contains($svc, "person_id: {\$pid} not found in this tenant's People"));
$a('email-fallback suggestion still references the id workflow',
    str_contains($svc, 'paste the person_id column from the People directory'));

echo "\n4. Commit handler — same precedence + placement_id update path\n";
$a('commit reads pid via array_key_exists/is_int (not raw cast)',
    str_contains($svc, "\$hasPidCol  = array_key_exists('person_id', \$row)")
    && str_contains($svc, '$pid        = $hasPidCol && is_int($row[\'person_id\']) ? (int) $row[\'person_id\'] : 0;'));
$a('commit looks up person by id when pid > 0',
    str_contains($svc, "WHERE tenant_id = :tenant_id AND id = :pid AND deleted_at IS NULL"));
$a('commit errors out cleanly when neither id nor email present',
    str_contains($svc, "throw new \\RuntimeException('either person_id or person_email is required')"));
$a('update-existing uses placement_id FIRST',
    strpos($svc, "if (!empty(\$row['placement_id']))")
  < strpos($svc, "if (!\$existing && !empty(\$row['external_id']))"));
$a('placement_id miss is a hard error (not silent fallback)',
    str_contains($svc, "throw new \\RuntimeException(\"placement_id not found: {\$row['placement_id']}\");"));

echo "\n5. UI — <IdBadge /> + click-to-copy\n";
$a('IdBadge component exists',
    str_contains($badgeUi, 'export default function IdBadge'));
$a('IdBadge writes the bare integer to clipboard (CSV-friendly)',
    str_contains($badgeUi, "navigator.clipboard.writeText(String(id))"));
$a('IdBadge shows ✓ copied feedback briefly',
    str_contains($badgeUi, 'copied') && str_contains($badgeUi, 'setTimeout(() => setCopied(false), 1200)'));
$a('Placements list imports + uses IdBadge with PL prefix',
    str_contains($listUi, "import IdBadge from '../../../dashboard/src/components/IdBadge'")
    && str_contains($listUi, '<IdBadge id={p.id} prefix="PL"'));
$a('Placements list also surfaces the linked person_id',
    str_contains($listUi, '<IdBadge id={p.person_id} prefix="P"'));
$a('Placements list header has an ID column (colSpan covers all columns)',
    str_contains($listUi, "<th>ID</th>")
    && (str_contains($listUi, 'colSpan={9}') || str_contains($listUi, 'colSpan={isDraftView ? 10 : 9}')));
$a('PlacementDetail surfaces PL + linked P badges',
    str_contains($detailUi, '<IdBadge id={placement.id} prefix="PL"')
    && str_contains($detailUi, '<IdBadge id={placement.person_id} prefix="P"'));
$a('PersonDetail header surfaces the P badge next to the name',
    str_contains($personUi, '<IdBadge id={person.id} prefix="P"'));

echo "\n6. PHP syntax\n";
foreach ([
    '/app/modules/placements/api/csv_import.php',
    '/app/core/CsvImportService.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Placements CSV id-lookup smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
