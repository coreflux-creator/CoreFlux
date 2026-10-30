<?php
/** Assignment classification evidence, shared by preview, import and field mappings. */
declare(strict_types=1);

function jobdivaClassificationValue(mixed $raw, bool $assignmentCategory = false): ?string
{
    if (!is_scalar($raw)) return null;
    $key = strtolower(trim((string) preg_replace('/[\s_\-\/\\\\]+/', ' ', (string) $raw)));
    $aliases = [
        'w2'=>'w2', 'w 2'=>'w2', 'w2 employee'=>'w2', 'w 2 employee'=>'w2',
        'hourly employee'=>'w2', 'salaried employee'=>'w2', 'employee'=>'w2',
        '1099'=>'1099', '1099 contractor'=>'1099', 'independent contractor'=>'1099', 'ic'=>'1099',
        'c2c'=>'c2c', 'corp to corp'=>'c2c', 'crop to crop'=>'c2c', 'corp 2 corp'=>'c2c',
        'corporation to corporation'=>'c2c', 'inc to inc'=>'c2c',
        'temp to perm'=>'temp_to_perm', 'contract to hire'=>'temp_to_perm', 'cth'=>'temp_to_perm',
        'direct hire'=>'direct_hire', 'direct placement'=>'direct_hire', 'permanent'=>'direct_hire', 'perm'=>'direct_hire',
        'internal'=>'internal', 'ref'=>'referral', 'referral'=>'referral',
    ];
    // This is JobDiva's Assignment SALARY category, not a generic vendor/name heuristic.
    if ($assignmentCategory && $key === 'subcontract') return 'c2c';
    return $aliases[$key] ?? null;
}

function jobdivaClassificationKey(string $key): string
{
    return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
}

/** All observations at one authoritative level must agree; array order has no meaning. */
function jobdivaClassificationReview(array $entries, string $source): array
{
    $types = []; $issues = []; $observations = [];
    $typedKeys = ['employmentcategory','employeecategory','engagementtype','workertype','classification',
        'employmenttype','employeetype','positiontype','taxtype','payrolltype','contracttype','jobtype','hiretype'];
    $flagKeys = ['w2'=>'w2','isw2'=>'w2','tcsw2'=>'w2','c2c'=>'c2c','isc2c'=>'c2c','tcsc2c'=>'c2c',
        'corptocorp'=>'c2c','croptocrop'=>'c2c','corporationtocorporation'=>'c2c'];
    foreach ($entries as $entry) {
        $key = jobdivaClassificationKey((string) ($entry['key'] ?? ''));
        $path = (string) ($entry['path'] ?? $entry['key'] ?? '');
        // Cost profiles and related-party/candidate/job preferences cannot classify a worker.
        if (preg_match('/(?:^|\.)(?:overheads?|company|companies|job|candidate|history|_jd_job|_jd_candidate)(?:\.|$)/i', $path)) continue;
        if (!in_array($key, $typedKeys, true) && !isset($flagKeys[$key])) continue;
        if (isset($flagKeys[$key]) && !in_array($source, ['start_assignment','csv_flag','mapped_source'], true)) continue;
        $value = $entry['value'] ?? null;
        if (!is_scalar($value) && $value !== null) continue;
        $raw = strtolower(trim((string) $value));
        $type = null;
        if (isset($flagKeys[$key])) {
            if (in_array($raw, ['1','true','yes','y','on','checked'], true)) $type = $flagKeys[$key];
            elseif (!in_array($raw, ['', '0','false','no','n','off','unchecked','null'], true)) {
                $explicit = jobdivaClassificationValue($value);
                if ($explicit === $flagKeys[$key]) $type = $explicit;
                else $issues[] = "$path: unrecognized classification flag.";
            }
        } else {
            $type = jobdivaClassificationValue($value, in_array($key, ['employmentcategory','employeecategory'], true));
            // Generic work arrangements provide no tax/settlement classification.
            $neutral = in_array($key, ['positiontype','jobtype','contracttype','hiretype'], true)
                && in_array($raw, ['contract','temporary','temp','full time','full-time','part time','part-time'], true);
            if ($raw !== '' && $type === null && !$neutral) $issues[] = "$path: employment category is unrecognized or ambiguous.";
        }
        $observations[] = ['path'=>$path, 'key'=>(string) ($entry['key'] ?? ''), 'value'=>$value, 'classification'=>$type];
        if ($type !== null) $types[$type] = true;
    }
    if (count($types) > 1) $issues[] = 'Conflicting assignment classifications: ' . implode(', ', array_keys($types)) . '.';
    $hasEvidence = $types !== [] || $issues !== [];
    if ($types === [] && $issues === []) $issues[] = 'An explicit assignment employment category or positive classification flag is required.';
    return [
        'version'=>1, 'source'=>$source, 'has_evidence'=>$hasEvidence, 'status'=>$issues === [] ? 'resolved' : 'needs_review',
        'engagement_type'=>$issues === [] ? (string) array_key_first($types) : null,
        'observations'=>$observations, 'issues'=>array_values(array_unique($issues)),
    ];
}

