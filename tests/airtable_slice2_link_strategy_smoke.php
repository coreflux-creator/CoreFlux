<?php
/**
 * airtable_slice2_link_strategy_smoke.php
 *
 * Slice 2 — Airtable integration: real entity linkages.
 *
 *   • Migration 082 adds link_strategy / link_match_airtable_field /
 *     link_match_internal_column / link_unmatched_action to
 *     airtable_table_mappings and widens external_entity_mappings
 *     sync_status ENUM.
 *   • core/airtable/sync.php exposes AIRTABLE_ENTITY_LINK_DEFAULTS
 *     (placement → external_id, vendor → match_column on
 *     ap_vendors_index.vendor_name, etc.), airtableResolveLink(),
 *     airtableRelinkExistingRows(), airtableLinkStats().
 *   • api/airtable.php exposes relink / link_stats / unmatched /
 *     link_manual cases with thin /api/airtable/*.php shims.
 *   • AirtableSettings.jsx surfaces the linkage policy editor +
 *     per-row badge + Relink button.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Airtable Slice 2 — Link Strategy smoke\n";
echo "======================================\n\n";

$ROOT = dirname(__DIR__);

// --- Migration 082 -------------------------------------------------
echo "Migration 082 — airtable_link_strategy\n";
$mig = $read("{$ROOT}/core/migrations/082_airtable_link_strategy.sql");
$a('file exists', $mig !== '');
$a('adds link_strategy ENUM',                   str_contains($mig, 'link_strategy ENUM("external_id","match_column","manual","none")'));
$a('adds link_match_airtable_field VARCHAR',    str_contains($mig, 'link_match_airtable_field   VARCHAR(120) NULL'));
$a('adds link_match_internal_column VARCHAR',   str_contains($mig, 'link_match_internal_column  VARCHAR(120) NULL'));
$a('adds link_unmatched_action ENUM',           str_contains($mig, 'link_unmatched_action ENUM("skip","park","create_stub")'));
$a('default link_strategy = none (BC safe)',    str_contains($mig, 'DEFAULT "none"'));
$a('default unmatched_action = park (Op chose B)', str_contains($mig, 'DEFAULT "park"'));
$a('widens sync_status to include unmatched',   str_contains($mig, '"unmatched","ambiguous"'));
$a('idempotent column add (information_schema check)',
                                                 str_contains($mig, 'information_schema.COLUMNS')
                                              && str_contains($mig, "COLUMN_NAME  = 'link_strategy'"));
$a('seeds writable_targets for ap_vendors_index',
                                                 str_contains($mig, "'ap_vendors_index'") &&
                                                 str_contains($mig, "'vendor_name'"));
$a('seeds writable_targets for companies.name', str_contains($mig, "'companies',        'name'"));

// --- core/airtable/sync.php — Slice 2 helpers ----------------------
echo "\ncore/airtable/sync.php — resolver + relink + stats\n";
$sync = $read("{$ROOT}/core/airtable/sync.php");
$a('AIRTABLE_LINK_STRATEGIES constant',         str_contains($sync, "AIRTABLE_LINK_STRATEGIES   = ['external_id', 'match_column', 'manual', 'none']"));
$a('AIRTABLE_UNMATCHED_ACTIONS constant',       str_contains($sync, "AIRTABLE_UNMATCHED_ACTIONS = ['skip', 'park', 'create_stub']"));
$a('AIRTABLE_ENTITY_LINK_DEFAULTS map',         str_contains($sync, 'const AIRTABLE_ENTITY_LINK_DEFAULTS = ['));
$a('default: placement → external_id',
    str_contains($sync, "'placement'  => ['external_id',  'placements',        'external_id',  'park']"));
$a('default: vendor → match_column on ap_vendors_index.vendor_name',
    str_contains($sync, "'vendor'     => ['match_column', 'ap_vendors_index',  'vendor_name',  'park']"));
$a('default: company → match_column on companies.name',
    str_contains($sync, "'company'    => ['match_column', 'companies',         'name',         'park']"));
$a('default: contact → match_column on people.email_primary',
    str_contains($sync, "'contact'    => ['match_column', 'people',            'email_primary','park']"));
$a('airtableResolveLinkDefaults() helper',      str_contains($sync, 'function airtableResolveLinkDefaults('));
$a('airtableResolveLink() helper',              str_contains($sync, 'function airtableResolveLink('));
$a('airtableRelinkExistingRows() helper',       str_contains($sync, 'function airtableRelinkExistingRows('));
$a('airtableLinkStats() helper',                str_contains($sync, 'function airtableLinkStats('));
$a('resolver: none → ok with null id',          str_contains($sync, "return ['action' => 'link', 'internal_id' => null, 'sync_status' => 'ok']"));
$a('resolver: external_id needle = Airtable rec id',
                                                 str_contains($sync, "\$needle = \$externalId;"));
$a('resolver: match_column unwraps linked-record arrays',
                                                 str_contains($sync, "if (is_array(\$raw)) \$raw = \$raw[0] ?? null;"));
$a('resolver: rejects SQL injection on column name',
                                                 str_contains($sync, "preg_match('/^[A-Za-z0-9_]+\$/', \$lookupCol)"));
$a('resolver: LIMIT 2 to detect ambiguous',     str_contains($sync, 'LIMIT 2'));
$a('resolver: 0 matches → unmatched',           str_contains($sync, 'if (count($matches) === 0) return _airtableUnmatched'));
$a('resolver: 2+ matches → ambiguous',          str_contains($sync, 'if (count($matches) >  1)  return _airtableAmbiguous'));
$a('resolver: tolerates missing lookup table',  str_contains($sync, "[airtableResolveLink]"));

// --- sync loop refactored to use resolver --------------------------
echo "\nairtableSyncTable — Slice 2 sync loop\n";
$a('tracks linked/unmatched/ambiguous counters',
    str_contains($sync, '$linked = 0; $unmatched = 0; $ambiguous = 0;'));
$a('calls airtableResolveLink per record',     str_contains($sync, '$resolved = airtableResolveLink('));
$a('skip action drops record from sync',       str_contains($sync, "if (\$resolved['action'] === 'skip') {"));
$a('park action lets non-ok rows land',         str_contains($sync, "if (\$syncStatus !== 'ok') {"));
$a('patches sync_status post-upsert (not via mappingUpsert)',
    str_contains($sync, "UPDATE external_entity_mappings\n                                SET sync_status = :s"));
$a('audit detail carries link_strategy',       str_contains($sync, "'link_strategy' => \$mapping['link_strategy']"));
$a('return envelope surfaces linked/unmatched/ambiguous',
    str_contains($sync, "'linked'    => \$linked,    'unmatched' => \$unmatched, 'ambiguous' => \$ambiguous,"));

// --- mappingList + Upsert surface new cols -------------------------
echo "\nmappingList + Upsert persist new cols\n";
$a('mappingList SELECTs link_strategy + 3 more cols',
    str_contains($sync, 'link_strategy, link_match_airtable_field,
                link_match_internal_column, link_unmatched_action,'));
$a('Upsert INSERT carries 4 new cols',
    str_contains($sync, "INSERT INTO airtable_table_mappings\n                (tenant_id, base_id, base_name, table_id, table_name,
                 internal_entity, direction, field_map, primary_field,
                 link_strategy, link_match_airtable_field,
                 link_match_internal_column, link_unmatched_action,"));
$a('Upsert UPDATE carries 4 new cols',
    str_contains($sync, "link_strategy = :ls,
                    link_match_airtable_field  = :lmf,
                    link_match_internal_column = :lic,
                    link_unmatched_action      = :lua"));
$a('Upsert validates strategy enum',           str_contains($sync, "link_strategy must be one of: '"));

// --- API endpoints --------------------------------------------------
echo "\napi/airtable.php — relink / link_stats / unmatched / link_manual\n";
$router = $read("{$ROOT}/api/airtable.php");
$a("router case 'relink'",                     str_contains($router, "case 'relink': {"));
$a("router case 'link_stats'",                 str_contains($router, "case 'link_stats': {"));
$a("router case 'unmatched'",                  str_contains($router, "case 'unmatched': {"));
$a("router case 'link_manual'",                str_contains($router, "case 'link_manual': {"));
$a('relink requires manage RBAC',              preg_match("/case 'relink':.*?integrations\.airtable\.manage/s", $router) === 1);
$a('link_stats requires view RBAC',            preg_match("/case 'link_stats':.*?integrations\.airtable\.view/s", $router) === 1);
$a('unmatched accepts status=unmatched|ambiguous',
    preg_match("/case 'unmatched':.*?status must be unmatched\|ambiguous/s", $router) === 1);
$a('link_manual writes UPDATE sync_status=ok', preg_match("/case 'link_manual':.*?sync_status = 'ok'/s", $router) === 1);
$a('unmatched.php shim exists', is_file("{$ROOT}/api/airtable/unmatched.php"));
$a('relink.php shim exists',     is_file("{$ROOT}/api/airtable/relink.php"));
$a('link_stats.php shim exists', is_file("{$ROOT}/api/airtable/link_stats.php"));
$a('link_manual.php shim exists', is_file("{$ROOT}/api/airtable/link_manual.php"));

// --- AirtableSettings.jsx ------------------------------------------
echo "\nAirtableSettings.jsx — Linkage UI\n";
$ui = $read("{$ROOT}/dashboard/src/pages/AirtableSettings.jsx");
$a('imports useState for linkStrategy state',  str_contains($ui, 'const [linkStrategy,   setLinkStrategy]   = useState'));
$a('persists link_strategy via mapping_save', str_contains($ui, "link_strategy:                 linkStrategy   || undefined"));
$a('Linkage fieldset rendered',                str_contains($ui, 'data-testid="airtable-linkage-section"'));
$a('strategy dropdown testid',                 str_contains($ui, 'data-testid="airtable-link-strategy"'));
$a('unmatched dropdown testid',                str_contains($ui, 'data-testid="airtable-link-unmatched"'));
$a('match_column reveals air-field+int-column inputs',
    str_contains($ui, "{linkStrategy === 'match_column' && (") &&
    str_contains($ui, 'data-testid="airtable-link-airfield"') &&
    str_contains($ui, 'data-testid="airtable-link-intcolumn"'));
$a('row fetches link_stats on mount',          str_contains($ui, "/api/airtable/link_stats.php?action=link_stats&mapping_id="));
$a('row Relink button',                        str_contains($ui, 'testid={`airtable-relink-${mapping.id}`}'));
$a('row badge shows linked count',             str_contains($ui, 'testid={`airtable-link-linked-${mapping.id}`}'));
$a('row badge shows unmatched + ambiguous when >0',
    str_contains($ui, 'testid={`airtable-link-unmatched-${mapping.id}`}') &&
    str_contains($ui, 'testid={`airtable-link-ambiguous-${mapping.id}`}'));
$a('sync toast surfaces linked/unmatched/ambiguous',
    str_contains($ui, 'linked ${r.linked || 0}, unmatched ${r.unmatched || 0}, ambiguous ${r.ambiguous || 0}'));
$a('Badge helper component',                   str_contains($ui, 'function Badge({ tone, label, testid })'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
