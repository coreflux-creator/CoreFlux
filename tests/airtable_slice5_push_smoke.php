<?php
/**
 * airtable_slice5_push_smoke.php
 *
 * Slice 5 — Airtable Push Direction & Bidirectional Sync.
 *
 *   • core/migrations/084_airtable_push_direction.sql — schema
 *   • core/airtable/sync.php — constants, mapping upsert handles
 *     reverse_field_map + push_unmatched_action, SELECT lists new
 *     columns, airtableSyncTable accepts 'pull' and 'both'.
 *   • core/airtable/sync_push.php — airtablePushMapping(), entity
 *     descriptors, unmatched action branching, linkage write-back.
 *   • api/airtable.php — push_now case + push_now.php shim.
 *   • cron/airtable_sync.php — runs push leg for push/both mappings.
 *   • dashboard/src/pages/AirtableSettings.jsx — direction dropdown
 *     wired to backend list, push fieldset, Push now button,
 *     reverse_field_map + push_unmatched_action submitted to API.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Airtable Slice 5 — Push Direction smoke\n";
echo "=======================================\n\n";

$ROOT = dirname(__DIR__);

// --- migration ----------------------------------------------------
echo "core/migrations/084_airtable_push_direction.sql\n";
$sql = $read("{$ROOT}/core/migrations/084_airtable_push_direction.sql");
$a('migration file exists',                  $sql !== '');
$a('adds reverse_field_map JSON',            str_contains($sql, 'ADD COLUMN IF NOT EXISTS reverse_field_map JSON'));
$a('adds push_unmatched_action VARCHAR(16)', str_contains($sql, 'ADD COLUMN IF NOT EXISTS push_unmatched_action VARCHAR(16)'));
$a('default push action create_new',         str_contains($sql, "DEFAULT 'create_new'"));
$a('adds last_push_at DATETIME',             str_contains($sql, 'ADD COLUMN IF NOT EXISTS last_push_at DATETIME'));
$a('adds last_push_error TEXT',              str_contains($sql, 'ADD COLUMN IF NOT EXISTS last_push_error TEXT'));
$a('adds last_push_records INT UNSIGNED',    str_contains($sql, 'ADD COLUMN IF NOT EXISTS last_push_records INT UNSIGNED'));

// --- core/airtable/sync.php --------------------------------------
echo "\ncore/airtable/sync.php\n";
$sync = $read("{$ROOT}/core/airtable/sync.php");
$a('AIRTABLE_MAPPING_DIRECTIONS includes pull/push/both/off',
    str_contains($sync, "AIRTABLE_MAPPING_DIRECTIONS = ['pull', 'push', 'both', 'off']"));
$a('AIRTABLE_PUSH_UNMATCHED_ACTIONS defined',
    str_contains($sync, "AIRTABLE_PUSH_UNMATCHED_ACTIONS = ['create_new', 'update_only', 'error']"));

$a('airtableMappingList SELECTs reverse_field_map',
    str_contains($sync, 'reverse_field_map, push_unmatched_action,')
    && str_contains($sync, 'last_push_at, last_push_error, last_push_records,'));
$a('mapping list decodes reverse_field_map JSON',
    str_contains($sync, "if (!empty(\$r['reverse_field_map']))")
    && str_contains($sync, "json_decode((string) \$r['reverse_field_map']"));
$a('mapping get decodes reverse_field_map JSON',
    str_contains($sync, "if (!empty(\$row['reverse_field_map']))"));

$a('upsert reads reverse_field_map payload',
    str_contains($sync, "\$reverseFieldMap     = \$payload['reverse_field_map']     ?? null;"));
$a('upsert reads push_unmatched_action with default create_new',
    str_contains($sync, "\$pushUnmatchedAction = trim((string) (\$payload['push_unmatched_action'] ?? 'create_new'));"));
$a('upsert validates reverse_field_map is array/object',
    str_contains($sync, "throw new \\InvalidArgumentException('reverse_field_map must be an object')"));
$a('upsert validates push_unmatched_action against allowlist',
    str_contains($sync, "AIRTABLE_PUSH_UNMATCHED_ACTIONS, true"));
$a('upsert UPDATE writes reverse_field_map + push_unmatched_action',
    str_contains($sync, 'reverse_field_map          = :rfm,')
    && str_contains($sync, 'push_unmatched_action      = :pua'));
$a('upsert INSERT writes reverse_field_map + push_unmatched_action',
    str_contains($sync, 'reverse_field_map, push_unmatched_action,')
    && str_contains($sync, ":rfm, :pua,"));

$a('airtableSyncTable accepts pull and both, blocks push/off',
    str_contains($sync, "if (!in_array(\$mapping['direction'], ['pull', 'both'], true))"));

// --- core/airtable/sync_push.php ---------------------------------
echo "\ncore/airtable/sync_push.php\n";
$push = $read("{$ROOT}/core/airtable/sync_push.php");
$a('file exists',                              $push !== '');
$a('declares strict_types',                    str_contains($push, 'declare(strict_types=1);'));
$a('requires sync.php',                        str_contains($push, "require_once __DIR__ . '/sync.php';"));
$a('AIRTABLE_PUSH_ENTITY_TABLES const',        str_contains($push, 'const AIRTABLE_PUSH_ENTITY_TABLES'));
$a('descriptor includes placement → placements',
    str_contains($push, "'placement' => ['table' => 'placements'"));
$a('descriptor includes contact → people',
    str_contains($push, "'contact'   => ['table' => 'people'"));
$a('descriptor includes vendor → ap_vendors_index',
    str_contains($push, "'vendor'    => ['table' => 'ap_vendors_index'"));
$a('descriptor uses updated_at touched_col',
    substr_count($push, "'touched_col' => 'updated_at'") >= 4);

$a('airtablePushMapping function defined',     str_contains($push, 'function airtablePushMapping('));
$a('rejects non-push direction',               str_contains($push, "if (!in_array(\$mapping['direction'], ['push', 'both'], true))"));
$a('rejects entities with no push descriptor', str_contains($push, "no push descriptor"));
$a('whitelists table identifier',              str_contains($push, "preg_match('/^[A-Za-z0-9_]+\$/', \$desc['table'])"));
$a('decodes reverse_field_map (array or json)',
    str_contains($push, '$reverseMapRaw = $mapping[\'reverse_field_map\']'));
$a('throws on empty reverse_field_map',        str_contains($push, "reverse_field_map is empty"));
$a('filters CoreFlux rows by since (last_push_at)',
    str_contains($push, "\$since = \$opts['since'] ?? \$mapping['last_push_at'] ?? null;"));
$a('caps limit at 2000',                       str_contains($push, "min(2000, (int) (\$opts['limit'] ?? 500))"));

$a('builds fields payload from reverse_field_map',
    str_contains($push, 'foreach ($reverseMap as $coreflux_col => $airtable_field)'));
$a('looks up linkage in external_entity_mappings',
    str_contains($push, "SELECT external_id FROM external_entity_mappings")
    && str_contains($push, "source_system = 'airtable'"));
$a('PATCH existing linked record',             str_contains($push, "'PATCH'")
                                            && str_contains($push, "/v0/{\$base}/{\$table}/"));
$a('update_only branch skips when unmatched',  str_contains($push, "if (\$unmatched === 'update_only')"));
$a('error branch records failure',             str_contains($push, "if (\$unmatched === 'error')"));
$a('create_new branch POSTs to Airtable',      str_contains($push, "'POST'")
                                            && str_contains($push, "['records' => [['fields' => \$fields]]]"));
$a('writes external_entity_mappings linkage on create',
    str_contains($push, 'INSERT INTO external_entity_mappings'));
$a('updates mapping last_push_at/error/records',
    str_contains($push, 'last_push_at      = NOW()')
    && str_contains($push, 'last_push_error   = :err')
    && str_contains($push, 'last_push_records = :n'));
$a('returns rollup with pushed/created/updated/errored',
    str_contains($push, "'scanned'           => count(\$rows)")
    && str_contains($push, "'pushed'            => \$pushed")
    && str_contains($push, "'created'           => \$created")
    && str_contains($push, "'updated'           => \$updated")
    && str_contains($push, "'errored'           => \$errored"));
$a('audits push action',                       str_contains($push, "airtableAudit(\$tenantId, 'push'"));

// --- api/airtable.php --------------------------------------------
echo "\napi/airtable.php\n";
$api = $read("{$ROOT}/api/airtable.php");
$a('push_now case present',                    str_contains($api, "case 'push_now': {"));
$a('push_now requires POST',                   str_contains($api, "if (\$method !== 'POST') api_error('Method not allowed', 405);")
                                            && str_contains($api, 'integrations.airtable.manage'));
$a('push_now requires require_once sync_push.php',
    str_contains($api, "require_once __DIR__ . '/../core/airtable/sync_push.php';"));
$a('push_now validates mapping_id',            str_contains($api, "if (\$mid <= 0) api_error('mapping_id required', 422);"));
$a('push_now calls airtablePushMapping',       str_contains($api, "\$rollup = airtablePushMapping(\$tid, \$mid"));

// --- api/airtable/push_now.php shim -------------------------------
echo "\napi/airtable/push_now.php\n";
$shim = $read("{$ROOT}/api/airtable/push_now.php");
$a('shim file exists',                         $shim !== '');
$a('shim requires parent router',              str_contains($shim, "require __DIR__ . '/../airtable.php';"));

// --- cron/airtable_sync.php --------------------------------------
echo "\ncron/airtable_sync.php\n";
$cron = $read("{$ROOT}/cron/airtable_sync.php");
$a('cron requires sync_push.php',              str_contains($cron, "require_once __DIR__ . '/../core/airtable/sync_push.php';"));
$a('cron selects mappings IN pull/push/both',  str_contains($cron, "m.direction IN ('pull','push','both')"));
$a('cron runs pull leg for pull or both',      str_contains($cron, "if (in_array(\$dir, ['pull', 'both'], true))"));
$a('cron runs push leg for push or both',      str_contains($cron, "if (in_array(\$dir, ['push', 'both'], true))"));
$a('cron skips push when reverse_field_map empty',
    str_contains($cron, '$rfm = $m[\'reverse_field_map\']'));
$a('cron summary reports push counts',         str_contains($cron, 'push: %d pushed'));

// --- frontend AirtableSettings.jsx -------------------------------
echo "\ndashboard/src/pages/AirtableSettings.jsx\n";
$jsx = $read("{$ROOT}/dashboard/src/pages/AirtableSettings.jsx");
$a('handlePushNow defined in MappingRow',      str_contains($jsx, 'const handlePushNow = async () =>'));
$a('handlePushNow posts to push_now.php',      str_contains($jsx, "'/api/airtable/push_now.php?action=push_now'"));
$a('push meta surfaced for push/both',         str_contains($jsx, "data-testid={`airtable-push-meta-\${mapping.id}`}"));
$a('last_push_at + last_push_records rendered',
    str_contains($jsx, '{mapping.last_push_at')
    && str_contains($jsx, '{mapping.last_push_records'));
$a('last_push_error rendered with red icon',   str_contains($jsx, '{mapping.last_push_error'));

$a('Sync now allows pull OR both',             str_contains($jsx, "!(mapping.direction === 'pull' || mapping.direction === 'both')"));
$a('Push now button rendered for push/both',   str_contains($jsx, "data-testid={`airtable-push-now-\${mapping.id}`}"));
$a('Push now button gated on direction',       str_contains($jsx, "(mapping.direction === 'push' || mapping.direction === 'both') && ("));

$a('reverseFieldMap state initialised from mapping',
    str_contains($jsx, 'const [reverseFieldMap, setReverseFieldMap] = useState('));
$a('pushUnmatched state defaults to create_new',
    str_contains($jsx, "const [pushUnmatched, setPushUnmatched] = useState(mapping?.push_unmatched_action || 'create_new');"));
$a('submit parses reverse_field_map JSON only for push/both',
    str_contains($jsx, "if (dir === 'push' || dir === 'both') {")
    && str_contains($jsx, 'parsedReverse = JSON.parse(reverseFieldMap'));
$a('submit posts reverse_field_map + push_unmatched_action',
    str_contains($jsx, 'reverse_field_map:    parsedReverse,')
    && str_contains($jsx, 'push_unmatched_action: pushUnmatched,'));

$a('push fieldset rendered for push/both',     str_contains($jsx, "data-testid=\"airtable-push-section\""));
$a('reverse field map textarea has testid',    str_contains($jsx, 'data-testid="airtable-reverse-fieldmap-input"'));
$a('push unmatched select has testid',         str_contains($jsx, 'data-testid="airtable-push-unmatched"'));
$a('push unmatched offers create_new/update_only/error',
    str_contains($jsx, '<option value="create_new">')
    && str_contains($jsx, '<option value="update_only">')
    && str_contains($jsx, '<option value="error">'));

// --- PHP syntax checks -------------------------------------------
echo "\nPHP syntax checks\n";
foreach (['core/airtable/sync.php', 'core/airtable/sync_push.php',
          'cron/airtable_sync.php', 'api/airtable.php',
          'api/airtable/push_now.php'] as $f) {
    $r = shell_exec("php -l {$ROOT}/{$f} 2>&1");
    $a("{$f} parses",                           is_string($r) && str_contains($r, 'No syntax errors'));
}

echo "\n" . str_repeat('=', 50) . "\n";
echo "Slice 5: {$pass} ✓ / {$fail} ✗\n";
exit($fail > 0 ? 1 : 0);