/** Convert only the given source object, without scanning unrelated payload facets. */
function jobdivaClassificationEntries(array $node, string $prefix = ''): array
{
    $entries = [];
    foreach ($node as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if (is_array($value)) $entries = array_merge($entries, jobdivaClassificationEntries($value, $path));
        else $entries[] = ['path'=>$path, 'key'=>(string) $key, 'value'=>$value];
    }
    return $entries;
}

function jobdivaClassificationFromPayload(array $payload): array
{
    $contract = is_array($payload['_jd_contract'] ?? null) ? $payload['_jd_contract'] : [];
    if ($contract === [] && is_array($payload['__cf_classification_review']['observations'] ?? null)) {
        return jobdivaClassificationReview($payload['__cf_classification_review']['observations'], (string) ($payload['__cf_classification_review']['source'] ?? 'start_assignment'));
    }
    if (is_array($contract['classification_review']['observations'] ?? null)) {
        // Recompute from observations, never trust a cached resolved/complete boolean.
        return jobdivaClassificationReview($contract['classification_review']['observations'], (string) ($contract['classification_review']['source'] ?? 'assignment'));
    }
    if (trim((string) ($contract['employment_category'] ?? '')) !== '') {
        return jobdivaClassificationReview([['key'=>'employment_category','path'=>'Assignment.SALARY.EMPLOYMENT_CATEGORY','value'=>$contract['employment_category']]], 'assignment_salary');
    }
    // Old synthesized contracts always stored employment_category and overhead flags.
    // A missing category cannot be repaired by trusting their derived engagement_type.
    if ($contract !== [] && !array_key_exists('employment_category', $contract)
        && !array_key_exists('w2_flag', $contract) && !array_key_exists('c2c_flag', $contract)
        && array_key_exists('engagement_type', $contract)) {
        return jobdivaClassificationReview([['key'=>'engagement_type','path'=>'Assignment.engagement_type','value'=>$contract['engagement_type']]], 'explicit_canonical_assignment');
    }
    $entries = [];
    foreach (['_jd_start','assignment','start','Start','jobdiva_assignment'] as $root) {
        if (is_array($payload[$root] ?? null)) $entries = array_merge($entries, jobdivaClassificationEntries($payload[$root], $root));
    }
    foreach ($payload as $key=>$value) {
        if (!is_array($value)) $entries[] = ['path'=>(string)$key,'key'=>(string)$key,'value'=>$value];
    }
    return jobdivaClassificationReview($entries, 'start_assignment');
}

function jobdivaClassificationRequire(array $payload): string
{
    $review = jobdivaClassificationFromPayload($payload);
    if ($review['status'] !== 'resolved') throw new RuntimeException('JobDiva classification needs review: ' . implode(' ', $review['issues']));
    return $review['engagement_type'];
}
