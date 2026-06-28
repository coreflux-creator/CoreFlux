<?php
/**
 * field_mapping_automap_suggestions_smoke.php
 *
 * Locks in the AI auto-map (rule-based) feature requested by the
 * operator after the joined-entity extraction shipped: "Yes add the
 * suggestions".
 *
 * Tests three concentric rings:
 *  1. Pure-function unit tests on the mapping-suggester internals —
 *     normalisation, scoring, synonym dictionary, entity defaults,
 *     target indexing, default-transform inference.
 *  2. API endpoint wiring (RBAC, contract, request/response shape).
 *  3. Studio UI surfaces — button, modal, list, checkbox, apply
 *     handler, select-all/none, error/empty states.
 *
 * Run:  php -d zend.assertions=1 tests/field_mapping_automap_suggestions_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/core/integrations/mapping_suggester.php';

$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else       { $fail++; $failures[] = $label; echo "  ✗ $label\n"; }
};

echo "Auto-map suggestions smoke\n";
echo "=========================\n";

// 1) mappingSuggesterNormalise — handles snake_case / camelCase / dotted.
echo "\n1. mappingSuggesterNormalise\n";
$a('snake_case → flat lowercase',
    mappingSuggesterNormalise('first_name') === 'firstname');
$a('camelCase → flat lowercase',
    mappingSuggesterNormalise('firstName') === 'firstname');
$a('UPPER_SNAKE → flat lowercase',
    mappingSuggesterNormalise('FIRST_NAME') === 'firstname');
$a('dotted path uses TAIL segment only',
    mappingSuggesterNormalise('_jd_candidate.firstName') === 'firstname');
$a('array suffix stripped',
    mappingSuggesterNormalise('emails[].value') === 'value');
$a('non-alphanumeric removed',
    mappingSuggesterNormalise('email-address') === 'emailaddress');

// 2) Synonym dictionary covers the common JobDiva/QBO/Zoho cases.
echo "\n2. Synonym dictionary completeness\n";
$syn = mappingSuggesterSynonymMap();
foreach ([
    'firstname'    => 'first_name',
    'lastname'     => 'last_name',
    'workemail'    => 'email_primary',
    'mobilephone'  => 'phone_primary',
    'zipcode'      => 'postal_code',
    'jobtitle'     => 'title',
    'agreedpayrate' => 'pay_rate',
    'finalbillrate' => 'bill_rate',
    'customername' => 'name',
    'companyname'  => 'name',
    'startdate'    => 'start_date',
    'enddate'      => 'end_date',
    'startstatus'  => 'status',
    'id'           => 'external_id',
] as $src => $tgt) {
    $a("synonym {$src} → {$tgt}",
        isset($syn[$src]) && in_array($tgt, $syn[$src], true));
}

$a('dept/department synonyms prefer real staffing job department column',
    isset($syn['dept'], $syn['department'])
    && ($syn['dept'][0] ?? null) === 'department'
    && ($syn['department'][0] ?? null) === 'department');

// 3) Entity defaults route to the right module + linked_entity.
echo "\n3. Entity defaults (preferred module + linked_entity)\n";
foreach ([
    'person'           => ['people',     'person'],
    'staffing_job'     => ['staffing',   'staffing_job'],
    'job'              => ['staffing',   'staffing_job'],
    'jobdiva_job'      => ['staffing',   'staffing_job'],
    'jobdiva_customer' => ['companies',  'end_client_company'],
    'contact'          => ['companies',  'self'],
    'assignment'       => ['placements', 'self'],
    'placement'        => ['placements', 'self'],
    'company'          => ['companies',  'self'],
    'time_entry'       => ['time',       'self'],
    'vendor'           => ['ap',         'self'],
    'invoice'          => ['billing',    'self'],
    'journal_entry'    => ['accounting', 'self'],
] as $entity => [$mod, $linked]) {
    $d = mappingSuggesterEntityDefaults($entity);
    $a("entity {$entity} → module={$mod}, linked_entity={$linked}",
        $d['module'] === $mod && $d['linked_entity'] === $linked);
}
$a('unknown entity falls back to (module=null, linked_entity=self)',
    mappingSuggesterEntityDefaults('bogus_entity_type')['module'] === null
    && mappingSuggesterEntityDefaults('bogus_entity_type')['linked_entity'] === 'self');

// 4) Scoring tiers.
echo "\n4. mappingSuggesterScore tiers\n";
$a('exact normalised match scores 0.95',
    mappingSuggesterScore('firstname', 'first_name', false) === 0.95);
$a('synonym match scores 0.85',
    mappingSuggesterScore('workemail', 'email_primary', true) === 0.85);
$a('fuzzy substring scores 0.55 when ≥ 4 chars overlap',
    mappingSuggesterScore('phoneprimary', 'phone', false) === 0.55);
$a('totally unrelated returns 0.0',
    mappingSuggesterScore('a', 'unrelated_column', false) === 0.0);

// 5) Default transforms.
echo "\n5. Default transform inference\n";
$a('*_date column → date_normalise',
    mappingSuggesterDefaultTransform('startdate', 'start_date') === 'date_normalise');
$a('status column → lowercase',
    mappingSuggesterDefaultTransform('startstatus', 'status') === 'lowercase');
$a('currency source + currency target → uppercase',
    mappingSuggesterDefaultTransform('payratecurrencyunit', 'currency') === 'uppercase');
$a('non-special → none',
    mappingSuggesterDefaultTransform('firstname', 'first_name') === 'none');

// 6) Target indexing helper.
echo "\n6. mappingSuggesterIndexTargets\n";
$targets = [
    ['target_module' => 'people',     'target_table' => 'people', 'target_column' => 'first_name'],
    ['target_module' => 'people',     'target_table' => 'people', 'target_column' => 'last_name'],
    ['target_module' => 'placements', 'target_table' => 'placements', 'target_column' => 'first_name'],
    ['target_module' => 'companies',  'target_table' => 'companies',  'target_column' => '*'], // wildcard ignored
];
$idx = mappingSuggesterIndexTargets($targets);
$a('indexes by target_column', isset($idx['first_name']) && isset($idx['last_name']));
$a('multiple rows per column preserved',
    is_array($idx['first_name']) && count($idx['first_name']) === 2);
$a('wildcard target_column skipped',
    !isset($idx['*']));

// 7) API endpoint sanity (presence, RBAC, contract).
echo "\n7. /api/admin/integrations/suggest_mappings.php\n";
$ep = "$root/api/admin/integrations/suggest_mappings.php";
$a('endpoint file exists', file_exists($ep));
$src = (string) @file_get_contents($ep);
$a('requires mapping_suggester.php',
    str_contains($src, "core/integrations/mapping_suggester.php"));
$a('requires api_require_auth + RBAC gate',
    str_contains($src, 'api_require_auth')
    && str_contains($src, "rbac_legacy_require(\$user, 'tenant_admin.integrations')"));
$a('supports POST (json body) and GET (query)',
    str_contains($src, "'POST'") && str_contains($src, "'GET'"));
$a('validates required integration + entity_type',
    str_contains($src, "if (\$integration === '' || \$entityType === '')"));
$a('calls mappingSuggesterSuggest with tenant + limit',
    str_contains($src, 'mappingSuggesterSuggest($tid, $integration, $entityType, $limit)'));
$a('response shape includes count + suggestions',
    str_contains($src, "'count'") && str_contains($src, "'suggestions'"));

// 8) Studio UI — button + modal + apply pipeline.
echo "\n8. FieldMappingStudio.jsx auto-map UI\n";
$fms = file_get_contents("$root/dashboard/src/pages/FieldMappingStudio.jsx");
$a('declares suggestion state hooks',
    str_contains($fms, 'const [suggestOpen, setSuggestOpen]')
    && str_contains($fms, 'const [suggestList, setSuggestList]')
    && str_contains($fms, 'const [suggestSelected, setSuggestSelected]')
    && str_contains($fms, 'const [suggestApplying, setSuggestApplying]'));
$a('loadSuggestions calls POST suggest_mappings.php',
    str_contains($fms, "'/api/admin/integrations/suggest_mappings.php'"));
$a('default-selects high-confidence rows ≥0.85',
    str_contains($fms, "if ((s.confidence ?? 0) >= 0.85) sel[i] = true"));
$a('applySuggestions iterates picks + posts /field_map.php',
    str_contains($fms, 'applySuggestions')
    && str_contains($fms, "'/api/admin/integrations/field_map.php'"));
$a('Auto-map button rendered with testid',
    str_contains($fms, 'data-testid="fms-automap-btn"'));
$a('modal rendered with testid fms-suggest-modal',
    str_contains($fms, 'data-testid="fms-suggest-modal"'));
$a('modal has reload + select-all + select-none + apply controls',
    str_contains($fms, 'data-testid="fms-suggest-reload"')
    && str_contains($fms, 'data-testid="fms-suggest-select-all"')
    && str_contains($fms, 'data-testid="fms-suggest-select-none"')
    && str_contains($fms, 'data-testid="fms-suggest-apply"'));
$a('per-row checkbox + row testid pattern',
    str_contains($fms, 'data-testid={`fms-suggest-row-${i}`}')
    && str_contains($fms, 'data-testid={`fms-suggest-check-${i}`}'));
$a('empty state + error state surfaces',
    str_contains($fms, 'data-testid="fms-suggest-empty"')
    && str_contains($fms, 'data-testid="fms-suggest-error"'));
$a('shared <th>/<td> helpers declared',
    str_contains($fms, 'const thStyle = {')
    && str_contains($fms, 'const tdStyle = {'));

// 8.5) Inline-edit per row — target column, linked_entity, transform.
echo "\n8.5 Inline-editable suggestion rows\n";
$a('per-row target dropdown rendered',
    str_contains($fms, 'data-testid={`fms-suggest-target-${i}`}'));
$a('target dropdown exposes data-current attribute',
    str_contains($fms, "data-current={currentKey}"));
$a('target dropdown options scoped to same module first',
    str_contains($fms, 'sameModule = allOpts.filter(t => t.target_module === s.target_module)')
    && str_contains($fms, 'const opts = [...sameModule, ...otherModule]'));
$a('current selection injected when missing from writable list',
    str_contains($fms, 'if (!hasCurrent) {')
    && str_contains($fms, 'opts.unshift({'));
$a('per-row linked_entity dropdown',
    str_contains($fms, 'data-testid={`fms-suggest-linked-${i}`}'));
$a('per-row transform dropdown with sane choices',
    str_contains($fms, 'data-testid={`fms-suggest-transform-${i}`}')
    && str_contains($fms, "'none', 'lowercase', 'uppercase', 'trim', 'date_normalise', 'json_decode'"));
$a('edited rows flag _edited and surface a marker',
    str_contains($fms, '_edited: true')
    && str_contains($fms, 'data-testid={`fms-suggest-edited-${i}`}'));
$a('apply pipeline uses the current row.target_module/table/column (post-edit)',
    str_contains($fms, "target_module: s.target_module")
    && str_contains($fms, "target_table:  s.target_table")
    && str_contains($fms, "target_column: s.target_column"));

// 9) PHP lint sanity.
echo "\n9. PHP syntax\n";
$lint = shell_exec('php -l ' . escapeshellarg("$root/core/integrations/mapping_suggester.php") . ' 2>&1');
$a('php -l mapping_suggester.php', str_contains((string) $lint, 'No syntax errors detected'));
$lint2 = shell_exec('php -l ' . escapeshellarg($ep) . ' 2>&1');
$a('php -l suggest_mappings.php', str_contains((string) $lint2, 'No syntax errors detected'));

echo "\n=========================\n";
echo "Auto-map suggestions smoke: $pass ✓ / $fail ✗\n";
echo "=========================\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! $msg\n";
    exit(1);
}
exit(0);
