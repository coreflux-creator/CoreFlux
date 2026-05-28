<?php
/**
 * /app/core/integrations/mapping_suggester.php
 *
 * Rule-based "auto-map" engine that scans indexed payload paths for a
 * (tenant, integration, entity_type) and proposes the most likely
 * CoreFlux target columns. Output is a ranked list of suggested
 * `(source_path → target_module.target_table.target_column [+ linked_entity, transform])`
 * mappings, each carrying a confidence score + human-readable reason
 * so the operator can review-then-apply in one click.
 *
 * Strategy:
 *   1. Normalise both ends — lowercase + strip non-alphanumeric.
 *   2. Look up the path's normalised tail (last dot-segment) in a
 *      synonym dictionary that maps common JobDiva/QBO/Airtable-style
 *      keys to canonical CoreFlux column names.
 *   3. Find writable targets in the entity's preferred module first
 *      (people for `person`, companies for `jobdiva_customer`, etc.);
 *      fall back to global search if no preferred-module hit.
 *   4. Skip paths already mapped to keep suggestions actionable.
 *
 * Determinism + zero network calls = no LLM dependency, no cost,
 * fast enough to run on every Studio open.
 */
declare(strict_types=1);

require_once __DIR__ . '/payload_field_index.php';
require_once __DIR__ . '/field_map_apply.php';
require_once __DIR__ . '/field_map.php';

/**
 * Default linked_entity + preferred CoreFlux module per integration
 * entity_type. Used to scope writable-target search.
 *
 * @return array{module:?string, linked_entity:string}
 */
function mappingSuggesterEntityDefaults(string $entityType): array
{
    static $DEFAULTS = [
        'person'           => ['module' => 'people',     'linked_entity' => 'person'],
        'job'              => ['module' => 'placements', 'linked_entity' => 'self'],
        'jobdiva_customer' => ['module' => 'companies',  'linked_entity' => 'end_client_company'],
        'contact'          => ['module' => 'companies',  'linked_entity' => 'self'],
        'assignment'       => ['module' => 'placements', 'linked_entity' => 'self'],
        'placement'        => ['module' => 'placements', 'linked_entity' => 'self'],
        'company'          => ['module' => 'companies',  'linked_entity' => 'self'],
        'time_entry'       => ['module' => 'time',       'linked_entity' => 'self'],
        // QBO/Zoho entity types — preferred modules for completeness.
        'customer'         => ['module' => 'billing',    'linked_entity' => 'self'],
        'vendor'           => ['module' => 'ap',         'linked_entity' => 'self'],
        'invoice'          => ['module' => 'billing',    'linked_entity' => 'self'],
        'bill'             => ['module' => 'ap',         'linked_entity' => 'self'],
        'payment'          => ['module' => 'billing',    'linked_entity' => 'self'],
        'journal_entry'    => ['module' => 'accounting', 'linked_entity' => 'self'],
        'gl_account'       => ['module' => 'accounting', 'linked_entity' => 'self'],
    ];
    return $DEFAULTS[$entityType] ?? ['module' => null, 'linked_entity' => 'self'];
}

/**
 * Synonym dictionary: normalised source-name → ranked list of
 * canonical CoreFlux column names that mean the same thing.
 *
 * Order matters — the first column we find a writable target for
 * wins. Keep aliases tight; over-broad synonyms produce false-positive
 * suggestions that erode operator trust.
 */
