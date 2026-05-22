<?php
/**
 * Suggest Mappings — POST endpoint that proposes field-map rows from a
 * sample payload. The operator clicks "Suggest mappings" on the
 * LinkedExternalSystemsPanel's payload viewer; the UI POSTs the actual
 * payload here; we return a list of proposed (external_field →
 * internal_field) pairs with a confidence score and a human-readable
 * reason. The UI presents them with checkboxes + a one-click "Apply
 * selected" that walks the upsert endpoint.
 *
 * Heuristic order (best match wins):
 *   1. Known integration-specific aliases (curated below) — confidence 1.0
 *   2. Case/separator-insensitive exact match between key tail and an
 *      internal-field name — confidence 0.9
 *   3. Substring containment (e.g. `jobTitle` ⊃ `title`) — confidence 0.7
 *
 * Only enabled internal fields (allow-list) are eligible targets. Each
 * internal field gets at MOST one suggestion (the highest-confidence
 * candidate). Already-configured internal fields are NOT re-suggested
 * (the operator already made a choice).
 *
 * RBAC: integrations.field_map.manage (same as the rest of field_map
 * surface).
 *
 * Request body:
 *   {
 *     integration: 'jobdiva',
 *     entity_type: 'placement',
 *     payload:     { ... raw payload from external_entity_mappings ... }
 *   }
 *
 * Response:
 *   {
 *     suggestions: [
 *       { external_field, internal_field, transform, confidence, reason },
 *       ...
 *     ],
 *     already_configured: [internal_field, ...]   // surfaced so the UI
 *                                                 // can grey them out
 *   }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/integrations/field_map.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'integrations.field_map.manage');

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body        = api_json_body();
$integration = trim((string) ($body['integration'] ?? ''));
$entityType  = trim((string) ($body['entity_type'] ?? ''));
$payload     = is_array($body['payload'] ?? null) ? $body['payload'] : [];

if ($integration === '') api_error('integration required', 422);
if ($entityType  === '') api_error('entity_type required', 422);
if ($payload === [])     api_error('payload required (empty payloads have nothing to suggest from)', 422);

$allowedInternal = tenantIntegrationFieldMapAllowedInternalFields($entityType);
if ($allowedInternal === []) {
    api_error("entity_type '{$entityType}' has no admin-mappable fields", 422);
}

/**
 * Curated alias map. Each row maps a (case/separator-insensitive) external
 * field key — or dotted path — to a CoreFlux internal field. These are
 * the highest-confidence matches. Add freely as we discover real-world
 * payload shapes; the suggest endpoint is the canonical place where
 * "what does X external field correspond to" lives.
 */
$ALIASES = [
    'jobdiva' => [
        'placement' => [
            // Title / job role
            'jobTitle'        => ['title', 'none'],
            'positionTitle'   => ['title', 'none'],
            'job.title'       => ['title', 'none'],
            'job.jobTitle'    => ['title', 'none'],
            // Dates
            'startDate'       => ['start_date', 'date_normalise'],
            'endDate'         => ['end_date',   'date_normalise'],
            // End client
            'endClientName'   => ['end_client_name', 'none'],
            'clientName'      => ['end_client_name', 'none'],
            'companyName'     => ['end_client_name', 'none'],
            // Status
            'status'          => ['status', 'lowercase'],
            'startStatus'     => ['status', 'lowercase'],
            'placementStatus' => ['status', 'lowercase'],
        ],
        'person' => [
            'candidateFirstName' => ['first_name', 'none'],
            'firstName'          => ['first_name', 'none'],
            'candidateLastName'  => ['last_name',  'none'],
            'lastName'           => ['last_name',  'none'],
            'candidateEmail'     => ['email_primary', 'lowercase'],
            'email'              => ['email_primary', 'lowercase'],
            'candidatePhone'     => ['phone_primary', 'none'],
            'phone'              => ['phone_primary', 'none'],
            'phone 1'            => ['phone_primary', 'none'],
            'linkedinUrl'        => ['linkedin_url', 'none'],
        ],
        'company' => [
            'companyName' => ['name', 'none'],
            'name'        => ['name', 'none'],
            'website'     => ['website', 'none'],
            'phone'       => ['phone',   'none'],
        ],
        'contact' => [
            'firstName' => ['name',  'none'],
            'name'      => ['name',  'none'],
            'email'     => ['email', 'lowercase'],
            'phone'     => ['phone', 'none'],
            'title'     => ['title', 'none'],
        ],
    ],
];

