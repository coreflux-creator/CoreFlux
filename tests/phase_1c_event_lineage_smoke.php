<?php
/**
 * Phase 1c — Event Lineage smoke (Live Books Rails, 2026-02-14).
 *
 * Pins:
 *   • Migration 038 creates event_lineage with the right shape.
 *   • core/event_lineage.php exposes link / walk / validate primitives,
 *     degrades gracefully when table is missing, cycle-safe, BFS-correct.
 *   • posting_engine/process.php auto-links lineage from emit dict's
 *     parent_event_id / parent_event_ids[] fields.
 *   • api/accounting/event_lineage.php read + manual-link surface works.
 *   • Registry validation respects parent_event_types from event_registry
 *     (the canonical lineage contract from EVENT_REGISTRY.md).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Migration 038\n";
$mig = $read(__DIR__ . '/../core/migrations/038_event_lineage.sql');
$a('creates event_lineage',                str_contains($mig, 'CREATE TABLE IF NOT EXISTS event_lineage'));
$a('many-to-many edge',                    str_contains($mig, 'parent_event_id') && str_contains($mig, 'child_event_id'));
$a('relationship_type defaults spawned_by',str_contains($mig, "relationship_type VARCHAR(40) NOT NULL DEFAULT 'spawned_by'"));
$a('UNIQUE on (parent,child,relationship)',str_contains($mig, 'UNIQUE KEY uq_lineage_edge (parent_event_id, child_event_id, relationship_type)'));
$a('parent + child + relationship indexed',
    str_contains($mig, 'idx_lineage_parent') &&
    str_contains($mig, 'idx_lineage_child')  &&
    str_contains($mig, 'idx_lineage_rel'));

echo "\nHelper library\n";
$lib = $read(__DIR__ . '/../core/event_lineage.php');
foreach ([
    'eventLineageLink',
    'eventLineageGetParents',
    'eventLineageGetChildren',
    'eventLineageGetAncestors',
    'eventLineageGetDescendants',
    'eventLineageGetRoot',
    'eventLineageValidateParentType',
] as $fn) {
    $a("library defines {$fn}",            str_contains($lib, "function {$fn}("));
}
$a('table-missing graceful return',        str_contains($lib, '_eventLineageTableExists'));
$a('rejects self-loops',                   str_contains($lib, '$parentEventId === $childEventId'));
$a('BFS cycle-safe ($visited check)',      str_contains($lib, '$visited = [$startEventId => true]') && str_contains($lib, 'isset($visited[$nid])'));
$a('uses INSERT IGNORE for idempotency',   str_contains($lib, 'INSERT IGNORE INTO event_lineage'));
$a('validate-parent reads event_registry', str_contains($lib, 'eventRegistryGet($childEventType)'));
$a('default relationship = spawned_by',    str_contains($lib, "\$relationshipType = 'spawned_by'"));

echo "\nPosting engine wire-in\n";
$proc = $read(__DIR__ . '/../core/posting_engine/process.php');
$a('engine reads parent_event_id (singular)',  str_contains($proc, "\$event['parent_event_id']"));
$a('engine reads parent_event_ids (plural)',   str_contains($proc, "\$event['parent_event_ids']"));
$a('engine accepts custom lineage_relationship',str_contains($proc, "\$event['lineage_relationship']"));
$a('engine calls eventLineageLink()',           str_contains($proc, "eventLineageLink(\$tenantId, \$pid, \$eventId"));
$a('engine wraps lineage in try/catch',         str_contains($proc, '[event-lineage] link failed'));

echo "\nAPI\n";
$api = $read(__DIR__ . '/../api/accounting/event_lineage.php');
$a('GET ?direction=ancestors',                  str_contains($api, "direction === 'ancestors'"));
$a('GET ?direction=descendants',                str_contains($api, "direction === 'descendants'"));
$a('GET ?root=1 returns originating event',     str_contains($api, "eventLineageGetRoot"));
$a('POST validates against registry',           str_contains($api, "eventLineageValidateParentType"));
$a('POST warns but does NOT block on mismatch', str_contains($api, "link recorded anyway"));
$a('max_depth clamped 1..32',                   str_contains($api, "max(1, min(32"));

echo "\nRegistry contract\n";
// Sanity: lineage parents declared in the seed should be reachable.
require_once __DIR__ . '/../core/seeds/event_registry_seed.php';
$rows  = eventRegistrySeedRows();
$names = array_column($rows, 0);
$lineageDeclared = 0;
$lineageInvalid  = 0;
foreach ($rows as [$type, , , , , , , $parents]) {
    foreach ($parents as $p) {
        $lineageDeclared++;
        if (!in_array($p, $names, true)) {
            echo "  FAIL  parent '{$p}' for child '{$type}' is not a registered event\n";
            $lineageInvalid++;
        }
    }
}
$a('every declared parent_event_type is a registered event',  $lineageInvalid === 0);
$a('at least one event declares parent_event_types',          $lineageDeclared > 0);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
