<?php
/**
 * Saved Treasury Scenarios smoke test.
 *
 * Covers:
 *   - Migration 026_treasury_scenario_presets.sql creates the table with
 *     uniqueness on (tenant_id, name) so re-saving the same name upserts.
 *   - api/treasury_scenario_presets.php parses, supports GET / POST /
 *     DELETE, RBAC-gated (read = view, write = manage).
 *   - POST validates name, description length cap, events array shape
 *     (kind whitelist, positive amount, YYYY-MM-DD), 50-event cap, and
 *     does NOT clamp dates (presets re-applied later may live outside
 *     today's window — clamping on save would corrupt them).
 *   - DELETE 422 on missing id, 404 on unknown id.
 *   - Module-namespaced kebab alias delegates.
 *   - TreasuryScenario.jsx mounts Save form + saved-list block, applies
 *     saved presets (replaces the stack), supports inline delete.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Migration — 026_treasury_scenario_presets.sql\n";
$migPath = "{$ROOT}/core/migrations/026_treasury_scenario_presets.sql";
$assert('migration file exists',                  is_readable($migPath));
$mig = (string) file_get_contents($migPath);
$assert('CREATE TABLE IF NOT EXISTS (idempotent)', strpos($mig, 'CREATE TABLE IF NOT EXISTS treasury_scenario_presets') !== false);
$assert('UNIQUE (tenant_id, name) — upsert path',
    strpos($mig, 'UNIQUE KEY uk_tenant_name (tenant_id, name)') !== false);
$assert('events_json is JSON NOT NULL',           strpos($mig, 'events_json         JSON NOT NULL') !== false);
$assert('utf8mb4_unicode_ci collation',           strpos($mig, 'utf8mb4_unicode_ci') !== false);

echo "\nEndpoint — api/treasury_scenario_presets.php\n";
$apiPath = "{$ROOT}/api/treasury_scenario_presets.php";
$assert('endpoint exists',                        is_readable($apiPath));
$assert('parses',                                 $lint($apiPath));
$api = (string) file_get_contents($apiPath);
$assert('declares strict_types',                  strpos($api, 'declare(strict_types=1)') !== false);

echo "\nRBAC + verbs\n";
$assert("GET requires treasury.payment.view",
    preg_match('/method === \'GET\'.*?treasury\.payment\.view/s', $api) === 1);
$assert("POST requires treasury.payment.manage",
    preg_match('/method === \'POST\'.*?treasury\.payment\.manage/s', $api) === 1);
$assert("DELETE requires treasury.payment.manage",
    preg_match('/method === \'DELETE\'.*?treasury\.payment\.manage/s', $api) === 1);
$assert('405 fallthrough for unknown verbs',
    strpos($api, "api_error('Method not allowed', 405)") !== false);

echo "\nValidation — POST shape\n";
$assert('name required (422)',                    strpos($api, "api_error('name required', 422)") !== false);
$assert('name length cap 120',                    strpos($api, "api_error('name max 120 chars', 422)") !== false);
$assert('description length cap 500',             strpos($api, "api_error('description max 500 chars', 422)") !== false);
$assert('events must be an array',                strpos($api, "api_error('events must be an array', 422)") !== false);
$assert('at least one event required',            strpos($api, "api_error('at least one event required', 422)") !== false);
$assert('event count cap (50)',                   strpos($api, 'count($rawEv) > 50') !== false);
$assert('kind whitelist (inflow|outflow)',        strpos($api, "in_array(\$kind, ['inflow', 'outflow'], true)") !== false);
$assert('amount > 0',                             strpos($api, '$amount <= 0') !== false);
$assert('date YYYY-MM-DD format guard',
    strpos($api, "preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$date)") !== false);
$assert('does NOT clamp dates (presets are re-runnable)',
    strpos($api, '// scenario endpoint. Dates are NOT clamped here') !== false);

echo "\nUpsert + edit-in-place behavior\n";
$assert('insert uses ON DUPLICATE KEY UPDATE',
    strpos($api, 'ON DUPLICATE KEY UPDATE') !== false);
$assert('edit-in-place when id provided',         strpos($api, 'UPDATE treasury_scenario_presets') !== false);
$assert('404 when edit targets missing row',      strpos($api, "api_error('Preset not found', 404)") !== false);

echo "\nDELETE\n";
$assert('id required (422) on DELETE',            strpos($api, "api_error('id required', 422)") !== false);
$assert('DELETE 404 when row missing',            substr_count($api, "'Preset not found'") >= 2);
$assert('DELETE scopes by tenant_id',
    preg_match('/DELETE FROM treasury_scenario_presets WHERE id = :id AND tenant_id = :t/s', $api) === 1);

echo "\nKebab alias — /modules/treasury/api/scenario_presets.php\n";
$alias = "{$ROOT}/modules/treasury/api/scenario_presets.php";
$assert('alias file exists',                      is_readable($alias));
$assert('alias delegates to platform endpoint',
    strpos((string) file_get_contents($alias), '/api/treasury_scenario_presets.php') !== false);

echo "\nUI — TreasuryScenario.jsx Save Scenario integration\n";
$pg = (string) file_get_contents("{$ROOT}/dashboard/src/pages/TreasuryScenario.jsx");
$assert('imports useApi (saved-list query)',       strpos($pg, "import { api, useApi } from '../lib/api'") !== false);
$assert('imports Save + Bookmark icons',           strpos($pg, 'Save, Bookmark') !== false);
$assert('saved-presets bar testid',                strpos($pg, 'data-testid="scenario-saved-presets-bar"') !== false);
$assert('save-open button testid',                 strpos($pg, 'data-testid="scenario-save-open"') !== false);
$assert('save-form testid',                        strpos($pg, 'data-testid="scenario-save-form"') !== false);
$assert('save-name input testid',                  strpos($pg, 'data-testid="scenario-save-name"') !== false);
$assert('save-description input testid',           strpos($pg, 'data-testid="scenario-save-description"') !== false);
$assert('save-submit button testid',               strpos($pg, 'data-testid="scenario-save-submit"') !== false);
$assert('save-status testid',                      strpos($pg, 'data-testid="scenario-save-status"') !== false);
$assert('saved-empty placeholder testid',          strpos($pg, 'data-testid="scenario-saved-empty"') !== false);
$assert('saved-list testid',                       strpos($pg, 'data-testid="scenario-saved-list"') !== false);
$assert('per-saved card testid template',          strpos($pg, 'data-testid={`scenario-saved-${p.id}`}') !== false);
$assert('per-saved apply testid template',         strpos($pg, 'data-testid={`scenario-saved-apply-${p.id}`}') !== false);
$assert('per-saved delete testid template',        strpos($pg, 'data-testid={`scenario-saved-delete-${p.id}`}') !== false);

echo "\nBehaviour — save / apply / delete handlers\n";
$assert('saveAsPreset POSTs the current event stack',
    strpos($pg, "api.post('/api/v1/treasury/scenario-presets', {") !== false
    && strpos($pg, 'events,') !== false);
$assert('saveAsPreset reloads the saved-list',     strpos($pg, 'savedQuery.reload?.()') !== false);
$assert('save-open disabled when events empty',
    strpos($pg, 'disabled={events.length === 0}') !== false);
$assert('applySavedPreset replaces the stack (not additive)',
    strpos($pg, 'setEvents(preset.events);') !== false
    && strpos($pg, 'run(preset.events, days);') !== false);
$assert('deleteSavedPreset confirms before deleting',
    strpos($pg, 'window.confirm(') !== false);
$assert('deleteSavedPreset DELETEs by id',
    strpos($pg, 'api.delete(`/api/v1/treasury/scenario-presets?id=${preset.id}`)') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
