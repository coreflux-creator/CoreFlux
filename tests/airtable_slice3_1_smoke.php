<?php
/**
 * airtable_slice3_1_smoke.php
 *
 * Slice 3.1 — Storage-only metric fix + Records Vault drill-down +
 * Discover/Bulk-add tables affordance.
 *
 *   • airtableLinkStats() splits 'ok' rows into 'linked' vs
 *     'stored_only' based on the mapping's link_strategy. Without
 *     this the Health rollup misleadingly reported 100% linked for
 *     records that are actually orphaned in the integrations vault.
 *   • /api/airtable.php → cases 'vault', 'discover_tables',
 *     'mapping_save_bulk' added. Shim files dropped in api/airtable/.
 *   • /api/airtable.php health rollup includes stored_only +
 *     mappings_stored_only. The 'no_strategy' hint code became
 *     'stored_only' with a stronger CTA.
 *   • AirtableSettings.jsx HealthPanel: Stored-only tile, per-mapping
 *     "Records vault" drill-down button + STORAGE ONLY badge.
 *   • AirtableSettings.jsx MappingEditor: "Sync more tables" button
 *     opens DiscoverTablesModal for bulk multi-table add.
 *   • AirtableSettings.jsx adds VaultBrowser + DiscoverTablesModal
 *     components with full a11y, filtering, pagination.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Airtable Slice 3.1 — Vault + Discover + metric fix smoke\n";
echo "=========================================================\n\n";

$ROOT = dirname(__DIR__);

// --- core/airtable/sync.php — airtableLinkStats fix ----------------
echo "core/airtable/sync.php — airtableLinkStats\n";
$sync = $read("{$ROOT}/core/airtable/sync.php");
$a('splits ok→linked vs stored_only by link_strategy',
                                                str_contains($sync, "\$isStoredOnly = ((string) (\$mapping['link_strategy'] ?? 'none')) === 'none';"));
$a('linked field zero when strategy=none',     str_contains($sync, "'linked'      => \$isStoredOnly ? 0           : \$okCount,"));
$a('stored_only field surfaced',               str_contains($sync, "'stored_only' => \$isStoredOnly ? \$okCount    : 0,"));
$a('still preserves unmatched/ambiguous/stale/error',
                                                str_contains($sync, "'unmatched'   => (int) (\$rows['unmatched'] ?? 0),")
                                            && str_contains($sync, "'ambiguous'   => (int) (\$rows['ambiguous'] ?? 0),")
                                            && str_contains($sync, "'stale'       => (int) (\$rows['stale']     ?? 0),")
                                            && str_contains($sync, "'error'       => (int) (\$rows['error']     ?? 0),"));

// --- api/airtable.php — new actions --------------------------------
echo "\napi/airtable.php — new vault / discover / bulk actions\n";
$api = $read("{$ROOT}/api/airtable.php");
$a("case 'vault' exists",                      str_contains($api, "case 'vault':"));
$a("case 'discover_tables' exists",            str_contains($api, "case 'discover_tables':"));
$a("case 'mapping_save_bulk' exists",          str_contains($api, "case 'mapping_save_bulk':"));
$a('vault paginates with limit + offset',      str_contains($api, "(int) (api_query('limit')  ?? 50)")
                                            && str_contains($api, "(int) (api_query('offset') ?? 0)"));
$a('vault supports text search (q param)',     str_contains($api, '$q      = trim((string) (api_query(\'q\') ?? \'\'));')
                                            && str_contains($api, "external_id LIKE :q OR payload_snapshot LIKE :q"));
$a('vault returns top_fields fingerprint',     str_contains($api, "'top_fields'      => \$topFields"));
$a('vault flags is_stored_only',               str_contains($api, "'is_stored_only'  => ((string) (\$mapping['link_strategy'] ?? 'none')) === 'none',"));
$a('vault surfaces airtable_record_url per row',
                                                str_contains($api, "'_airtable_record_url'"));
$a('discover_tables lists every base + table', str_contains($api, "airtableListBases(\$tid)")
                                            && str_contains($api, "airtableListTables(\$tid, \$bid)"));
$a('discover_tables flags already-mapped tuples',
                                                str_contains($api, "\$existing[\$key] = (int) (\$m['id'] ?? 0);")
                                            && str_contains($api, "'mapped'       => \$isMapped"));
$a('discover_tables tolerates per-base failures',
                                                str_contains($api, '$tables = []; // base may be inaccessible'));
$a('discover_tables returns tables_total + tables_mapped',
                                                str_contains($api, "'tables_total'  => \$tableTotal")
                                            && str_contains($api, "'tables_mapped' => \$tableMapped"));
$a('mapping_save_bulk caps batch at 50',       str_contains($api, "if (count(\$items) > 50)"));
$a('mapping_save_bulk reports created/skipped/errors',
                                                str_contains($api, "'created' => \$created,")
                                            && str_contains($api, "'skipped' => \$skipped,")
                                            && str_contains($api, "'errors'  => \$errors,"));

// --- Health rollup includes stored_only ----------------------------
echo "\napi/airtable.php — health rollup includes stored_only\n";
$a("'stored_only' key in rollup",              str_contains($api, "'stored_only'     => 0,"));
$a("'mappings_stored_only' counter",           str_contains($api, "'mappings_stored_only' => 0,"));
$a('per-mapping object includes is_stored_only',
                                                str_contains($api, "'is_stored_only'  => ((string) (\$m['link_strategy'] ?? 'none')) === 'none',"));
$a("hint code: stored_only (replacing no_strategy)",
                                                str_contains($api, "'code'     => 'stored_only'"));
$a('stored_only hint mentions Records vault CTA',
                                                str_contains($api, 'Browse them under "Records vault" below'));

// --- shim files ----------------------------------------------------
echo "\napi/airtable/{vault,discover_tables,mapping_save_bulk}.php — shims\n";
foreach (['vault', 'discover_tables', 'mapping_save_bulk'] as $shim) {
    $p = "{$ROOT}/api/airtable/{$shim}.php";
    $a("{$shim}.php shim exists",              file_exists($p));
    $a("{$shim}.php delegates to ../airtable.php",
                                                file_exists($p)
                                            && str_contains((string) file_get_contents($p), "require __DIR__ . '/../airtable.php'"));
}

// --- AirtableSettings.jsx — Tile + VaultBrowser + Discover ---------
echo "\nAirtableSettings.jsx — Tile/VaultBrowser/DiscoverTablesModal\n";
$set = $read("{$ROOT}/dashboard/src/pages/AirtableSettings.jsx");
$a('new "Stored only (no link)" tile',         str_contains($set, 'testid="airtable-health-tile-stored-only"')
                                            && str_contains($set, 'label="Stored only (no link)"'));
$a('per-mapping STORAGE ONLY badge',           str_contains($set, 'STORAGE ONLY')
                                            && str_contains($set, 'data-testid={`airtable-health-stored-badge-${m.id}`}'));
$a('per-mapping Records vault button',         str_contains($set, 'data-testid={`airtable-health-vault-btn-${m.id}`}')
                                            && str_contains($set, 'onClick={() => setVaultMappingId(m.id)}'));
$a('vaultMappingId state',                     str_contains($set, "const [vaultMappingId, setVaultMappingId] = useState(null);"));
$a('VaultBrowser component declared',          str_contains($set, 'function VaultBrowser('));
$a('VaultBrowser modal testid',                str_contains($set, 'data-testid="airtable-vault-modal"'));
$a('VaultBrowser search input',                str_contains($set, 'data-testid="airtable-vault-search"'));
$a('VaultBrowser top-fields chips',            str_contains($set, 'data-testid="airtable-vault-top-fields"'));
$a('VaultBrowser per-row inspect toggle',      str_contains($set, 'data-testid={`airtable-vault-toggle-${r.id}`}'));
$a('VaultBrowser per-row open-in-Airtable link', str_contains($set, 'data-testid={`airtable-vault-open-${r.id}`}'));
$a('VaultBrowser prev/next pagination',        str_contains($set, 'data-testid="airtable-vault-prev"')
                                            && str_contains($set, 'data-testid="airtable-vault-next"'));
$a('STORAGE ONLY badge in VaultBrowser header', substr_count($set, 'STORAGE ONLY') >= 2);

$a('"Sync more tables" button in MappingEditor',
                                                str_contains($set, 'data-testid="airtable-discover-tables-btn"')
                                            && str_contains($set, 'Sync more tables'));
$a('DiscoverTablesModal component declared',   str_contains($set, 'function DiscoverTablesModal('));
$a('discover modal testid',                    str_contains($set, 'data-testid="airtable-discover-modal"'));
$a('discover default-entity dropdown',         str_contains($set, 'data-testid="airtable-discover-default-entity"'));
$a('discover per-table checkboxes',            str_contains($set, 'data-testid={`airtable-discover-check-${t.id}`}'));
$a('discover per-table entity dropdown',       str_contains($set, 'data-testid={`airtable-discover-entity-${t.id}`}'));
$a('discover stats label',                     str_contains($set, 'data-testid="airtable-discover-stats"'));
$a('bulk apply hits mapping_save_bulk',        str_contains($set, "/api/airtable/mapping_save_bulk.php?action=mapping_save_bulk"));

// --- Summary --------------------------------------------------------
echo "\n\n----------------------------------------\n";
echo "Slice 3.1 smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
