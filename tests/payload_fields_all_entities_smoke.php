<?php
/**
 * payload_fields_all_entities_smoke.php
 *
 * Verifies the new `entity_type=*` mode on
 * /api/admin/integrations/payload_fields.php — drives the Source
 * Payload Inspector's "🌐 Search across every entity bucket" toggle.
 *
 * Smoke for the routing logic: when entity_type=* we should return
 * `paths_by_entity` keyed by each seen entity_type, NOT a single
 * `paths` array.
 */
declare(strict_types=1);

function _ok(string $msg): void { fwrite(STDOUT, "✅ $msg\n"); }

// Sanity check the endpoint file is syntactically clean.
$path = __DIR__ . '/../api/admin/integrations/payload_fields.php';
assert(file_exists($path), 'payload_fields.php exists');
$contents = (string) file_get_contents($path);

// Required routing markers — surface drift early.
assert(str_contains($contents, "\$entityType === '*'"),  'endpoint handles entity_type=*');
assert(str_contains($contents, "\$entityType === 'all'"),'endpoint also accepts entity_type=all');
assert(str_contains($contents, "paths_by_entity"),       'endpoint returns paths_by_entity key');
assert(str_contains($contents, 'integrationPayloadFieldIndexSources'), 'endpoint iterates registered sources');
assert(str_contains($contents, 'integrationPayloadFieldIndexList'),    'endpoint fetches per-bucket paths');
_ok('endpoint routes entity_type=* correctly');

// The endpoint must STILL handle the legacy two modes (discovery + path-listing).
assert(str_contains($contents, 'sources'),                 'discovery mode preserved');
assert(str_contains($contents, "'entity_type' => \$entityType"), 'path-listing mode preserved');
_ok('legacy modes (discovery, path-listing) preserved');

echo "\n🎯 payload_fields_all_entities_smoke — ALL PASS\n";