function mappingSuggesterSynonymMap(): array
{
    return [
        // ---------- Person / identity ----------
        'firstname'       => ['first_name'],
        'givenname'       => ['first_name'],
        'lastname'        => ['last_name'],
        'surname'         => ['last_name'],
        'familyname'      => ['last_name'],
        'middlename'      => ['middle_name'],
        'preferredname'   => ['preferred_name'],
        'nickname'        => ['preferred_name'],
        'displayname'     => ['preferred_name', 'name'],
        'fullname'        => ['name'],
        // ---------- Contact channels ----------
        'email'           => ['email_primary'],
        'emailaddress'    => ['email_primary'],
        'workemail'       => ['email_primary'],
        'primaryemail'    => ['email_primary'],
        'personalemail'   => ['email_secondary'],
        'secondaryemail'  => ['email_secondary'],
        'phone'           => ['phone_primary'],
        'phonenumber'     => ['phone_primary'],
        'mobilephone'     => ['phone_primary'],
        'workphone'       => ['phone_primary'],
        'cellphone'       => ['phone_primary'],
        'primaryphone'    => ['phone_primary'],
        'homephone'       => ['phone_secondary'],
        'secondaryphone'  => ['phone_secondary'],
        'fax'             => ['phone_secondary'],
        // ---------- Address ----------
        'address'         => ['address_line1', 'home_address_line1'],
        'address1'        => ['address_line1', 'home_address_line1'],
        'streetaddress'   => ['address_line1', 'home_address_line1'],
        'street'          => ['address_line1', 'home_address_line1'],
        'address2'        => ['address_line2', 'home_address_line2'],
        'addressline2'    => ['address_line2', 'home_address_line2'],
        'addressline1'    => ['address_line1', 'home_address_line1'],
        'city'            => ['city', 'home_city'],
        'town'            => ['city', 'home_city'],
        'state'           => ['state', 'home_state'],
        'province'        => ['state', 'home_state'],
        'region'          => ['state', 'home_state'],
        'zip'             => ['postal_code', 'home_postal_code'],
        'zipcode'         => ['postal_code', 'home_postal_code'],
        'postalcode'      => ['postal_code', 'home_postal_code'],
        'postal'          => ['postal_code', 'home_postal_code'],
        'country'         => ['country', 'home_country'],
        'countrycode'     => ['country', 'home_country'],
        // ---------- Job / placement ----------
        'title'           => ['title'],
        'jobtitle'        => ['title'],
        'positiontype'    => ['engagement_type'],
        'positiontitle'   => ['title'],
        'dept'            => ['notes'],
        'department'      => ['notes'],
        'jobid'           => ['jobdiva_job_id', 'external_id'],
        'jobrefno'        => ['jobdiva_job_id'],
        'optionalrefnumber' => ['jobdiva_job_id'],
        // ---------- Rates ----------
        'payrate'         => ['pay_rate'],
        'agreedpayrate'   => ['pay_rate'],
        'finalpayrate'    => ['pay_rate'],
        'billrate'        => ['bill_rate'],
        'finalbillrate'   => ['bill_rate'],
        'agreedbillrate'  => ['bill_rate'],
        'rate'            => ['pay_rate'],
        'ratecurrencyunit' => ['currency'],
        'payratecurrencyunit' => ['currency'],
        'billratecurrencyunit' => ['currency'],
        'currency'        => ['currency'],
        'currencycode'    => ['currency'],
        // ---------- Dates ----------
        'startdate'       => ['start_date'],
        'enddate'         => ['end_date'],
        'actualenddate'   => ['actual_end_date'],
        'duedate'         => ['due_date'],
        'hiredate'        => ['hire_date'],
        'terminationdate' => ['termination_date'],
        // ---------- Status / lifecycle ----------
        'status'          => ['status'],
        'startstatus'     => ['status'],
        'state'           => ['status'],  // for placements; collides w/ address — module filter resolves
        // ---------- Company ----------
        'companyname'     => ['name'],
        'customername'    => ['name'],
        'clientname'      => ['name'],
        'name'            => ['name'],
        'website'         => ['website'],
        'url'             => ['website'],
        'homepage'        => ['website'],
        'industry'        => ['industry'],
        // ---------- Cross-cutting ----------
        'id'              => ['external_id'],
        'externalid'      => ['external_id'],
        'recordid'        => ['external_id'],
        'sourceid'        => ['external_id'],
        'recruitername'   => ['recruiter_name'],
        'recruiteremail'  => ['recruiter_email'],
        'accountmanagername'  => ['account_manager_name'],
        'accountmanageremail' => ['account_manager_email'],
        'clientapprovername'  => ['client_approver_name'],
        'clientapproveremail' => ['client_approver_email'],
        'remoteworkpolicy' => ['remote_policy'],
        'remotepolicy'    => ['remote_policy'],
        'engagementtype'  => ['engagement_type'],
        'workerclass'     => ['worker_class'],
        'workerclassification' => ['worker_class'],
        'classification'  => ['classification'],
        'taxid'           => ['tax_id'],
        'ein'             => ['tax_id'],
        'ssn'             => ['tax_id'],
        'paymentterms'    => ['payment_terms'],
        'terms'           => ['payment_terms'],
    ];
}

