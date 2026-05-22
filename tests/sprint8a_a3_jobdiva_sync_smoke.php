<?php
/**
 * Sprint 8a / Slice A3 smoke — JobDiva sync drivers.
 *
 * Static / contract assertions only:
 *   - core/jobdiva/sync.php parses + exports drivers
 *   - Each driver pulls items via jobdivaSyncFetchItems (testable via
 *     items_override) and binds via mappingUpsert with source_system='jobdiva'
 *   - Companies driver upserts into companies via companiesUpsertByName + tags 'client'
 *   - Contacts driver resolves company via mappingFindInternal first
 *   - Placements driver resolves person mapping first; skips if missing
 *     (we DO NOT auto-create candidates/applicants per user requirement)
 *   - jobdivaSyncAll aggregates counts, latency, bumps last_sync_at
 *   - api/jobdiva.php sync action invokes jobdivaSyncAll, returns counts/total/latency
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

echo "Sync driver — core/jobdiva/sync.php\n";
$path = "{$ROOT}/core/jobdiva/sync.php";
$src = (string) file_get_contents($path);
$assert('file exists',                            strlen($src) > 0);
$assert('parses',                                 $lint($path));
$assert('declares strict_types',                  strpos($src, 'declare(strict_types=1)') !== false);
$assert('requires JobDiva client',                strpos($src, "require_once __DIR__ . '/client.php'") !== false);
$assert('requires entity_mappings library',       strpos($src, "require_once __DIR__ . '/../integrations/entity_mappings.php'") !== false);
$assert('requires people companies lib',          strpos($src, "require_once __DIR__ . '/../../modules/people/lib/companies.php'") !== false);
$assert('exports jobdivaSyncCompanies',           strpos($src, 'function jobdivaSyncCompanies(') !== false);
$assert('exports jobdivaSyncContacts',            strpos($src, 'function jobdivaSyncContacts(') !== false);
$assert('exports jobdivaSyncPlacements',          strpos($src, 'function jobdivaSyncPlacements(') !== false);
$assert('exports jobdivaSyncAll',                 strpos($src, 'function jobdivaSyncAll(') !== false);
$assert('exports jobdivaSyncFetchItems',          strpos($src, 'function jobdivaSyncFetchItems(') !== false);

echo "\nFetch shape — paginated + list + override\n";
$assert('items_override bypass for tests',
    strpos($src, "isset(\$opts['items_override']) && is_array(\$opts['items_override'])") !== false);
$assert('passes fromDate/toDate query through (V2 BI format)',
    strpos($src, "'fromDate'") !== false && strpos($src, "'toDate'") !== false
    && strpos($src, "m/d/Y H:i:s") !== false);
$assert('handles {data:[...]} pagination',        strpos($src, "isset(\$resp['data'])  && is_array(\$resp['data'])") !== false);
$assert('userFieldsName workaround on Companies + Contacts BI calls',
    strpos($src, "'/apiv2/bi/NewUpdatedCompanyRecords'") !== false
    && strpos($src, "'/apiv2/bi/NewUpdatedContactRecords'") !== false
    && strpos($src, "\$query['userFieldsName'] = ''") !== false);
$assert('resilient retry helper exists',
    strpos($src, "function jobdivaSyncFetchWithRetry") !== false);
$assert('retry halves window on 500/Not an array/timeout',
    strpos($src, "stripos(\$msg, 'HTTP 500')") !== false
    && strpos($src, "stripos(\$msg, 'Not an array')") !== false
    && strpos($src, "stripos(\$msg, 'timeout')") !== false);
$assert('retry honours 1-hour floor',
    strpos($src, '$minWindowSec = 3600') !== false
    && strpos($src, "'sync_retry_floor_hit'") !== false);
$assert('retry audit logs success on later attempt',
    strpos($src, "'sync_retry_succeeded'") !== false);
$assert('default delta window is 7 days (was 30, narrowed for JobDiva BI stability)',
    strpos($src, "default_window_days'] ?? 7") !== false);
$assert('Companies driver opts into retry helper',
    strpos($src, "jobdivaSyncFetchWithRetry(\$tid, JOBDIVA_PATH_COMPANIES_DELTA") !== false);
$assert('Contacts driver opts into retry helper',
    strpos($src, "jobdivaSyncFetchWithRetry(\$tid, JOBDIVA_PATH_CONTACTS_DELTA") !== false);
$assert('handles {items:[...]} pagination',       strpos($src, "isset(\$resp['items']) && is_array(\$resp['items'])") !== false);
$assert('handles plain list response',            strpos($src, 'array_keys($resp) === range(0, count($resp) - 1)') !== false);

echo "\nCompanies driver\n";
$assert('hits V2 BI NewUpdatedCompanyRecords',    strpos($src, 'JOBDIVA_PATH_COMPANIES_DELTA')  !== false
                                                  && strpos($src, "'/apiv2/bi/NewUpdatedCompanyRecords'") !== false);
$assert('falls back across id key spellings',     strpos($src, "\$jd['companyId']") !== false && strpos($src, "\$jd['company_id']") !== false);
$assert('falls back across name key spellings',   strpos($src, "\$jd['companyName']") !== false && strpos($src, "\$jd['company_name']") !== false);
$assert('upserts via companiesUpsertByName',      strpos($src, "companiesUpsertByName(\$tid, \$name") !== false);
$assert("tags 'client' role on backfilled company", strpos($src, "['client']") !== false);
$assert('binds mapping (company)',
    strpos($src, "mappingUpsert(\$tid, 'jobdiva', 'company', \$extId, \$companyId, \$jd, 'pull')") !== false);
$assert('skips records missing extId or name',    strpos($src, "if (\$extId === '' || \$name === '') { \$skipped++; continue; }") !== false);
$assert('emits audit row entity_type=company',
    strpos($src, "'entity_type'     => 'company'") !== false
    && strpos($src, "'direction'       => 'pull'") !== false);
$assert('truncates errors at 50',                 strpos($src, 'count($errors) >= 50') !== false);

echo "\nContacts driver\n";
$assert('hits V2 BI NewUpdatedContactRecords',    strpos($src, 'JOBDIVA_PATH_CONTACTS_DELTA')   !== false
                                                  && strpos($src, "'/apiv2/bi/NewUpdatedContactRecords'") !== false);
$assert('resolves company via mappingFindInternal',
    strpos($src, "mappingFindInternal(\$tid, 'jobdiva', 'company', \$companyExtId)") !== false);
$assert('skips when company mapping missing + records reason',
    strpos($src, '$companyMapping = mappingFindInternal') !== false
    && strpos($src, "if (!\$companyMapping) {\n                \$skipped++; \$skipReasons['company_unmapped']++;") !== false);
$assert('surfaces company_unmapped diagnostic in errors[] for UI',
    strpos($src, "'kind'        => 'company_unmapped'") !== false
    && strpos($src, "parent company has no mapping") !== false);
$assert('first-sync mode widens window to 365 days',
    strpos($src, "function jobdivaSyncIsFirstSync") !== false
    && strpos($src, "\$opts['default_window_days'] = 365") !== false
    && strpos($src, "'sync_first_backfill'") !== false);
$assert('binds mapping (contact)',
    strpos($src, "mappingUpsert(\$tid, 'jobdiva', 'contact', \$extId, \$internalId, \$jd, 'pull')") !== false);
$assert('contact upsert helper exists',           strpos($src, 'function jobdivaSyncUpsertContact(') !== false);
$assert('contact upsert dedupes by email per company',
    strpos($src, "AND company_id = :c AND email = :e LIMIT 1") !== false);
$assert("contact insert defaults role to 'other'",
    strpos($src, "contact_role)\n         VALUES (:t, :c, :n, :ti, :e, :ph, \"other\")") !== false);

echo "\nPlacements driver — deferred-by-design (no V2 BI endpoint)\n";
$assert('emits sync_skip audit when no items_override',
    strpos($src, "'sync_skip'") !== false && strpos($src, "'no_v2_bi_endpoint'") !== false);
$assert('returns deferred_reason for callers',
    strpos($src, "'deferred_reason'") !== false);
$assert('still honours items_override for tests',
    strpos($src, "if (isset(\$opts['items_override']) && is_array(\$opts['items_override'])) {\n        // Smoke tests still drive the upsert logic via items_override.") !== false);
$assert('resolves person via existing mapping',
    strpos($src, "mappingFindInternal(\$tid, 'jobdiva', 'person', \$personExtId)") !== false);
$assert('skips when person mapping missing (NO ATS auto-create)',
    strpos($src, '$personMapping = mappingFindInternal') !== false
    && strpos($src, 'if (!$personMapping) { $skipped++; continue; }') !== false);
$assert('optionally resolves end_client company_id',
    strpos($src, "if (\$companyExtId !== '') {") !== false
    && strpos($src, "mappingFindInternal(\$tid, 'jobdiva', 'company', \$companyExtId)") !== false);
$assert('placement upsert helper exists',         strpos($src, 'function jobdivaSyncUpsertPlacement(') !== false);
$assert("placement uses 'jd:' external_id prefix",
    strpos($src, "'jd:' . \$extId") !== false);
$assert('placement status maps JobDiva → CoreFlux enum',
    strpos($src, "'pending' => 'pending_start'") !== false
    && strpos($src, "'cancelled' => 'cancelled'") !== false);
$assert("placement insert defaults engagement_type='w2'",
    strpos($src, '"w2"') !== false);
$assert('binds mapping (placement)',
    strpos($src, "mappingUpsert(\$tid, 'jobdiva', 'placement', \$extId, \$internalId, \$jd, 'pull')") !== false);

echo "\njobdivaSyncAll — orchestration\n";
$assert('runs all 3 drivers in order via safeRun isolator',
    strpos($src, "\$companies  = \$safeRun('company',")   !== false
    && strpos($src, "\$contacts   = \$safeRun('contact',") !== false
    && strpos($src, "\$placements = \$safeRun('placement',") !== false
    && strpos($src, "jobdivaSyncCompanies(\$tid, \$userId")   !== false
    && strpos($src, "jobdivaSyncContacts(\$tid, \$userId")    !== false
    && strpos($src, "jobdivaSyncPlacements(\$tid, \$userId")  !== false);
$assert('per-entity errors isolated (one fail no longer aborts whole sync)',
    strpos($src, "'sync_entity_error'") !== false
    && strpos($src, "'processed' => 0, 'skipped' => 0, 'failed' => 1") !== false);
$assert('measures latency_ms',                    strpos($src, '(int) round((microtime(true) - $start) * 1000)') !== false);
$assert('returns counts {company,contact,placement}',
    strpos($src, "'company'   => \$companies['processed']") !== false
    && strpos($src, "'contact'   => \$contacts['processed']") !== false
    && strpos($src, "'placement' => \$placements['processed']") !== false);
$assert('total = sum of counts',                  strpos($src, '$total      = array_sum($counts)') !== false);
$assert('bumps connection.last_sync_at',
    strpos($src, "'UPDATE jobdiva_connections SET last_sync_at = NOW()") !== false);
$assert('returns by_entity envelope',             strpos($src, "'by_entity'") !== false && strpos($src, "=> [\n            'company'   => \$companies") !== false);

echo "\nAPI wiring — api/jobdiva.php\n";
$api = (string) file_get_contents("{$ROOT}/api/jobdiva.php");
$assert('requires sync.php',                      strpos($api, "require_once __DIR__ . '/../core/jobdiva/sync.php'") !== false);
$assert('sync action invokes jobdivaSyncAll',     strpos($api, '$result = jobdivaSyncAll($tid, $user') !== false);
$assert('sync action returns counts/total/latency_ms',
    strpos($api, "'counts'     => \$result['counts']") !== false
    && strpos($api, "'total'      => \$result['total']") !== false
    && strpos($api, "'latency_ms' => \$result['latency_ms']") !== false);
$assert('sync action passes modified_since opt',  strpos($api, "\$opts['modified_since'] = (string) \$body['modified_since']") !== false);
$assert('sync action 502s on Throwable',          strpos($api, "api_error('Sync failed: '") !== false);
$assert('A1 placeholder note removed',
    strpos($api, 'Slice A1 placeholder') === false
    && strpos($api, 'Slice A1 — manual sync is wired') === false);

echo "\nUI — ConnectedSourcesBadge\n";
$badgePath = "{$ROOT}/dashboard/src/components/ConnectedSourcesBadge.jsx";
$badge = (string) file_get_contents($badgePath);
$assert('component file exists',                  strlen($badge) > 0);
$assert('default export',                         strpos($badge, 'export default function ConnectedSourcesBadge') !== false);
$assert('reads list_for_internal endpoint',
    strpos($badge, "/api/integrations/mappings.php?action=list_for_internal") !== false);
$assert('encodes entityType + internalId',
    strpos($badge, 'encodeURIComponent(entityType)') !== false
    && strpos($badge, 'encodeURIComponent(internalId)') !== false);
$assert('renders nothing when zero mappings',     strpos($badge, 'if (mappings.length === 0) return null') !== false);
$assert('chip dynamic testid by source_system',
    strpos($badge, 'data-testid={`connected-source-chip-${m.source_system}`}') !== false);
$assert('container testid',                       strpos($badge, 'data-testid={`connected-sources-${entityType}-${internalId}`}') !== false);
$assert('palette covers ok/stale/error/deleted_in_source',
    strpos($badge, 'ok:') !== false && strpos($badge, 'stale:') !== false
    && strpos($badge, 'error:') !== false && strpos($badge, 'deleted_in_source:') !== false);
$assert('renders source-status text when not ok',
    strpos($badge, "m.sync_status !== 'ok'") !== false);
$assert('label map includes JobDiva',             strpos($badge, "jobdiva: 'JobDiva'") !== false);

echo "\nWiring — PersonDetail header\n";
$pd = (string) file_get_contents("{$ROOT}/modules/people/ui/PersonDetail.jsx");
$assert('imports ConnectedSourcesBadge',          strpos($pd, "import ConnectedSourcesBadge from '../../../dashboard/src/components/ConnectedSourcesBadge'") !== false);
$assert('renders badge with entityType=person',
    strpos($pd, '<ConnectedSourcesBadge entityType="person" internalId={person.id} />') !== false);

echo "\nWiring — DirectoryDetail (companies) header\n";
$dd = (string) file_get_contents("{$ROOT}/modules/people/ui/DirectoryModule.jsx");
$assert('imports ConnectedSourcesBadge',          strpos($dd, "import ConnectedSourcesBadge from '../../../dashboard/src/components/ConnectedSourcesBadge'") !== false);
$assert('renders badge with entityType=company',
    strpos($dd, '<ConnectedSourcesBadge entityType="company" internalId={c.id} />') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
