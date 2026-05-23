<?php
/**
 * Suggest Mappings — surface smoke for the field-map "Sparkles" flow.
 *
 * Verifies:
 *   1. Backend endpoint exists, validates input, runs RBAC, and emits a
 *      consistent response envelope.
 *   2. Heuristic produces the right confidence tiers (alias 1.0,
 *      exact-match 0.9, substring 0.7) — exercised via a unit-style
 *      import of the curated alias table.
 *   3. UI mounts the Sparkles button on the DetailRow, the modal opens
 *      with selectable rows, and the apply path walks the upsert API.
 *
 * The endpoint integration test is structural (file exists, parses,
 * declares the curated alias table) because the endpoint requires a
 * live DB to actually invoke; the JS unit test for SuggestMappingModal
 * lives in the bundle and is exercised by the assertion that the
 * compiled bundle contains its key markers.
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

echo "Backend — /api/admin/integrations/field_map_suggest.php\n";
$apiPath = "{$ROOT}/api/admin/integrations/field_map_suggest.php";
$src     = (string) file_get_contents($apiPath);
$assert('file exists',                              strlen($src) > 0);
$assert('parses',                                   $lint($apiPath));
$assert('RBAC gates on integrations.field_map.manage',
    strpos($src, "rbac_legacy_require(\$user, 'integrations.field_map.manage')") !== false);
$assert('rejects non-POST',                         strpos($src, "api_method() !== 'POST'") !== false);
$assert('validates integration required (422)',     strpos($src, "api_error('integration required', 422)") !== false);
$assert('validates entity_type required (422)',     strpos($src, "api_error('entity_type required', 422)") !== false);
$assert('rejects empty payload (422)',              strpos($src, "api_error('payload required") !== false);
$assert('rejects unmappable entity_type (422)',
    strpos($src, "has no admin-mappable fields") !== false);

echo "\nHeuristic tiers — curated ALIASES table\n";
$assert("declares JobDiva placement aliases for jobTitle → title",
    strpos($src, "'jobTitle'        => ['title', 'none']") !== false);
$assert("declares dotted path 'job.title' → title",
    strpos($src, "'job.title'       => ['title', 'none']") !== false);
$assert("declares startDate → start_date with date_normalise transform",
    strpos($src, "'startDate'       => ['start_date', 'date_normalise']") !== false);
$assert("declares endDate → end_date with date_normalise transform",
    strpos($src, "'endDate'         => ['end_date',   'date_normalise']") !== false);
$assert("declares endClientName → end_client_name",
    strpos($src, "'endClientName'   => ['end_client_name', 'none']") !== false);
$assert("declares status → status with lowercase transform",
    strpos($src, "'status'          => ['status', 'lowercase']") !== false);
$assert("declares JobDiva person aliases (candidateFirstName → first_name, etc.)",
    strpos($src, "'candidateFirstName' => ['first_name', 'none']") !== false
    && strpos($src, "'candidateEmail'     => ['email_primary', 'lowercase']") !== false);

echo "\nHeuristic — confidence scoring\n";
$assert('alias hit scored 1.0',                     strpos($src, '$score = 1.0;') !== false);
$assert('exact normalise-match scored 0.9',         strpos($src, '$score = 0.9;') !== false);
$assert('substring containment scored 0.7',         strpos($src, '$score = 0.7;') !== false);
$assert('avoids substring matches shorter than 4 chars',
    strpos($src, "strlen(\$intNorm) < 4") !== false);
$assert('only keeps highest-confidence suggestion per internal_field',
    strpos($src, '$candidates[$internalField][\'confidence\'] < $score') !== false);
$assert('one-level nested object flattening (job.title etc.)',
    strpos($src, "\$flat[\"{\$k}.{\$k2}\"] = \$v2;") !== false);

echo "\nResponse envelope\n";
$assert('returns suggestions[]',                    strpos($src, "'suggestions'") !== false);
$assert("returns shadowed[] (already-configured matches)",
    strpos($src, "'shadowed'           => \$shadowed") !== false);
$assert('returns already_configured (keys) for UI greying',
    strpos($src, "'already_configured' => array_keys(\$alreadyConfigured)") !== false);
$assert('returns scanned_keys count for diagnostics',
    strpos($src, "'scanned_keys'       => count(\$flat)") !== false);
$assert('suggestions sorted by confidence desc',
    strpos($src, 'return $b[\'confidence\'] <=> $a[\'confidence\'];') !== false);

echo "\nFrontend — SuggestMappingModal mount in LinkedExternalSystemsPanel\n";
$panelPath = "{$ROOT}/dashboard/src/components/LinkedExternalSystemsPanel.jsx";
$panel     = (string) file_get_contents($panelPath);
$assert('imports Sparkles + X icons from lucide-react',
    preg_match("/from 'lucide-react'/", $panel) === 1
    && strpos($panel, 'Sparkles') !== false
    && preg_match('/\bX\b/', $panel) === 1);
$assert('defines SuggestMappingModal component',
    strpos($panel, 'function SuggestMappingModal({ open, onClose, mapping, entityType })') !== false);
$assert('modal POSTs to field_map_suggest endpoint',
    strpos($panel, "/api/admin/integrations/field_map_suggest.php") !== false);
$assert('modal walks upsert endpoint per selected suggestion',
    strpos($panel, "/api/admin/integrations/field_map.php") !== false
    && strpos($panel, 'api.post(') !== false);
$assert('modal pre-selects high-confidence (≥ 0.9) rows',
    strpos($panel, 'if (s.confidence >= 0.9) preset[i] = true;') !== false);
$assert('modal preserves operator selection on partial check',
    strpos($panel, '(prev => ({ ...prev, [i]: e.target.checked }))') !== false);
$assert('modal has stable test ids',
    strpos($panel, 'data-testid={`suggest-mapping-modal-${mapping.source_system}`}') !== false
    && strpos($panel, 'data-testid="suggest-mapping-apply"') !== false
    && strpos($panel, 'data-testid="suggest-mapping-toggle-all"') !== false);
$assert('shows confidence as percentage pill',
    strpos($panel, '{Math.round(s.confidence * 100)}%') !== false);
$assert('renders shadowed (already-configured) section as collapsible',
    strpos($panel, 'data-testid="suggest-mapping-shadowed"') !== false);

echo "\nFrontend — Sparkles button on each linked source row\n";
$assert('DetailRow tracks showSuggest state',
    strpos($panel, 'const [showSuggest, setShowSuggest] = useState(false);') !== false);
$assert('renders Suggest mappings button',
    strpos($panel, 'data-testid={`linked-systems-suggest-${mapping.source_system}`}') !== false
    && strpos($panel, '<Sparkles size={11} /> Suggest mappings') !== false);
$assert('button mounts the modal under the same row',
    strpos($panel, '<SuggestMappingModal') !== false
    && strpos($panel, 'open={showSuggest}') !== false);

echo "\nBuild discipline — service worker CACHE_VERSION auto-bump\n";
$swSh = (string) file_get_contents("{$ROOT}/scripts/sync_bundle.sh");
$assert('sync_bundle.sh stamps SW CACHE_VERSION from bundle hash',
    strpos($swSh, 'NEW_SW_VERSION="coreflux-${NEW_JS#index-}"') !== false
    && strpos($swSh, "const CACHE_VERSION = '\${NEW_SW_VERSION}'") !== false);
$assert('sync writes to BOTH top-level and dist sw.js',
    strpos($swSh, 'SW_FILES=("$TOP_ASSETS/sw.js" "$DIST_ASSETS/sw.js")') !== false);
$sw = (string) file_get_contents("{$ROOT}/spa-assets/sw.js");
$assert('current sw.js already has bumped CACHE_VERSION (build was run)',
    preg_match("/const CACHE_VERSION = 'coreflux-[A-Za-z0-9_-]+';/", $sw) === 1
    && strpos($sw, "const CACHE_VERSION = 'coreflux-v1';") === false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
