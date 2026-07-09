<?php
/**
 * Placements CSV import — uses Core\CsvImportService primitive.
 * Per HARD_RULES (2026-02-XX): every primary-entity module MUST expose CSV import.
 *
 *   GET  /api/placements/csv_import?action=template
 *   POST /api/placements/csv_import?action=dry_run
 *   POST /api/placements/csv_import?action=commit (+ optional ?skip_invalid=1)
 *
 * Imports the placement record, first/current draft rate row, and the
 * canonical placement_client_chain tiers (end client, MSP, prime vendor,
 * sub-vendor) so manual import has the same graph shape as UI-created
 * placements and JobDiva projection.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';
require_once __DIR__ . '/../lib/placements.php';
require_once __DIR__ . '/../../people/lib/companies.php';
require_once __DIR__ . '/../../staffing/lib/clients.php';

use Core\CsvImportService;

require_once __DIR__ . '/../lib/csv_helpers.php';

CsvImportService::registerSchema('placements', [
    'fields' => [
        // person_id is the preferred lookup (numeric primary key, copy
        // from People directory). person_email kept as a fallback for
        // legacy CSVs. At least one is required — enforced in dry_run
        // since CsvImportService validates fields individually.
        'person_id'         => ['label' => 'Person ID',        'type'  => 'integer'],
        'person_email'      => ['label' => 'Person email',     'type'  => 'email'],
        // placement_id is the preferred match key for the "update
        // existing row" pathway — beats external_id + title + start_date
        // composite lookup. Leave blank when creating a new placement.
        'placement_id'      => ['label' => 'Placement ID',     'type'  => 'integer'],
        'title'             => ['label' => 'Title',            'required' => true],
        'engagement_type'   => ['label' => 'Engagement type',  'required' => true,
                                'enum' => ['w2','1099','c2c','temp_to_perm','direct_hire']],
        'start_date'        => ['label' => 'Start date',       'required' => true, 'type' => 'date'],
        'end_date'          => ['label' => 'End date',         'type' => 'date'],
        'actual_end_date'   => ['label' => 'Actual end date',  'type' => 'date'],
        'due_date'          => ['label' => 'Due date',         'type' => 'date'],
        'end_client_company_id' => ['label' => 'End client company ID', 'type' => 'integer'],
        'end_client_name'   => ['label' => 'End client name'],
        'worksite_state'    => ['label' => 'Worksite state'],
        'worksite_country'  => ['label' => 'Worksite country (2-letter)'],
        'remote_policy'     => ['label' => 'Remote policy',    'enum' => ['onsite','hybrid','remote']],
        'client_approver_name'  => ['label' => 'Client approver name'],
        'client_approver_email' => ['label' => 'Client approver email', 'type' => 'email'],
        'jobdiva_job_id'        => ['label' => 'JobDiva job ID'],
        'recruiter_name'        => ['label' => 'Recruiter name'],
        'recruiter_email'       => ['label' => 'Recruiter email', 'type' => 'email'],
        'account_manager_name'  => ['label' => 'Account manager name'],
        'account_manager_email' => ['label' => 'Account manager email', 'type' => 'email'],
        'client_bill_cycle'     => ['label' => 'Client bill cycle', 'enum' => ['weekly','biweekly','semimonthly','monthly','adhoc']],
        'client_bill_cycle_anchor' => ['label' => 'Client bill cycle anchor', 'type' => 'date'],
        'vendor_pay_cycle'      => ['label' => 'Vendor pay cycle', 'enum' => ['weekly','biweekly','semimonthly','monthly','adhoc']],
        'vendor_pay_cycle_anchor' => ['label' => 'Vendor pay cycle anchor', 'type' => 'date'],
        'bill_rate'         => ['label' => 'Bill rate ($/hr)', 'type' => 'number'],
        'pay_rate'          => ['label' => 'Pay rate ($/hr)',  'type' => 'number'],
        'rate_effective_from' => ['label' => 'Rate effective from', 'type' => 'date'],
        'rate_effective_to'   => ['label' => 'Rate effective to',   'type' => 'date'],
        'bill_rate_unit'    => ['label' => 'Bill rate unit', 'enum' => ['hour','day','week','month','project']],
        'pay_rate_unit'     => ['label' => 'Pay rate unit',  'enum' => ['hour','day','week','month','project']],
        'currency'          => ['label' => 'Currency'],
        'ot_multiplier'     => ['label' => 'OT multiplier', 'type' => 'number'],
        'dt_multiplier'     => ['label' => 'DT multiplier', 'type' => 'number'],
        'adder_pct'         => ['label' => 'Adder %', 'type' => 'number'],
        'background_fee_total' => ['label' => 'Background fee total', 'type' => 'number'],
        'msp_name'          => ['label' => 'MSP name'],
        'msp_fee_pct'       => ['label' => 'MSP / discount fee %', 'type' => 'number'],
        'msp_fee_flat'      => ['label' => 'MSP / discount fee flat', 'type' => 'number'],
        'msp_submittal_id'  => ['label' => 'MSP submittal ID'],
        'msp_vms_job_id'    => ['label' => 'MSP VMS job ID'],
        'prime_vendor_name' => ['label' => 'Prime vendor name'],
        'prime_vendor_fee_pct' => ['label' => 'Prime vendor fee %', 'type' => 'number'],
        'prime_vendor_fee_flat' => ['label' => 'Prime vendor fee flat', 'type' => 'number'],
        'prime_vendor_submittal_id' => ['label' => 'Prime vendor submittal ID'],
        'prime_vendor_vms_job_id' => ['label' => 'Prime vendor VMS job ID'],
        'sub_vendor_name'   => ['label' => 'Sub-vendor name'],
        'sub_vendor_fee_pct' => ['label' => 'Sub-vendor fee %', 'type' => 'number'],
        'sub_vendor_fee_flat' => ['label' => 'Sub-vendor fee flat', 'type' => 'number'],
        'sub_vendor_submittal_id' => ['label' => 'Sub-vendor submittal ID'],
        'sub_vendor_vms_job_id' => ['label' => 'Sub-vendor VMS job ID'],
        'external_id'       => ['label' => 'External ID'],
        'notes'             => ['label' => 'Notes'],
    ],
    'unique_within_batch' => ['external_id', 'placement_id'],
]);

function placementsCsvBlankToNull(mixed $value): mixed
{
    if ($value === null) return null;
    if (is_string($value) && trim($value) === '') return null;
    return $value;
}

function placementsCsvPercentToDecimal(mixed $value): ?float
{
    if ($value === null || $value === '') return null;
    $s = trim((string) $value);
    if ($s === '') return null;
    $hadPercent = str_contains($s, '%');
    $s = str_replace([',', '%'], '', $s);
    if (!is_numeric($s)) return null;
    $n = (float) $s;
    if ($hadPercent || abs($n) > 1) $n = $n / 100;
    return round($n, 6);
}

function placementsCsvNormaliseCurrency(mixed $value): string
{
    $raw = strtoupper(trim((string) ($value ?? '')));
    if ($raw === '') return 'USD';
    if (preg_match('/\b([A-Z]{3})\b/', $raw, $m)) return $m[1];
    $cur = strtoupper(substr($raw, 0, 3));
    return strlen($cur) === 3 ? $cur : 'USD';
}

function placementsCsvBuildRatePayload(array $row): ?array
{
    if (($row['bill_rate'] ?? '') === '' || ($row['pay_rate'] ?? '') === '') {
        return null;
    }
    return [
        'effective_from' => $row['rate_effective_from'] ?? $row['start_date'],
        'effective_to'   => placementsCsvBlankToNull($row['rate_effective_to'] ?? null),
        'bill_rate'      => (float) $row['bill_rate'],
        'bill_rate_unit' => $row['bill_rate_unit'] ?? 'hour',
        'pay_rate'       => (float) $row['pay_rate'],
        'pay_rate_unit'  => $row['pay_rate_unit'] ?? 'hour',
        'currency'       => placementsCsvNormaliseCurrency($row['currency'] ?? 'USD'),
        'ot_multiplier'  => ($row['ot_multiplier'] ?? '') !== '' ? (float) $row['ot_multiplier'] : 1.5,
        'dt_multiplier'  => ($row['dt_multiplier'] ?? '') !== '' ? (float) $row['dt_multiplier'] : 2.0,
        'adder_pct'      => placementsCsvPercentToDecimal($row['adder_pct'] ?? null),
        'background_fee_total' => ($row['background_fee_total'] ?? '') !== ''
            ? (float) $row['background_fee_total']
            : null,
    ];
}

function placementsCsvUpsertDraftRate(int $placementId, array $row, ?int $userId, bool $updateExisting): void
{
    $payload = placementsCsvBuildRatePayload($row);
    if ($payload === null) return;

    $existing = scopedFind(
        'SELECT id, approved_at
           FROM placement_rates
          WHERE tenant_id = :tenant_id AND placement_id = :p AND effective_to IS NULL
          ORDER BY (approved_at IS NULL) DESC, effective_from DESC, id DESC
          LIMIT 1',
        ['p' => $placementId]
    );

    if ($updateExisting && $existing && empty($existing['approved_at'])) {
        scopedUpdate('placement_rates', (int) $existing['id'], $payload);
        return;
    }

    $payload['placement_id'] = $placementId;
    $payload['created_by_user_id'] = $userId;
    scopedInsert('placement_rates', $payload);
}

function placementsCsvUpsertChainRow(
    int $placementId,
    int $position,
    string $role,
    ?string $name,
    array $extras,
    ?int $userId
): void {
    $name = trim((string) $name);
    if ($placementId <= 0 || $position < 0 || $role === '' || $name === '') return;

    $roleForCompany = $role === 'end_client'
        ? 'client'
        : ($role === 'direct' ? 'client' : $role);
    $companyId = companiesUpsertByName(currentTenantId(), $name, [
        'created_by_user_id' => $userId,
    ], [$roleForCompany]);
    companiesBumpUsage($companyId);

    $payload = [
        'placement_id' => $placementId,
        'position'     => $position,
        'party_name'   => $name,
        'party_role'   => $role,
        'company_id'   => $companyId,
    ];
    foreach (['portal_fee_pct', 'portal_fee_flat', 'submittal_id', 'vms_job_id'] as $k) {
        if (array_key_exists($k, $extras) && $extras[$k] !== null && $extras[$k] !== '') {
            $payload[$k] = $extras[$k];
        }
    }

    $existing = scopedFind(
        'SELECT id FROM placement_client_chain
          WHERE tenant_id = :tenant_id AND placement_id = :p AND position = :pos
          LIMIT 1',
        ['p' => $placementId, 'pos' => $position]
    );
    if ($existing) {
        unset($payload['placement_id'], $payload['position']);
        scopedUpdate('placement_client_chain', (int) $existing['id'], $payload);
        return;
    }
    scopedInsert('placement_client_chain', $payload);
}

function placementsCsvUpsertChain(int $placementId, array $row, ?int $userId): void
{
    placementsCsvUpsertChainRow($placementId, 0, 'end_client', $row['end_client_name'] ?? null, [], $userId);

    $tiers = [
        ['prefix' => 'msp', 'position' => 1, 'role' => 'msp'],
        ['prefix' => 'prime_vendor', 'position' => 2, 'role' => 'prime_vendor'],
        ['prefix' => 'sub_vendor', 'position' => 3, 'role' => 'sub_vendor'],
    ];
    foreach ($tiers as $tier) {
        $prefix = $tier['prefix'];
        placementsCsvUpsertChainRow(
            $placementId,
            (int) $tier['position'],
            (string) $tier['role'],
            $row[$prefix . '_name'] ?? null,
            [
                'portal_fee_pct' => placementsCsvPercentToDecimal($row[$prefix . '_fee_pct'] ?? null),
                'portal_fee_flat' => ($row[$prefix . '_fee_flat'] ?? '') !== ''
                    ? (float) $row[$prefix . '_fee_flat']
                    : null,
                'submittal_id' => placementsCsvBlankToNull($row[$prefix . '_submittal_id'] ?? null),
                'vms_job_id' => placementsCsvBlankToNull($row[$prefix . '_vms_job_id'] ?? null),
            ],
            $userId
        );
    }
}

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'template') {
    rbac_legacy_require($user, 'placements.manage');
    $csv = CsvImportService::buildTemplate('placements');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="placements_template.csv"');
    header('Cache-Control: no-store');
    echo $csv;
    exit;
}

if ($method === 'GET' && $action === 'sample') {
    rbac_legacy_require($user, 'placements.manage');
    $samples = require __DIR__ . '/../../../core/csv_samples.php';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="placements_sample.csv"');
    header('Cache-Control: no-store');
    echo CsvImportService::buildSample('placements', $samples['placements'] ?? []);
    exit;
}


if ($method === 'POST' && $action === 'inspect') {
    rbac_legacy_require($user, 'placements.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('placements', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    rbac_legacy_require($user, 'placements.manage');
    require_once __DIR__ . '/../../../core/ai_csv_mapper.php';
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);

    // Read up to 3 sample rows alongside the header.
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $headers = fgetcsv($stream) ?: [];
    $samples = [];
    for ($i = 0; $i < 3; $i++) {
        $row = fgetcsv($stream);
        if ($row === false) break;
        $samples[] = $row;
    }
    fclose($stream);

    $body         = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $alreadyMap   = is_array($body['already_mapped'] ?? null) ? $body['already_mapped'] : [];

    $ins = CsvImportService::inspect('placements', $csv);
    try {
        $result = aiSuggestColumnMap([
            'feature_key'    => 'csv.mapping.placements',
            'entity_label'   => 'Placements',
            'schema_fields'  => $ins['fields'],
            'headers'        => $headers,
            'sample_rows'    => $samples,
            'already_mapped' => $alreadyMap,
        ]);
    } catch (AIDisabledException $e) {
        api_error('AI is not enabled for this tenant: ' . $e->getMessage(), 503);
    } catch (\Throwable $e) {
        api_error('AI suggestion failed: ' . $e->getMessage(), 502);
    }
    api_ok($result);
}
if ($method === 'POST' && $action === 'dry_run') {
    rbac_legacy_require($user, 'placements.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $result = CsvImportService::dryRun('placements', $csv, $columnMap);

    // Person lookup — `person_id` is the preferred identifier (numeric
    // PK from People directory, no typo / hidden-whitespace surface).
    // Falls back to case-insensitive email match for legacy CSVs. At
    // least one of the two is required per row.
    if ($result['rows']) {
        $pdo = getDB();
        // CRITICAL: resolve the lookup tenant via the *people* module
        // scope, not the placements scope (which is what the URL would
        // route us to). People rows are `shared` by default — a sub-
        // tenant's directory shows the parent's people, and IdBadge
        // copies the parent-tenant id. If we look up under the raw
        // sub-tenant id, every row misses with "not found in this
        // tenant's People" even though the operator copied a real id.
        $tid = effectiveTenantIdForModule('people') ?? currentTenantId();

        // Collect both lookup keys in one pass.
        $idsWanted    = [];
        $emailsWanted = [];
        foreach ($result['rows'] as $rn => $r) {
            $pid = isset($r['person_id']) && $r['person_id'] !== '' ? (int) $r['person_id'] : 0;
            $em  = placementsCsvNormaliseEmail((string) ($r['person_email'] ?? ''));
            if ($pid > 0)       $idsWanted[]    = $pid;
            elseif ($em !== '') $emailsWanted[] = $em;
        }

        $foundById = [];
        if ($idsWanted) {
            $placeholders = implode(',', array_fill(0, count($idsWanted), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, LOWER(email_primary) AS e FROM people
                  WHERE tenant_id = ? AND deleted_at IS NULL
                    AND id IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$tid], $idsWanted));
            foreach ($stmt as $r) $foundById[(int) $r['id']] = (string) ($r['e'] ?? '');
        }

        $foundByEmail = [];
        if ($emailsWanted) {
            $placeholders = implode(',', array_fill(0, count($emailsWanted), '?'));
            // DEFENSIVE: normalise BOTH sides of the equality. The CSV
            // cell goes through placementsCsvNormaliseEmail() (strips
            // Unicode whitespace + lowercases). The stored DB value may
            // ALSO carry the same junk from a prior import — TRIM +
            // LOWER on email_primary so a trailing NBSP in the people
            // row doesn't silently miss every lookup. The leading TRIM
            // also catches BOM bytes some Excel-roundtripped imports
            // wrote into the column.
            $stmt = $pdo->prepare(
                "SELECT id, LOWER(TRIM(email_primary)) AS e FROM people
                  WHERE tenant_id = ? AND deleted_at IS NULL
                    AND LOWER(TRIM(email_primary)) IN ({$placeholders})"
            );
            $stmt->execute(array_merge([$tid], $emailsWanted));
            foreach ($stmt as $r) $foundByEmail[(string) $r['e']] = (int) $r['id'];
        }

        // "Did you mean?" — only kicks in when neither id nor email
        // matched. Cheaper than the legacy email-only path because
        // person_id misses don't need a fuzzy search.
        $directoryCache = null;
        $loadDirectory = static function () use (&$directoryCache, $pdo, $tid) {
            if ($directoryCache !== null) return $directoryCache;
            $st = $pdo->prepare(
                'SELECT LOWER(email_primary) AS e FROM people
                  WHERE tenant_id = ? AND deleted_at IS NULL AND email_primary IS NOT NULL
                  LIMIT 5000'
            );
            $st->execute([$tid]);
            $directoryCache = array_column($st->fetchAll(\PDO::FETCH_ASSOC), 'e');
            return $directoryCache;
        };
        $suggestFor = static function (string $needle) use ($loadDirectory): array {
            if ($needle === '') return [];
            $candidates = $loadDirectory();
            if (!$candidates) return [];
            $scored = [];
            foreach ($candidates as $cand) {
                if (strlen($cand) > 255 || strlen($needle) > 255) continue;
                $d = levenshtein($needle, $cand);
                if ($d <= 3) $scored[$cand] = $d;
            }
            asort($scored);
            return array_slice(array_keys($scored), 0, 3);
        };

        foreach ($result['rows'] as $rn => $row) {
            $hasPidCol  = array_key_exists('person_id', $row)
                       && $row['person_id'] !== ''
                       && $row['person_id'] !== null;
            $pid        = $hasPidCol && is_int($row['person_id'])
                       ? (int) $row['person_id'] : 0;
            $pidInvalid = $hasPidCol && !is_int($row['person_id']);
            $rawEm      = (string) ($row['person_email'] ?? '');
            $em         = placementsCsvNormaliseEmail($rawEm);

            if (!$hasPidCol && $em === '') {
                $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                $result['errors'][$rn][] = 'either person_id or person_email is required';
                continue;
            }

            // If person_id was provided but rejected at validation time
            // (eg. "P-foo"), the CsvImportService already attached an
            // explicit "not an integer" error. Don't ALSO complain about
            // the email — the row already shows the precise problem.
            if ($pidInvalid) {
                continue;
            }

            if ($pid > 0) {
                if (!isset($foundById[$pid])) {
                    $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                    $result['errors'][$rn][] = "person_id: {$pid} not found in this tenant's People — open /modules/people/{$pid} to verify, or click the P-badge on a real row to copy the right id.";
                }
                // person_id wins → email is informational only. Skip
                // email validation so a stale legacy email column doesn't
                // poison an otherwise-valid id row.
                continue;
            }

            // Fallback: email-only lookup with fuzzy suggestion.
            if (!isset($foundByEmail[$em])) {
                $msg = "person_email: '{$rawEm}' not found in this tenant's People";
                $suggestions = $suggestFor($em);
                if ($suggestions) {
                    $msg .= ' — did you mean: ' . implode(', ', $suggestions)
                          . '? (Tip: paste the person_id column from the People directory to skip the email lookup entirely.)';
                } else {
                    // No close match — most likely the person hasn't
                    // been imported yet. Point at the people importer
                    // explicitly so the operator stops fighting this
                    // page.
                    $msg .= ' — no close match found. Import the person first via /modules/people/import, or add a person_id column referencing an existing row.';
                }
                $result['errors'][$rn] = $result['errors'][$rn] ?? [];
                $result['errors'][$rn][] = $msg;
            }
        }
        $result['error_count'] = count($result['errors']);
    }
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    rbac_legacy_require($user, 'placements.manage');
    rbac_legacy_require($user, 'placements.financials.manage');
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap      = CsvImportService::readRequestColumnMap();
    $skipInvalid    = !empty($_GET['skip_invalid']);
    $updateExisting = !empty($_GET['update_existing']);

    $result = CsvImportService::commit('placements', $csv, function (array $row) use ($user, $updateExisting) {
        // Resolve person — id wins over email. Same precedence as
        // dry_run: the operator's choice of person_id is honoured even
        // if the email column is also present (the email might be
        // stale legacy data).
        $hasPidCol  = array_key_exists('person_id', $row)
                   && $row['person_id'] !== ''
                   && $row['person_id'] !== null;
        $pid        = $hasPidCol && is_int($row['person_id']) ? (int) $row['person_id'] : 0;
        if ($hasPidCol && !is_int($row['person_id'])) {
            // CsvImportService already attached an explicit "not an integer"
            // error at dry-run time; re-raise the same message here so
            // commit doesn't silently fall through to an email lookup.
            throw new \RuntimeException(
                "person_id: not an integer '{$row['person_id']}' (accepted: 1042 or P-1042)"
            );
        }
        if ($pid > 0) {
            // Look up the person under the *people* module scope (shared
            // → parent for sub-tenants). The raw scopedFind here would
            // resolve via the URL → 'placements' scope, which usually
            // also points at the parent but isn't guaranteed: a tenant
            // can override placements to 'isolated'. Bind the tenant
            // explicitly so person lookups are always correct.
            $peopleTid = effectiveTenantIdForModule('people') ?? currentTenantId();
            $stmt = getDB()->prepare(
                'SELECT id FROM people WHERE tenant_id = :tenant_id AND id = :pid AND deleted_at IS NULL'
            );
            $stmt->execute(['tenant_id' => $peopleTid, 'pid' => $pid]);
            $person = $stmt->fetch();
            if (!$person) throw new \RuntimeException("person_id not found: {$pid}");
        } else {
            $emClean = placementsCsvNormaliseEmail((string) ($row['person_email'] ?? ''));
            if ($emClean === '') {
                throw new \RuntimeException('either person_id or person_email is required');
            }
            // Mirror dry_run defensive DB-side normalisation — TRIM the
            // stored email so a row with a trailing NBSP from an older
            // import still matches. Same people-scope rationale as above.
            $peopleTid = effectiveTenantIdForModule('people') ?? currentTenantId();
            $stmt = getDB()->prepare(
                'SELECT id FROM people WHERE tenant_id = :tenant_id AND LOWER(TRIM(email_primary)) = :email AND deleted_at IS NULL'
            );
            $stmt->execute(['tenant_id' => $peopleTid, 'email' => $emClean]);
            $person = $stmt->fetch();
            if (!$person) throw new \RuntimeException("person_email not found: {$row['person_email']}");
        }

        // Update-existing mode lookup order:
        //   1. placement_id (numeric PK — most reliable, no ambiguity)
        //   2. external_id  (tenant-unique upstream identifier)
        //   3. (person_id + title + start_date) composite
        $existing = null;
        if ($updateExisting) {
            if (!empty($row['placement_id'])) {
                $existing = scopedFind(
                    'SELECT id FROM placements WHERE tenant_id = :tenant_id AND id = :pid AND deleted_at IS NULL',
                    ['pid' => (int) $row['placement_id']]
                );
                if (!$existing) {
                    throw new \RuntimeException("placement_id not found: {$row['placement_id']}");
                }
            }
            if (!$existing && !empty($row['external_id'])) {
                $existing = scopedFind(
                    'SELECT id FROM placements WHERE tenant_id = :tenant_id AND external_id = :x AND deleted_at IS NULL',
                    ['x' => $row['external_id']]
                );
            }
            if (!$existing) {
                $existing = scopedFind(
                    'SELECT id FROM placements
                      WHERE tenant_id = :tenant_id AND person_id = :p AND title = :t AND start_date = :s
                            AND deleted_at IS NULL',
                    ['p' => (int) $person['id'], 't' => $row['title'], 's' => $row['start_date']]
                );
            }
        }

        $payload = [
            'person_id'        => (int) $person['id'],
            'external_id'      => $row['external_id']     ?? null,
            'start_date'       => $row['start_date'],
            'end_date'         => $row['end_date']        ?? null,
            'actual_end_date'  => $row['actual_end_date'] ?? null,
            'due_date'         => $row['due_date']        ?? null,
            'engagement_type'  => $row['engagement_type'],
            'worksite_state'   => $row['worksite_state']  ?? null,
            'worksite_country' => $row['worksite_country']?? null,
            'remote_policy'    => placementsNormalizeRemotePolicy($row['remote_policy'] ?? null),
            'title'            => $row['title'],
            'end_client_name'  => $row['end_client_name'] ?? null,
            'end_client_company_id' => !empty($row['end_client_company_id']) ? (int) $row['end_client_company_id'] : null,
            'client_approver_name'  => $row['client_approver_name']  ?? null,
            'client_approver_email' => $row['client_approver_email'] ?? null,
            'jobdiva_job_id'        => $row['jobdiva_job_id']        ?? null,
            'recruiter_name'        => $row['recruiter_name']        ?? null,
            'recruiter_email'       => $row['recruiter_email']       ?? null,
            'account_manager_name'  => $row['account_manager_name']  ?? null,
            'account_manager_email' => $row['account_manager_email'] ?? null,
            'client_bill_cycle'     => $row['client_bill_cycle']     ?? null,
            'client_bill_cycle_anchor' => $row['client_bill_cycle_anchor'] ?? null,
            'vendor_pay_cycle'      => $row['vendor_pay_cycle']      ?? null,
            'vendor_pay_cycle_anchor' => $row['vendor_pay_cycle_anchor'] ?? null,
            'notes'            => $row['notes']           ?? null,
        ];
        $payload = array_filter($payload, static fn($v): bool => $v !== null && $v !== '');

        if (!empty($payload['end_client_company_id'])) {
            $co = companiesGet((int) $payload['end_client_company_id']);
            if ($co) {
                $payload['end_client_name'] = $co['name'];
                companiesAddRole((int) $co['id'], 'client');
                companiesBumpUsage((int) $co['id']);
            }
        } elseif (!empty($payload['end_client_name'])) {
            $cid = companiesUpsertByName(currentTenantId(), (string) $payload['end_client_name'], [
                'created_by_user_id' => $user['id'] ?? null,
            ], ['client']);
            $payload['end_client_company_id'] = $cid;
            companiesBumpUsage($cid);
        }
        if (!empty($payload['end_client_company_id']) || !empty($payload['end_client_name'])) {
            $clientRef = staffingClientEnsureForCompany(
                currentTenantId(),
                !empty($payload['end_client_company_id']) ? (int) $payload['end_client_company_id'] : null,
                (string) ($payload['end_client_name'] ?? ''),
                ['created_by_user_id' => $user['id'] ?? null]
            );
            $payload['client_id'] = $clientRef['client_id'];
            $payload['end_client_company_id'] = $clientRef['company_id'] ?: ($payload['end_client_company_id'] ?? null);
            $payload['end_client_name'] = $clientRef['name'];
        }

        if ($existing) {
            scopedUpdate('placements', (int) $existing['id'], $payload);
            $pid = (int) $existing['id'];
        } else {
            $payload['status']             = 'draft';
            $payload['created_by_user_id'] = $user['id'] ?? null;
            $pid = scopedInsert('placements', $payload);
        }

        // First rate row (drafted, not approved — approval is a deliberate human step).
        // Existing approved snapshots stay locked; imports write drafts.
        placementsCsvUpsertDraftRate($pid, $row, $user['id'] ?? null, (bool) $existing);

        // End client + vendor tiers are canonical placement_client_chain rows.
        placementsCsvUpsertChain($pid, array_merge($row, [
            'end_client_name' => $payload['end_client_name'] ?? ($row['end_client_name'] ?? null),
        ]), $user['id'] ?? null);

        return $pid;
    }, ['skip_invalid' => $skipInvalid, 'column_map' => $columnMap]);

    placementsAudit('placement.csv_imported', [
        'imported'        => $result['imported_count'],
        'skipped'         => $result['skipped_count'],
        'update_existing' => $updateExisting,
    ]);
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|dry_run|commit', 400);
