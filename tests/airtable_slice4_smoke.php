<?php
/**
 * airtable_slice4_smoke.php
 *
 * Slice 4 — Reconciliation queue + Promote-vault wizard.
 *
 *   • core/airtable/sync_slice4.php — airtableSearchInternalEntities,
 *     airtableCreateStubFromVault, airtablePromoteVaultMapping helpers.
 *   • api/airtable.php — new cases: search_entities, create_stub,
 *     promote_vault. + shim files.
 *   • dashboard/src/pages/ReconciliationModal.jsx — per-row triage UI.
 *   • dashboard/src/pages/PromoteVaultModal.jsx — bulk-promote wizard.
 *   • AirtableSettings.jsx — Reconcile + Promote buttons per row.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Airtable Slice 4 — Reconciliation + Promote smoke\n";
echo "==================================================\n\n";

$ROOT = dirname(__DIR__);

// --- core/airtable/sync_slice4.php --------------------------------
echo "core/airtable/sync_slice4.php\n";
$s4 = $read("{$ROOT}/core/airtable/sync_slice4.php");
$a('file exists',                              $s4 !== '');
$a('require_once sync.php',                    str_contains($s4, "require_once __DIR__ . '/sync.php';"));

$a('airtableSearchInternalEntities defined',   str_contains($s4, 'function airtableSearchInternalEntities'));
$a('  whitelists table identifier',            str_contains($s4, "preg_match('/^[A-Za-z0-9_]+\$/', \$table)"));
$a('  tenant-scoped LIKE query',               str_contains($s4, 'WHERE tenant_id = :t'));
$a('  per-entity label/sublabel shaping',      str_contains($s4, "'label'    => \$label,"));

$a('airtableCreateStubFromVault defined',      str_contains($s4, 'function airtableCreateStubFromVault'));
$a('  strips Airtable metadata fields',        str_contains($s4, "\$k[0] !== '_'"));
$a('  returns null on insufficient data',      substr_count($s4, 'return null;') >= 6);
$a('  inserts into tenant-scoped table',       str_contains($s4, 'INSERT INTO `%s` (%s) VALUES (%s)'));
$a('  audit logs the create_stub action',      str_contains($s4, "airtableAudit(\$tenantId, 'create_stub'"));
$a('  handles company entity (Name aliases)',  str_contains($s4, "['Company', 'Company Name', 'Name', 'name']"));
$a('  handles contact entity (Email aliases)', str_contains($s4, "['Email', 'Email Address', 'Primary Email']"));
$a('  handles vendor entity',                  str_contains($s4, "case 'vendor':"));
$a('  handles placement entity',               str_contains($s4, "case 'placement':"));
$a('  splits combined Name into first/last',   str_contains($s4, "preg_split('/\\s+/', trim(\$combined), 2)"));

$a('airtablePromoteVaultMapping defined',      str_contains($s4, 'function airtablePromoteVaultMapping'));
$a('  upserts mapping with new policy',        str_contains($s4, 'airtableMappingUpsert($tenantId, $payload, $userId)'));
$a('  migrates vault rows on entity change',   str_contains($s4, 'SET internal_entity_type = :new'));
$a('  re-runs airtableResolveLink per row',    str_contains($s4, 'airtableResolveLink($tenantId, $mapping'));
$a('  optionally creates stubs',               str_contains($s4, '$createStubs')
                                            && str_contains($s4, 'airtableCreateStubFromVault'));
$a('  returns rollup with scanned/linked/stubs',
                                                str_contains($s4, "'scanned'         => \$scanned")
                                            && str_contains($s4, "'linked'          => \$linked")
                                            && str_contains($s4, "'stubs_created'   => \$stubsCreated")
                                            && str_contains($s4, "'stubs_failed'    => \$stubsFailed"));
$a('  audit logs promote_vault',               str_contains($s4, "airtableAudit(\$tenantId, 'promote_vault'"));

$lint = shell_exec('php -l ' . escapeshellarg("{$ROOT}/core/airtable/sync_slice4.php") . ' 2>&1');
$a('PHP -l passes',                            is_string($lint) && str_contains($lint, 'No syntax errors detected'));

// --- api/airtable.php new actions ---------------------------------
echo "\napi/airtable.php — new actions\n";
$api = $read("{$ROOT}/api/airtable.php");
$a("case 'search_entities' exists",            str_contains($api, "case 'search_entities':"));
$a('  RBAC view gate',                         str_contains($api, "rbac_legacy_require(\$user, 'integrations.airtable.view');\n        require_once __DIR__ . '/../core/airtable/sync_slice4.php';"));
$a('  validates entity against allowlist',     str_contains($api, "in_array(\$entity, AIRTABLE_INTERNAL_ENTITIES, true)"));

$a("case 'create_stub' exists",                str_contains($api, "case 'create_stub':"));
$a('  RBAC manage gate',                       str_contains($api, "rbac_legacy_require(\$user, 'integrations.airtable.manage');"));
$a('  tenant-scoped vault row lookup',         str_contains($api, "WHERE id = :id AND tenant_id = :t AND source_system = 'airtable'"));
$a('  422 on insufficient payload',            str_contains($api, "Could not infer enough fields to create a stub"));
$a('  flips mapping row to linked',            str_contains($api, "SET internal_entity_id = :iid,\n                    sync_status = 'ok',"));

$a("case 'promote_vault' exists",              str_contains($api, "case 'promote_vault':"));
$a('  loads sync_slice4 lazily',               substr_count($api, "require_once __DIR__ . '/../core/airtable/sync_slice4.php';") >= 3);
$a('  validates entity/strategy/unmatched',    str_contains($api, 'AIRTABLE_INTERNAL_ENTITIES')
                                            && str_contains($api, 'AIRTABLE_LINK_STRATEGIES')
                                            && str_contains($api, 'AIRTABLE_UNMATCHED_ACTIONS'));
$a('  threads create_stubs flag',              str_contains($api, '$createStubs = (bool) ($body[\'create_stubs\'] ?? false);'));
$a('  threads _previous_entity for migration', str_contains($api, "'_previous_entity'            => \$previousEntity"));

// --- shim files ----------------------------------------------------
echo "\napi/airtable/{search_entities,create_stub,promote_vault}.php\n";
foreach (['search_entities', 'create_stub', 'promote_vault'] as $shim) {
    $p = "{$ROOT}/api/airtable/{$shim}.php";
    $a("{$shim}.php shim exists",              file_exists($p));
    $a("{$shim}.php delegates to ../airtable.php",
                                                file_exists($p)
                                            && str_contains((string) file_get_contents($p), "require __DIR__ . '/../airtable.php'"));
}

// --- ReconciliationModal.jsx --------------------------------------
echo "\ndashboard/src/pages/ReconciliationModal.jsx\n";
$rc = $read("{$ROOT}/dashboard/src/pages/ReconciliationModal.jsx");
$a('default export',                           str_contains($rc, 'export default function ReconciliationModal'));
$a('root testid',                              str_contains($rc, 'data-testid="airtable-reconcile-modal"'));
$a('status toggle (unmatched/ambiguous)',      str_contains($rc, 'data-testid="airtable-reconcile-status"'));
$a('row payload toggle',                       str_contains($rc, 'data-testid={`airtable-reconcile-toggle-${row.id}`}'));
$a('row open-in-Airtable link',                str_contains($rc, 'data-testid={`airtable-reconcile-open-${row.id}`}'));
$a('row typeahead search',                     str_contains($rc, 'data-testid={`airtable-reconcile-search-${row.id}`}'));
$a('row pick suggestion',                      str_contains($rc, 'data-testid={`airtable-reconcile-pick-${row.id}-${res.id}`}'));
$a('row create stub action',                   str_contains($rc, 'data-testid={`airtable-reconcile-stub-${row.id}`}'));
$a('typeahead calls search_entities endpoint', str_contains($rc, '/api/airtable/search_entities.php?action=search_entities'));
$a('manual link calls link_manual endpoint',   str_contains($rc, '/api/airtable/link_manual.php?action=link_manual'));
$a('stub calls create_stub endpoint',          str_contains($rc, '/api/airtable/create_stub.php?action=create_stub'));
$a('debounces search by 280ms',                str_contains($rc, '280'));

// --- PromoteVaultModal.jsx ----------------------------------------
echo "\ndashboard/src/pages/PromoteVaultModal.jsx\n";
$pm = $read("{$ROOT}/dashboard/src/pages/PromoteVaultModal.jsx");
$a('default export',                           str_contains($pm, 'export default function PromoteVaultModal'));
$a('root testid',                              str_contains($pm, 'data-testid="airtable-promote-modal"'));
$a('entity picker',                            str_contains($pm, 'data-testid="airtable-promote-entity"'));
$a('strategy picker',                          str_contains($pm, 'data-testid="airtable-promote-strategy"'));
$a('match_column-only fields (at_field/int_col)',
                                                str_contains($pm, 'data-testid="airtable-promote-at-field"')
                                            && str_contains($pm, 'data-testid="airtable-promote-int-col"'));
$a('unmatched-action picker',                  str_contains($pm, 'data-testid="airtable-promote-unmatched"'));
$a('create-stubs checkbox',                    str_contains($pm, 'data-testid="airtable-promote-stubs"'));
$a('submit button + endpoint',                 str_contains($pm, 'data-testid="airtable-promote-submit"')
                                            && str_contains($pm, '/api/airtable/promote_vault.php?action=promote_vault'));
$a('rollup testids',                           str_contains($pm, 'data-testid="airtable-promote-rollup"')
                                            && str_contains($pm, 'data-testid="airtable-promote-linked"')
                                            && str_contains($pm, 'data-testid="airtable-promote-stubs-created"')
                                            && str_contains($pm, 'data-testid="airtable-promote-still-unmatched"'));
$a('pulls top_fields fingerprint from vault',  str_contains($pm, '/api/airtable/vault.php?action=vault&mapping_id='));

// --- AirtableSettings.jsx — wiring --------------------------------
echo "\nAirtableSettings.jsx — wiring\n";
$set = $read("{$ROOT}/dashboard/src/pages/AirtableSettings.jsx");
$a('imports ReconciliationModal',              str_contains($set, "import ReconciliationModal from './ReconciliationModal'"));
$a('imports PromoteVaultModal',                str_contains($set, "import PromoteVaultModal   from './PromoteVaultModal'"));
$a('per-row Reconcile button (conditional)',   str_contains($set, 'data-testid={`airtable-reconcile-btn-${mapping.id}`}'));
$a('queue count badge on Reconcile',           str_contains($set, 'data-testid={`airtable-reconcile-count-${mapping.id}`}'));
$a('per-row Promote button on stored-only',    str_contains($set, 'data-testid={`airtable-promote-btn-${mapping.id}`}')
                                            && str_contains($set, "mapping.link_strategy === 'none' && stats && stats.stored_only > 0"));
$a('mounts ReconciliationModal',               str_contains($set, '<ReconciliationModal'));
$a('mounts PromoteVaultModal',                 str_contains($set, '<PromoteVaultModal'));
$a('reloads list after either modal closes',   str_contains($set, '() => { setReconciling(false); reload(); }')
                                            && str_contains($set, '() => { setPromoting(false); reload(); }'));

// --- Summary -------------------------------------------------------
echo "\n\n-------------------------------------\n";
echo "Airtable Slice 4 smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
