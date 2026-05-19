<?php
/**
 * Sprint 8a / Slice A4 smoke — Per-entity sync config picker.
 *
 * Asserts:
 *   - core/jobdiva/client.php exposes JOBDIVA_SYNC_ENTITIES (4 entities, time
 *     included), JOBDIVA_SYNC_SOURCES, JOBDIVA_SYNC_DIRECTIONS.
 *   - jobdivaSyncConfigRead() merges defaults so missing keys still render.
 *   - jobdivaSyncConfigWrite() rejects unknown sources/directions and the two
 *     incoherent combos (coreflux+pull, jobdiva+push); writes audit row.
 *   - api/jobdiva.php exposes sync_config_get + sync_config_set actions.
 *   - status response includes sync_config so the UI can render in one fetch.
 *   - jobdivaSyncAll honors config — entities with direction=off are skipped
 *     by config, NOT touched by HTTP.
 *   - JobDivaSettings.jsx renders the picker table with all 4 entities + per-
 *     entity testids + onConfigChange handler with client-side coherence guards.
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

echo "Constants — core/jobdiva/client.php\n";
$cli = (string) file_get_contents("{$ROOT}/core/jobdiva/client.php");
$assert('parses',                                 $lint("{$ROOT}/core/jobdiva/client.php"));
$assert('JOBDIVA_SYNC_ENTITIES has 4 entities incl. time',
    strpos($cli, "JOBDIVA_SYNC_ENTITIES   = ['company', 'contact', 'placement', 'time']") !== false);
$assert('JOBDIVA_SYNC_SOURCES jobdiva+coreflux',
    strpos($cli, "JOBDIVA_SYNC_SOURCES    = ['jobdiva', 'coreflux']") !== false);
$assert('JOBDIVA_SYNC_DIRECTIONS 4 values',
    strpos($cli, "JOBDIVA_SYNC_DIRECTIONS = ['pull', 'push', 'two_way', 'off']") !== false);
$assert("default for time is source=coreflux + direction=off",
    strpos($cli, "'time'      => ['source' => 'coreflux', 'direction' => 'off']") !== false);
$assert('default for company is jobdiva+pull',
    strpos($cli, "'company'   => ['source' => 'jobdiva',  'direction' => 'pull']") !== false);

echo "\nReader — jobdivaSyncConfigRead\n";
$assert('exists',                                 strpos($cli, 'function jobdivaSyncConfigRead(') !== false);
$assert('merges stored config over defaults',
    strpos($cli, '$merged[$ent] = $stored[$ent] ?? JOBDIVA_SYNC_DEFAULTS[$ent]') !== false);
$assert('decodes sync_config JSON column',        strpos($cli, "json_decode((string) \$row['sync_config']") !== false);

echo "\nWriter — jobdivaSyncConfigWrite\n";
$assert('exists',                                 strpos($cli, 'function jobdivaSyncConfigWrite(') !== false);
$assert('whitelists source',                      strpos($cli, "in_array(\$source, JOBDIVA_SYNC_SOURCES, true)") !== false);
$assert('whitelists direction',                   strpos($cli, "in_array(\$direction, JOBDIVA_SYNC_DIRECTIONS, true)") !== false);
$assert("rejects coreflux+pull combo",
    strpos($cli, "source=coreflux cannot have direction=pull") !== false);
$assert("rejects jobdiva+push combo",
    strpos($cli, "source=jobdiva cannot have direction=push") !== false);
$assert('updates connection.sync_config',         strpos($cli, 'UPDATE jobdiva_connections SET sync_config = :c') !== false);
$assert('emits sync_config_update audit row',     strpos($cli, "jobdivaAudit(\$tenantId, 'sync_config_update'") !== false);

echo "\nAPI — api/jobdiva.php\n";
$api = (string) file_get_contents("{$ROOT}/api/jobdiva.php");
$assert("status response includes sync_config",
    strpos($api, "'sync_config'       => jobdivaSyncConfigRead(\$tid)") !== false);
$assert("sync_config_get action GET-only",
    strpos($api, "case 'sync_config_get'") !== false
    && strpos($api, "if (\$method !== 'GET') api_error('Method not allowed', 405)") !== false);
$assert('sync_config_get returns entities/sources/directions for picker',
    strpos($api, "'entities'    => JOBDIVA_SYNC_ENTITIES") !== false
    && strpos($api, "'sources'     => JOBDIVA_SYNC_SOURCES") !== false
    && strpos($api, "'directions'  => JOBDIVA_SYNC_DIRECTIONS") !== false);
$assert("sync_config_set action POST-only",
    strpos($api, "case 'sync_config_set'") !== false);
$assert('sync_config_set requires manage perm',
    strpos($api, "rbac_legacy_require(\$user, 'integrations.jobdiva.manage');\n        \$body = api_json_body();\n        \$config = \$body['sync_config']") !== false);
$assert('sync_config_set 422 on invalid payload',
    strpos($api, 'is_array($config))') !== false
    && strpos($api, "api_error('sync_config object required', 422)") !== false);
$assert('sync_config_set 422 on InvalidArgumentException',
    strpos($api, 'catch (\InvalidArgumentException $e) {') !== false);

echo "\nSync orchestration honors config\n";
$src = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");
$assert('jobdivaSyncAll reads config first',      strpos($src, '$config = jobdivaSyncConfigRead($tid)') !== false);
$assert('shouldPull helper requires source=jobdiva + direction in pull/two_way',
    strpos($src, "(\$row['source'] ?? null) === 'jobdiva'") !== false
    && strpos($src, "in_array(\$row['direction'] ?? 'off', ['pull', 'two_way'], true)") !== false);
$assert("config-skipped entities marked skipped_by_config",
    strpos($src, "'skipped_by_config' => true") !== false);
$assert('jobdivaSyncAll returns skipped_by_config envelope',
    strpos($src, "'skipped_by_config'  => \$skipped") !== false);

echo "\nUI — JobDivaSettings.jsx picker\n";
$jsx = (string) file_get_contents("{$ROOT}/dashboard/src/pages/JobDivaSettings.jsx");
$assert('config card testid',                     strpos($jsx, 'data-testid="jobdiva-settings-sync-config-card"') !== false);
$assert('config table testid',                    strpos($jsx, 'data-testid="jobdiva-settings-sync-config-table"') !== false);
$assert('renders all 4 entity rows',
    strpos($jsx, "['company','contact','placement','time'].map(entity => {") !== false);
$assert('per-entity row testid template',
    strpos($jsx, 'data-testid={`jobdiva-settings-sync-config-row-${entity}`}') !== false);
$assert('per-entity source select testid template',
    strpos($jsx, 'data-testid={`jobdiva-settings-sync-config-source-${entity}`}') !== false);
$assert('per-entity direction select testid template',
    strpos($jsx, 'data-testid={`jobdiva-settings-sync-config-direction-${entity}`}') !== false);
$assert('source dropdown lists JobDiva + CoreFlux',
    strpos($jsx, '<option value="jobdiva">JobDiva</option>') !== false
    && strpos($jsx, '<option value="coreflux">CoreFlux</option>') !== false);
$assert('direction dropdown gates pull/push by source coherence',
    strpos($jsx, "cfg.source === 'jobdiva' && <option value=\"pull\">") !== false
    && strpos($jsx, "cfg.source === 'coreflux' && <option value=\"push\">") !== false);
$assert("includes off + two_way options",
    strpos($jsx, '<option value="off">Off</option>') !== false
    && strpos($jsx, '<option value="two_way">Two-way</option>') !== false);
$assert('onConfigChange POSTs sync_config_set',
    strpos($jsx, "api.post('/api/jobdiva.php?action=sync_config_set'") !== false);
$assert('onConfigChange has client-side coherence guards (coreflux+pull → push)',
    strpos($jsx, "next[entity].source === 'coreflux' && next[entity].direction === 'pull'") !== false
    && strpos($jsx, "next[entity].direction = 'push'") !== false);
$assert('onConfigChange has client-side coherence guards (jobdiva+push → pull)',
    strpos($jsx, "next[entity].source === 'jobdiva' && next[entity].direction === 'push'") !== false
    && strpos($jsx, "next[entity].direction = 'pull'") !== false);
$assert('config card only renders when connected + sync_config present',
    strpos($jsx, 'data?.connected && data?.sync_config &&') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