/**
 * Suggested transform for a (source-name, target-column) pair. Returns
 * 'date_normalise' for *_date columns, 'lowercase' for status columns,
 * 'none' otherwise. Keeps the suggestions ready-to-apply with the
 * right sane default.
 */
function mappingSuggesterDefaultTransform(string $normSrc, string $targetColumn): string
{
    if (str_ends_with($targetColumn, '_date'))               return 'date_normalise';
    if ($targetColumn === 'status')                          return 'lowercase';
    if (str_contains($normSrc, 'currency') && $targetColumn === 'currency') return 'uppercase';
    return 'none';
}

/**
 * Normalise a key for matching:
 *   first_name → firstname
 *   firstName  → firstname
 *   FIRST_NAME → firstname
 *   address.city → city  (we use the TAIL segment after the last dot)
 */
function mappingSuggesterNormalise(string $raw): string
{
    // Take the last dot-segment so dotted paths like
    // `_jd_candidate.firstName` normalise to `firstname`.
    $tail = $raw;
    if (str_contains($raw, '.')) {
        $parts = explode('.', $raw);
        $tail = end($parts) ?: $raw;
    }
    // Strip [] array suffixes.
    $tail = preg_replace('/\[\]/', '', $tail) ?? $tail;
    // Lowercase + strip non-alphanumeric.
    $norm = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $tail));
    return $norm;
}

/**
 * Build a column → row index of writable targets for fast lookup.
 * Returns: [$targetColumn => [['module'=>, 'table'=>, 'column'=>, ...], ...]].
 */
function mappingSuggesterIndexTargets(array $targets): array
{
    $byColumn = [];
    foreach ($targets as $t) {
        $col = (string) ($t['target_column'] ?? '');
        if ($col === '' || $col === '*') continue;
        $byColumn[$col] = $byColumn[$col] ?? [];
        $byColumn[$col][] = $t;
    }
    return $byColumn;
}

/**
 * Score a single (source, target) pair. Higher = more confident.
 * - exact normalised match → 0.95
 * - synonym match           → 0.85
 * - case-insensitive subset → 0.55 (still emit but mark low)
 */
function mappingSuggesterScore(string $normSrc, string $targetColumn, bool $viaSynonym): float
{
    $normTgt = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $targetColumn));
    if ($normSrc === $normTgt) return 0.95;
    if ($viaSynonym)           return 0.85;
    if (strlen($normSrc) >= 4 && (str_contains($normTgt, $normSrc) || str_contains($normSrc, $normTgt))) return 0.55;
    return 0.0;
}

/**
 * Main entry: build a ranked suggestions list for (tenant, integration,
 * entity_type).
 *
 * @return array<int, array{
 *   source_path:string, sample_value:?string, value_type:string,
 *   target_module:string, target_table:string, target_column:string,
 *   linked_entity:string, transform:string, confidence:float, reason:string
 * }>
 */