// Flatten payload to dotted-path → scalar for both alias matching AND
// substring-containment fallback. We only descend ONE level of nesting
// (e.g. `job.title` works, `job.meta.owner` doesn't). Two levels is
// enough for every JobDiva V2 response we've seen; deeper structures
// can be configured manually.
$flat = [];
foreach ($payload as $k => $v) {
    if (!is_string($k)) continue;
    if (is_scalar($v) || $v === null) {
        $flat[$k] = $v;
    } elseif (is_array($v)) {
        foreach ($v as $k2 => $v2) {
            if (is_string($k2) && (is_scalar($v2) || $v2 === null)) {
                $flat["{$k}.{$k2}"] = $v2;
            }
        }
    }
}

$normalise = static fn(string $s): string =>
    strtolower((string) preg_replace('/[^a-z0-9]/i', '', $s));

// Already-configured internal fields — surface to the UI so it can
// show "Already configured" and let the operator decide whether to
// overwrite.
$existing = tenantIntegrationFieldMapList($tid, $integration, $entityType);
$alreadyConfigured = [];
foreach ($existing as $row) $alreadyConfigured[$row['internal_field']] = true;

$aliasMap   = $ALIASES[$integration][$entityType] ?? [];
$candidates = [];  // [internal_field => {external_field, transform, confidence, reason}]

foreach ($flat as $extKey => $extVal) {
    $extNorm = $normalise($extKey);

    // 1. Alias hit (highest confidence).
    foreach ($aliasMap as $aliasKey => [$internalField, $transform]) {
        if ($normalise($aliasKey) === $extNorm && in_array($internalField, $allowedInternal, true)) {
            $score = 1.0;
            if (!isset($candidates[$internalField]) || $candidates[$internalField]['confidence'] < $score) {
                $candidates[$internalField] = [
                    'external_field' => $extKey,
                    'internal_field' => $internalField,
                    'transform'      => $transform,
                    'confidence'     => $score,
                    'reason'         => "Known JobDiva alias: '{$aliasKey}' → {$internalField}",
                    'sample_value'   => is_scalar($extVal) ? substr((string) $extVal, 0, 60) : null,
                ];
            }
            continue 2;  // first alias hit wins; move on to next external key
        }
    }

    // 2. Exact (case/separator-insensitive) match against an allowed
    //    internal field name.
    foreach ($allowedInternal as $internalField) {
        if ($normalise($internalField) === $extNorm) {
            $score = 0.9;
            if (!isset($candidates[$internalField]) || $candidates[$internalField]['confidence'] < $score) {
                $candidates[$internalField] = [
                    'external_field' => $extKey,
                    'internal_field' => $internalField,
                    'transform'      => 'none',
                    'confidence'     => $score,
                    'reason'         => "Field name matches '{$internalField}' (case/separator-insensitive)",
                    'sample_value'   => is_scalar($extVal) ? substr((string) $extVal, 0, 60) : null,
                ];
            }
            continue 2;
        }
    }

    // 3. Substring containment (e.g. `jobTitle` ⊃ `title`).
    foreach ($allowedInternal as $internalField) {
        $intNorm = $normalise($internalField);
        if ($intNorm === '' || strlen($intNorm) < 4) continue;  // avoid 'id' etc. matching everything
        if (str_contains($extNorm, $intNorm)) {
            $score = 0.7;
            if (!isset($candidates[$internalField]) || $candidates[$internalField]['confidence'] < $score) {
                $candidates[$internalField] = [
                    'external_field' => $extKey,
                    'internal_field' => $internalField,
                    'transform'      => 'none',
                    'confidence'     => $score,
                    'reason'         => "External key '{$extKey}' contains '{$internalField}'",
                    'sample_value'   => is_scalar($extVal) ? substr((string) $extVal, 0, 60) : null,
                ];
            }
        }
    }
}

// Drop suggestions for already-configured internal fields (the operator
// already picked a mapping; respect it). Surface those separately so
// the UI can render them as "already configured" muted rows if needed.
$suggestions = [];
$shadowed    = [];
foreach ($candidates as $internalField => $s) {
    if (isset($alreadyConfigured[$internalField])) {
        $shadowed[] = $s;
    } else {
        $suggestions[] = $s;
    }
}

// Stable ordering: by confidence desc, then internal_field asc.
usort($suggestions, static function ($a, $b) {
    if ($a['confidence'] !== $b['confidence']) return $b['confidence'] <=> $a['confidence'];
    return strcmp($a['internal_field'], $b['internal_field']);
});

api_ok([
    'suggestions'        => $suggestions,
    'shadowed'           => $shadowed,
    'already_configured' => array_keys($alreadyConfigured),
    'allowed_internal_fields' => $allowedInternal,
    'scanned_keys'       => count($flat),
]);