function mappingSuggesterSuggest(int $tenantId, string $integration, string $entityType, int $limit = 50): array
{
    if ($tenantId <= 0 || $integration === '' || $entityType === '') return [];

    // 1. Pull every indexed path for this (tenant, integration, entity_type).
    $paths = integrationPayloadFieldIndexList($tenantId, $integration, $entityType, 1000);
    if (!$paths) return [];

    // 2. Pull writable targets. Scope to the entity's preferred module
    //    first; if that returns nothing we fall back to the global list
    //    so quirky tenants still get coverage.
    $defaults = mappingSuggesterEntityDefaults($entityType);
    $preferredModule = $defaults['module'];
    $linkedEntity    = $defaults['linked_entity'];
    $preferred = $preferredModule !== null
        ? integrationWritableTargetsList($preferredModule, null)
        : [];
    $global   = integrationWritableTargetsList(null, null);
    // Always allow placements module too (joined entity types often need
    // to write back onto the placement row itself, e.g. job → placements.title).
    if ($preferredModule !== null && $preferredModule !== 'placements') {
        $global = array_merge($preferred, integrationWritableTargetsList('placements', null), $global);
    } else {
        $global = array_merge($preferred, $global);
    }
    // Dedupe by (module, table, column).
    $seen = [];
    $targets = [];
    foreach ($global as $t) {
        $k = ($t['target_module'] ?? '') . '|' . ($t['target_table'] ?? '') . '|' . ($t['target_column'] ?? '');
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $targets[] = $t;
    }
    $targetsByCol = mappingSuggesterIndexTargets($targets);

    // 3. Pull existing mappings so we don't propose duplicates.
    $existing = integrationFieldMapResolveGeneralised($tenantId, $integration, $entityType);
    $existingSources = [];
    foreach ($existing as $m) {
        $sp = (string) ($m['source_path'] ?? $m['external_field'] ?? '');
        if ($sp !== '') $existingSources[$sp] = true;
    }

    $synonyms = mappingSuggesterSynonymMap();

    // 4. For each path, find the best target match.
    $suggestions = [];
    foreach ($paths as $p) {
        $srcPath = (string) ($p['source_path'] ?? '');
        if ($srcPath === '' || $srcPath === '$') continue;
        if (isset($existingSources[$srcPath])) continue;
        // Skip non-leaf nodes (intermediate object/array bones).
        $type = (string) ($p['value_type'] ?? '');
        if ($type === 'object' || $type === 'array') continue;

        $normSrc = mappingSuggesterNormalise($srcPath);
        if ($normSrc === '') continue;

        // Try direct match first.
        $best = null;
        $bestVia = null;
        // (a) Exact normalised column match.
        foreach (array_keys($targetsByCol) as $col) {
            $score = mappingSuggesterScore($normSrc, $col, false);
            if ($score >= 0.95 && ($best === null || $score > $best['_score'])) {
                $best = $targetsByCol[$col][0];
                $best['_score'] = $score;
                $bestVia = 'exact match';
            }
        }
        // (b) Synonym match.
        if (!$best && isset($synonyms[$normSrc])) {
            foreach ($synonyms[$normSrc] as $cand) {
                if (isset($targetsByCol[$cand])) {
                    $best = $targetsByCol[$cand][0];
                    $best['_score'] = mappingSuggesterScore($normSrc, $cand, true);
                    $bestVia = "synonym → {$cand}";
                    break;
                }
            }
        }
        // (c) Fuzzy substring fallback — kept conservative; only triggers
        //     when nothing better was found and source is ≥ 4 chars.
        if (!$best && strlen($normSrc) >= 4) {
            $bestScore = 0.0;
            foreach (array_keys($targetsByCol) as $col) {
                $score = mappingSuggesterScore($normSrc, $col, false);
                if ($score >= 0.55 && $score > $bestScore) {
                    $best = $targetsByCol[$col][0];
                    $best['_score'] = $score;
                    $bestVia = 'fuzzy';
                    $bestScore = $score;
                }
            }
        }
        if (!$best) continue;

        // Decide linked_entity — prefer the target's
        // default_linked_entity, else the entity-type default.
        $linked = (string) ($best['default_linked_entity'] ?? '') ?: $linkedEntity;

        $suggestions[] = [
            'source_path'   => $srcPath,
            'sample_value'  => isset($p['sample_value']) ? (string) $p['sample_value'] : null,
            'value_type'    => $type,
            'target_module' => (string) ($best['target_module'] ?? ''),
            'target_table'  => (string) ($best['target_table']  ?? ''),
            'target_column' => (string) ($best['target_column'] ?? ''),
            'linked_entity' => $linked,
            'transform'     => mappingSuggesterDefaultTransform($normSrc, (string) ($best['target_column'] ?? '')),
            'confidence'    => (float) $best['_score'],
            'reason'        => $bestVia ?? 'match',
        ];
    }

    // 5. Sort by confidence desc, then alpha for stability.
    usort($suggestions, function ($a, $b) {
        if ($a['confidence'] === $b['confidence']) return strcmp($a['source_path'], $b['source_path']);
        return $b['confidence'] <=> $a['confidence'];
    });

    if ($limit > 0 && count($suggestions) > $limit) {
        $suggestions = array_slice($suggestions, 0, $limit);
    }
    return $suggestions;
}
