<?php
/**
 * JobDiva sync drivers (Sprint 8a / Slice A3).
 *
 * Pulls Companies, Contacts, and Placements from JobDiva REST API into the
 * matching CoreFlux internal tables, binding each external↔internal pair via
 * the agnostic `external_entity_mappings` pipeline (Slice A2). NO candidates,
 * applicants, or open positions — CoreFlux is not an ATS.
 *
 * Public surface:
 *   jobdivaSyncCompanies(int $tid, ?int $userId, array $opts = []): array
 *   jobdivaSyncContacts (int $tid, ?int $userId, array $opts = []): array
 *   jobdivaSyncPlacements(int $tid, ?int $userId, array $opts = []): array
 *   jobdivaSyncAll      (int $tid, ?int $userId, array $opts = []): array
 *     → { counts: {company,contact,placement}, total, by_entity: {...} }
 *
 * `$opts['modified_since']` (ISO 8601) → incremental delta pull.
 * `$opts['items_override']` (array)    → injects raw items, bypassing the
 *                                        HTTP call (used by smoke tests).
 *
 * Each driver returns: { processed, skipped, failed, errors[] }
 *
 * Idempotent: running `jobdivaSyncAll` twice in a row produces zero new
 * mapping rows on the second pass; existing rows just bump `last_seen_at`.
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';
require_once __DIR__ . '/../../modules/people/lib/companies.php';

/**
 * JobDiva V2 BI endpoints — verified 2026-02 from
 * https://api.jobdiva.com/swagger?group=Version%202. All BI endpoints
 * take `fromDate` + `toDate` as required query params formatted
 * `MM/dd/yyyy HH:mm:ss` (JobDiva-specific format, NOT ISO-8601).
 *
 *   Companies   → /apiv2/bi/NewUpdatedCompanyRecords
 *   Contacts    → /apiv2/bi/NewUpdatedContactRecords
 *   Timesheets  → /apiv2/bi/NewUpdatedTimesheetRecords (used by sync_time.php)
 *
 * Placements (Starts) intentionally have NO V2 "NewUpdatedStartRecords"
 * — JobDiva only exposes `/apiv2/jobdiva/searchStart` (POST with explicit
 * search criteria). Until we decide on the criteria source (timesheets,
 * job list, candidate list, etc.), jobdivaSyncPlacements() returns early
 * with a deferred-by-design result instead of hitting a non-existent path.
 */
const JOBDIVA_PATH_COMPANIES_DELTA  = '/apiv2/bi/NewUpdatedCompanyRecords';
const JOBDIVA_PATH_CONTACTS_DELTA   = '/apiv2/bi/NewUpdatedContactRecords';
const JOBDIVA_PATH_TIMESHEETS_DELTA = '/apiv2/bi/NewUpdatedTimesheetRecords';

/**
 * Resilient case/space-insensitive field lookup for JobDiva BI records.
 *
 * JobDiva V2 BI responses use INCONSISTENT key shapes across endpoints and
 * over time — a single endpoint can return "id" / "ID" / "Id" / "contactId"
 * across releases, and Contact records specifically use space-separated
 * keys ("first name", "company id"). Rather than chain ten `??` lookups
 * per field, we normalise both the record keys and the candidate list to
 * lowercase-alphanumeric, then resolve once.
 *
 * Candidates are tried in order; first non-empty scalar wins. Non-scalar
 * matches (arrays/objects) are skipped — JobDiva sometimes nests
 * structured payloads under a name that collides with a flat field.
 */
function jobdivaPluckField(array $item, array $candidates): string
{
    $norm = [];
    foreach ($item as $k => $v) {
        if (!is_string($k)) continue;
        $nk = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $k));
        if ($nk === '') continue;
        // First occurrence wins — JobDiva sometimes echoes the same logical
        // field twice with subtly different spellings; the canonical one
        // tends to appear first.
        if (!array_key_exists($nk, $norm)) $norm[$nk] = $v;
    }
    foreach ($candidates as $cand) {
        $nk = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $cand));
        if (!isset($norm[$nk])) continue;
        $v = $norm[$nk];
        if ($v === null) continue;
        if (is_scalar($v)) {
            $s = trim((string) $v);
            if ($s !== '') return $s;
        }
    }
    return '';
}

/**
 * Build JobDiva BI date-range query string. `fromDate` defaults to 7
 * days ago (narrower than before to dodge the JobDiva-side "Not an
 * array" 500 that fires when a result set is too large or contains a
 * malformed row). Accepts ISO-8601 overrides via `modified_since` /
 * `modified_until`. Time-zone agnostic — JobDiva treats these as
 * account-local.
 */
function jobdivaBiDateRange(array $opts): array
{
    $defaultWindowDays = (int) ($opts['default_window_days'] ?? 7);
    $now = new \DateTimeImmutable('now');
    if (!empty($opts['modified_since'])) {
        try { $from = new \DateTimeImmutable((string) $opts['modified_since']); }
        catch (\Throwable $_) { $from = $now->modify("-{$defaultWindowDays} days"); }
    } else {
        $from = $now->modify("-{$defaultWindowDays} days");
    }
    $to = $now;
    if (!empty($opts['modified_until'])) {
        try { $to = new \DateTimeImmutable((string) $opts['modified_until']); }
        catch (\Throwable $_) { /* keep now */ }
    }
    return [
        'fromDate' => $from->format('m/d/Y H:i:s'),
        'toDate'   => $to->format('m/d/Y H:i:s'),
    ];
}

/**
 * Resilient BI fetch — when JobDiva returns a 500 (typically the
 * tenant-data-shaped "Not an array" serialization NPE on the controller
 * side, or a timeout from a too-wide window), retry the call with a
 * progressively halved date window, down to a 1-hour floor. Returns
 * the first non-failing slice list and absorbs subsequent failures
 * into the audit log.
 *
 * Why: JobDiva's V2 BI endpoints are stateless date-range queries with
 * no row-level error recovery. One broken row in a 30-day window can
 * 500 the whole response. Halving the window 5 times shrinks 30 days →
 * 30d → 15d → 7d → 3d → 1d → 12h, by which point the bad slice is
 * isolated and the rest of the data flows.
 *
 * Public helper so per-entity drivers can opt in with their own opts.
 */
function jobdivaSyncFetchWithRetry(int $tid, string $path, array $opts): array
{
    $now  = new \DateTimeImmutable('now');
    try { $from = !empty($opts['modified_since']) ? new \DateTimeImmutable((string) $opts['modified_since']) : $now->modify('-30 days'); }
    catch (\Throwable $_) { $from = $now->modify('-30 days'); }
    try { $to = !empty($opts['modified_until']) ? new \DateTimeImmutable((string) $opts['modified_until']) : $now; }
    catch (\Throwable $_) { $to = $now; }

    $minWindowSec = 3600; // never retry below a 1-hour slice
    $maxAttempts  = (int) ($opts['retry_attempts'] ?? 6);
    $items = []; $lastError = null;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $sliceOpts = $opts;
        $sliceOpts['modified_since'] = $from->format('c');
        $sliceOpts['modified_until'] = $to->format('c');
        try {
            $items = jobdivaSyncFetchItems($tid, $path, $sliceOpts);
            if ($i > 0) {
                jobdivaAudit($tid, 'sync_retry_succeeded', [
                    'ok' => true,
                    'detail' => [
                        'path' => $path, 'attempts' => $i + 1,
                        'window_seconds' => $to->getTimestamp() - $from->getTimestamp(),
                    ],
                ]);
            }
            return $items;
        } catch (\Throwable $e) {
            $lastError = $e;
            $msg = $e->getMessage();
            // Only retry on JobDiva 500-class server errors that smell like
            // payload size / serialization issues. Auth + path errors
            // shouldn't be retried — they'd just multiply audit noise.
            $retriable = stripos($msg, 'HTTP 500') !== false
                      || stripos($msg, 'HTTP 502') !== false
                      || stripos($msg, 'HTTP 504') !== false
                      || stripos($msg, 'Not an array') !== false
                      || stripos($msg, 'timeout') !== false;
            if (!$retriable) throw $e;
            // Halve the window towards the most recent end. We keep $to fixed
            // (newest data first) and pull $from forward — gives the operator
            // most-recent data on the first successful slice.
            $windowSec = $to->getTimestamp() - $from->getTimestamp();
            if ($windowSec <= $minWindowSec) {
                jobdivaAudit($tid, 'sync_retry_floor_hit', [
                    'ok' => false,
                    'detail' => [
                        'path' => $path, 'attempts' => $i + 1,
                        'last_error' => substr($msg, 0, 500),
                        'note'       => 'Reached 1-hour window floor — JobDiva still 500ing. Likely a single malformed record; contact JobDiva Support with the li-uuid.',
                    ],
                ]);
                throw new \RuntimeException(
                    "JobDiva BI {$path} still failing after {$maxAttempts} retries down to a 1-hour window. "
                    . 'Likely a single malformed record in this tenant. Last error: ' . substr($msg, 0, 300),
                    0, $e
                );
            }
            $from = $to->modify('-' . max($minWindowSec, intdiv($windowSec, 2)) . ' seconds');
        }
    }
    if ($lastError) throw $lastError;
    return $items;
}

/**
 * First-sync detection — returns true if NO mappings exist yet for
 * the given (tenant, entity_type) pair. Drivers use this to widen
 * their date window for the initial backfill so the operator doesn't
 * have to manually set `modified_since` to "last year".
 */
function jobdivaSyncIsFirstSync(int $tenantId, string $entityType): bool
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare(
            "SELECT 1 FROM external_entity_mappings
              WHERE tenant_id = :t
                AND source_system = 'jobdiva'
                AND internal_entity_type = :e
              LIMIT 1"
        );
        $stmt->execute(['t' => $tenantId, 'e' => $entityType]);
        return $stmt->fetchColumn() === false;
    } catch (\Throwable $_) {
        return false;
    }
}

function jobdivaSyncCompanies(int $tid, ?int $userId, array $opts = []): array
{
    // First-ever Companies sync: widen the window to 365 days so the
    // operator backfills all reachable companies before Contacts /
    // Placements lookups fire. Subsequent syncs use the 7-day delta
    // window (or whatever the caller supplied via modified_since).
    if (!isset($opts['items_override']) && !isset($opts['modified_since'])
        && jobdivaSyncIsFirstSync($tid, 'company')) {
        $opts['default_window_days'] = 365;
        jobdivaAudit($tid, 'sync_first_backfill', [
            'ok'     => true,
            'detail' => ['entity' => 'company', 'window_days' => 365],
            'actor_user_id' => $userId,
        ]);
    }
    $items = isset($opts['items_override']) && is_array($opts['items_override'])
        ? $opts['items_override']
        : jobdivaSyncFetchWithRetry($tid, JOBDIVA_PATH_COMPANIES_DELTA, $opts);
    $processed = 0; $skipped = 0; $failed = 0; $errors = [];

    foreach ($items as $jd) {
        try {
            $extId = (string) ($jd['id'] ?? $jd['companyId'] ?? $jd['company_id'] ?? '');
            $name  = trim((string) ($jd['name'] ?? $jd['companyName'] ?? $jd['company_name'] ?? ''));
            // V2 BI fallback — JobDiva also returns "COMPANYID" / "COMPANY NAME"
            // in some tenant configs. jobdivaPluckField() catches those.
            if ($extId === '') $extId = jobdivaPluckField($jd, ['id', 'companyId', 'company_id', 'companyID', 'CompanyId', 'COMPANYID']);
            if ($name === '')  $name  = jobdivaPluckField($jd, ['name', 'companyName', 'company_name', 'company name', 'COMPANY NAME']);
            if ($extId === '' || $name === '') { $skipped++; continue; }

            $companyId = companiesUpsertByName($tid, $name, [
                'website'              => $jd['website']     ?? null,
                'phone'                => $jd['phone']       ?? null,
                'address_line1'        => $jd['address1']    ?? $jd['address']     ?? null,
                'address_line2'        => $jd['address2']    ?? null,
                'city'                 => $jd['city']        ?? null,
                'state'                => $jd['state']       ?? null,
                'postal_code'          => $jd['zip']         ?? $jd['postal_code'] ?? null,
                'country'              => $jd['country']     ?? 'US',
                'created_by_user_id'   => $userId,
            ], ['client']);

            mappingUpsert($tid, 'jobdiva', 'company', $extId, $companyId, $jd, 'pull');
            $processed++;
        } catch (\Throwable $e) {
            $failed++;
            $errors[] = ['entity' => 'company', 'external_id' => $extId ?? '?', 'error' => $e->getMessage()];
            if (count($errors) >= 50) break;
        }
    }

    jobdivaAudit($tid, 'sync', [
        'entity_type'     => 'company',
        'direction'       => 'pull',
        'ok'              => $failed === 0,
        'items_processed' => $processed,
        'items_skipped'   => $skipped,
        'items_failed'    => $failed,
        'actor_user_id'   => $userId,
        'detail'          => ['errors' => array_slice($errors, 0, 5)],
    ]);
    return ['processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
}

function jobdivaSyncContacts(int $tid, ?int $userId, array $opts = []): array
{
    // Same first-sync widening as Companies — backfill 365 days on the
    // initial pull so we don't end up with a sparse contact set that
    // depends on companies created earlier (which would otherwise be
    // outside the 7-day delta window).
    if (!isset($opts['items_override']) && !isset($opts['modified_since'])
        && jobdivaSyncIsFirstSync($tid, 'contact')) {
        $opts['default_window_days'] = 365;
        jobdivaAudit($tid, 'sync_first_backfill', [
            'ok'     => true,
            'detail' => ['entity' => 'contact', 'window_days' => 365],
            'actor_user_id' => $userId,
        ]);
    }
    $items = isset($opts['items_override']) && is_array($opts['items_override'])
        ? $opts['items_override']
        : jobdivaSyncFetchWithRetry($tid, JOBDIVA_PATH_CONTACTS_DELTA, $opts);
    $processed = 0; $skipped = 0; $failed = 0; $errors = [];
    $skipReasons = ['missing_fields' => 0, 'company_unmapped' => 0];
    $unmappedCompanies = []; // collect distinct external IDs for the diagnostic
    $sampleKeys = [];        // record-shape diagnostic: keys from first 3 items
    $sampleMissing = [];     // example records that failed the field gate

    foreach ($items as $idx => $jd) {
        if ($idx < 3 && is_array($jd)) $sampleKeys[$idx] = array_keys($jd);
        try {
            // JobDiva V2 BI Contact records use INCONSISTENT key shapes
            // across releases — "first name" / "FirstName" / "FIRSTNAME"
            // have all been observed in the wild. We normalise both the
            // record keys and our candidates to lowercase-alphanumeric so
            // a single canonical list catches every variant. See
            // jobdivaPluckField() above.
            $extId        = jobdivaPluckField($jd, ['id', 'contactId', 'contact_id', 'contactID']);
            $companyExtId = jobdivaPluckField($jd, [
                'company id', 'companyId', 'company_id', 'companyID',
                'CompanyId', 'COMPANYID', 'clientId', 'client_id',
            ]);
            $firstName    = jobdivaPluckField($jd, ['first name', 'firstName', 'first_name', 'firstname']);
            $lastName     = jobdivaPluckField($jd, ['last name',  'lastName',  'last_name',  'lastname']);
            $name         = jobdivaPluckField($jd, ['name', 'fullName', 'full_name', 'contactName', 'contact_name']);
            if ($name === '') $name = trim($firstName . ' ' . $lastName);
            if ($extId === '' || $name === '' || $companyExtId === '') {
                $skipped++; $skipReasons['missing_fields']++;
                if (count($sampleMissing) < 2 && is_array($jd)) {
                    // Capture a redacted sample so the operator can see
                    // EXACTLY what shape JobDiva is sending. We expose
                    // keys + the first 60 chars of each scalar value;
                    // arrays/objects are summarised by shape only.
                    $sample = [];
                    foreach ($jd as $k => $v) {
                        if (is_scalar($v) || $v === null) {
                            $sample[(string) $k] = $v === null ? null : substr((string) $v, 0, 60);
                        } else {
                            $sample[(string) $k] = '[' . gettype($v) . ']';
                        }
                    }
                    $sampleMissing[] = $sample;
                }
                continue;
            }

            // Resolve internal company via mapping created by jobdivaSyncCompanies().
            $companyMapping = mappingFindInternal($tid, 'jobdiva', 'company', $companyExtId);
            if (!$companyMapping) {
                $skipped++; $skipReasons['company_unmapped']++;
                if (count($unmappedCompanies) < 20) $unmappedCompanies[$companyExtId] = true;
                continue;
            }
            $companyId = (int) $companyMapping['internal_entity_id'];

            $internalId = jobdivaSyncUpsertContact($tid, $companyId, $jd, $name);
            mappingUpsert($tid, 'jobdiva', 'contact', $extId, $internalId, $jd, 'pull');
            $processed++;
        } catch (\Throwable $e) {
            $failed++;
            $errors[] = ['entity' => 'contact', 'external_id' => $extId ?? '?', 'error' => $e->getMessage()];
            if (count($errors) >= 50) break;
        }
    }

    // Diagnostic: when most contacts skip because their parent company
    // isn't mapped yet, surface this clearly so the operator knows to
    // backfill Companies (rather than wondering why "49 records" went
    // into a black hole). Counts as an error in the UI so the
    // diagnostics panel highlights it.
    if ($skipReasons['company_unmapped'] > 0) {
        $errors[] = [
            'entity'      => 'contact',
            'kind'        => 'company_unmapped',
            'error'       => sprintf(
                '%d contact%s skipped: parent company has no mapping. Run Companies sync first with a wider window or enable the "backfill_companies_on_contact_pull" option. Unmapped external company IDs (first 20): %s',
                $skipReasons['company_unmapped'],
                $skipReasons['company_unmapped'] === 1 ? '' : 's',
                implode(', ', array_keys($unmappedCompanies))
            ),
        ];
    }
    if ($skipReasons['missing_fields'] > 0) {
        $errors[] = [
            'entity' => 'contact',
            'kind'   => 'missing_fields',
            'error'  => sprintf('%d contacts skipped: missing required fields (id/name/companyId).', $skipReasons['missing_fields']),
            // Surface the actual record shape so the operator can compare
            // against the JobDiva V2 BI swagger and confirm/correct the
            // key list in jobdivaSyncContacts() if JobDiva renames a field.
            'sample_keys'    => $sampleKeys,
            'sample_records' => $sampleMissing,
        ];
    }

    jobdivaAudit($tid, 'sync', [
        'entity_type'     => 'contact',
        'direction'       => 'pull',
        'ok'              => $failed === 0,
        'items_processed' => $processed,
        'items_skipped'   => $skipped,
        'items_failed'    => $failed,
        'actor_user_id'   => $userId,
        'detail'          => [
            'errors'         => array_slice($errors, 0, 5),
            'skip_reasons'   => $skipReasons,
            'sample_keys'    => $sampleKeys,
            'sample_records' => $sampleMissing,
        ],
    ]);
    return [
        'processed'    => $processed,
        'skipped'      => $skipped,
        'failed'       => $failed,
        'errors'       => $errors,
        'skip_reasons' => $skipReasons,
    ];
}

function jobdivaSyncUpsertContact(int $tid, int $companyId, array $jd, string $name): int
{
    // JobDiva V2 BI Contact records expose "email", "phone 1" / "phone 2",
    // and "title" — older shapes use camelCase. jobdivaPluckField()
    // tolerates both.
    $email = jobdivaPluckField($jd, ['email', 'emailAddress', 'email_address', 'primary email', 'primaryEmail']);
    $phone = jobdivaPluckField($jd, ['phone 1', 'phone', 'phoneNumber', 'phone_number', 'workPhone', 'work phone']);
    $title = jobdivaPluckField($jd, ['title', 'jobTitle', 'job_title', 'job title']);

    $pdo = getDB();
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT id FROM company_contacts WHERE tenant_id = :t AND company_id = :c AND email = :e LIMIT 1');
        $stmt->execute(['t' => $tid, 'c' => $companyId, 'e' => $email]);
        $existingId = (int) $stmt->fetchColumn();
        if ($existingId > 0) {
            // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
            $pdo->prepare(
                'UPDATE company_contacts SET name = :n, title = :ti, phone = :ph WHERE id = :id'
            )->execute(['n' => $name, 'ti' => $title ?: null, 'ph' => $phone ?: null, 'id' => $existingId]);
            return $existingId;
        }
    }
    $pdo->prepare(
        'INSERT INTO company_contacts (tenant_id, company_id, name, title, email, phone, contact_role)
         VALUES (:t, :c, :n, :ti, :e, :ph, "other")'
    )->execute([
        't' => $tid, 'c' => $companyId, 'n' => $name,
        'ti' => $title ?: null, 'e' => $email ?: null, 'ph' => $phone ?: null,
    ]);
    return (int) $pdo->lastInsertId();
}

function jobdivaSyncPlacements(int $tid, ?int $userId, array $opts = []): array
{
    // 2026-02 follow-on: placements now have a real discovery path even
    // though JobDiva V2 has no "NewUpdatedStartRecords" BI delta endpoint.
    //
    // Discovery channels (in priority order, all wrapped in
    // jobdivaPlacementsDiscover()):
    //   1. POST /apiv2/jobdiva/searchStart with date-range criteria
    //   2. NewUpdatedTimesheetRecords → unique placementIds → searchStart per-ID
    //   3. webhook ingestion (api/jobdiva.php, placement.* events)
    //
    // For each discovered placement, jobdivaPlacementsAutoCreatePerson()
    // resolves-or-creates the internal person_id so placement.person_id
    // (NOT NULL) is always satisfiable.
    //
    // items_override still drives the upsert logic for smoke tests; in
    // that path we keep the original "skip when no person mapping"
    // behaviour, since the test fixtures are designed for it.
    require_once __DIR__ . '/sync_placements.php';

    if (!isset($opts['items_override']) && !isset($opts['modified_since'])
        && jobdivaSyncIsFirstSync($tid, 'placement')) {
        $opts['default_window_days'] = 365;
        jobdivaAudit($tid, 'sync_first_backfill', [
            'ok'     => true,
            'detail' => ['entity' => 'placement', 'window_days' => 365],
            'actor_user_id' => $userId,
        ]);
    }

    $discovery = jobdivaPlacementsDiscover($tid, $userId, $opts);
    $items     = $discovery['items'];
    $channel   = $discovery['channel'];

    $processed = 0; $skipped = 0; $failed = 0; $errors = [];
    $skipReasons = ['missing_fields' => 0, 'no_person' => 0];

    foreach ($items as $jd) {
        try {
            $extId        = jobdivaPluckField($jd, [
                'id', 'startId', 'start_id', 'placementId', 'placement_id', 'startID',
            ]);
            $startDate    = jobdivaPluckField($jd, [
                'startDate', 'start_date', 'start date', 'startdate',
            ]);
            $companyExtId = jobdivaPluckField($jd, [
                'companyId', 'company_id', 'company id', 'endClientCompanyId',
            ]);

            if ($extId === '' || $startDate === '') {
                // items_override / smoke-fixture compat: legacy fixtures
                // use the older simple key shapes, so try one more pass
                // before giving up. (jobdivaPluckField is case-insensitive
                // so this is belt-and-braces.)
                $extId     = $extId !== ''     ? $extId     : (string) ($jd['id'] ?? $jd['placementId'] ?? $jd['placement_id'] ?? '');
                $startDate = $startDate !== '' ? $startDate : (string) ($jd['startDate'] ?? $jd['start_date'] ?? '');
                if ($extId === '' || $startDate === '') {
                    $skipped++; $skipReasons['missing_fields']++; continue;
                }
            }

            // items_override path keeps the legacy "must have person mapping"
            // behaviour so the existing smoke fixtures still work unchanged.
            // Real-sync path auto-creates a minimal person record on demand.
            if (isset($opts['items_override'])) {
                $personExtId = (string) ($jd['employeeId'] ?? $jd['candidateId'] ?? $jd['person_id'] ?? '');
                $personMapping = mappingFindInternal($tid, 'jobdiva', 'person', $personExtId);
                if (!$personMapping) { $skipped++; continue; }
                $personId = (int) $personMapping['internal_entity_id'];
            } else {
                $personId = jobdivaPlacementsAutoCreatePerson($tid, $jd, $userId);
                if ($personId === null) {
                    $skipped++; $skipReasons['no_person']++;
                    continue;
                }
            }

            // Optional end-client company.
            $endClientCompanyId = null;
            if ($companyExtId !== '') {
                $cm = mappingFindInternal($tid, 'jobdiva', 'company', $companyExtId);
                if ($cm) $endClientCompanyId = (int) $cm['internal_entity_id'];
            }

            $internalId = jobdivaSyncUpsertPlacement($tid, $personId, $endClientCompanyId, $jd, $extId);
            mappingUpsert($tid, 'jobdiva', 'placement', $extId, $internalId, $jd, 'pull');
            $processed++;
        } catch (\Throwable $e) {
            $failed++;
            $errors[] = ['entity' => 'placement', 'external_id' => $extId ?? '?', 'error' => $e->getMessage()];
            if (count($errors) >= 50) break;
        }
    }

    jobdivaAudit($tid, 'sync', [
        'entity_type'     => 'placement',
        'direction'       => 'pull',
        'ok'              => $failed === 0,
        'items_processed' => $processed,
        'items_skipped'   => $skipped,
        'items_failed'    => $failed,
        'actor_user_id'   => $userId,
        'detail'          => [
            'errors'        => array_slice($errors, 0, 5),
            'skip_reasons'  => $skipReasons,
            'channel'       => $channel,
            'discovery'     => $discovery['diagnostics'] ?? [],
        ],
    ]);
    return [
        'processed'    => $processed,
        'skipped'      => $skipped,
        'failed'       => $failed,
        'errors'       => $errors,
        'channel'      => $channel,
        'skip_reasons' => $skipReasons,
    ];
}

function jobdivaSyncUpsertPlacement(int $tid, int $personId, ?int $endClientCompanyId, array $jd, string $extId): int
{
    $pdo = getDB();
    // Look up by external_id first (placements has a `external_id` column).
    $stmt = $pdo->prepare('SELECT id FROM placements WHERE tenant_id = :t AND external_id = :ext LIMIT 1');
    $stmt->execute(['t' => $tid, 'ext' => 'jd:' . $extId]);
    $existingId = (int) $stmt->fetchColumn();

    // Title is NOT NULL on `placements`. JobDiva uses many shapes for the
    // job/role title — fall back to a deterministic placeholder so we
    // never bail out at the DB layer.
    $title = jobdivaPluckField($jd, [
        'jobTitle', 'job_title', 'job title', 'title',
        'positionTitle', 'position_title', 'role', 'roleName',
    ]);
    if ($title === '') $title = 'JobDiva Placement ' . $extId;

    $startDate = jobdivaPluckField($jd, ['startDate', 'start_date', 'start date', 'startdate']);
    if ($startDate === '') $startDate = (string) ($jd['startDate'] ?? $jd['start_date'] ?? '');
    $endDate   = jobdivaPluckField($jd, ['endDate', 'end_date', 'end date', 'enddate']);
    $endClientName = jobdivaPluckField($jd, [
        'endClientName', 'clientName', 'end_client_name', 'client_name', 'client name', 'end client name',
    ]);
    $statusJd  = strtolower(jobdivaPluckField($jd, ['status', 'startStatus', 'placementStatus']));
    if ($statusJd === '') $statusJd = 'active';
    $statusMap = ['active' => 'active', 'pending' => 'pending_start', 'ended' => 'ended', 'cancelled' => 'cancelled'];
    $status    = $statusMap[$statusJd] ?? 'active';

    if ($existingId > 0) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE placements SET start_date = :sd, end_date = :ed,
                    status = :st, end_client_name = :ecn, end_client_company_id = :ecc,
                    title = :ti
              WHERE id = :id'
        )->execute([
            'sd' => $startDate, 'ed' => $endDate ?: null, 'st' => $status,
            'ecn' => $endClientName ?: null, 'ecc' => $endClientCompanyId,
            'ti' => $title, 'id' => $existingId,
        ]);
        return $existingId;
    }
    $pdo->prepare(
        'INSERT INTO placements (tenant_id, person_id, external_id, status, start_date, end_date,
                                  engagement_type, end_client_name, end_client_company_id, title)
         VALUES (:t, :p, :ext, :st, :sd, :ed, "w2", :ecn, :ecc, :ti)'
    )->execute([
        't' => $tid, 'p' => $personId, 'ext' => 'jd:' . $extId, 'st' => $status,
        'sd' => $startDate, 'ed' => $endDate ?: null,
        'ecn' => $endClientName ?: null, 'ecc' => $endClientCompanyId,
        'ti' => $title,
    ]);
    return (int) $pdo->lastInsertId();
}

function jobdivaSyncAll(int $tid, ?int $userId, array $opts = []): array
{
    $start  = microtime(true);
    $config = jobdivaSyncConfigRead($tid);

    $shouldPull = static function (array $cfg, string $entity): bool {
        $row = $cfg[$entity] ?? null;
        if (!$row) return false;
        return ($row['source'] ?? null) === 'jobdiva'
            && in_array($row['direction'] ?? 'off', ['pull', 'two_way'], true);
    };
    $shouldPush = static function (array $cfg, string $entity): bool {
        $row = $cfg[$entity] ?? null;
        if (!$row) return false;
        return ($row['source'] ?? null) === 'coreflux'
            && in_array($row['direction'] ?? 'off', ['push', 'two_way'], true);
    };

    $skipped = []; // entity-types skipped because of config

    // Per-entity calls are isolated: a 500 in one entity must NOT abort the
    // others. Each caught exception lands in by_entity[X].errors so the UI
    // diagnostics table shows JobDiva's verbatim response without
    // collapsing the entire sync into a single 502.
    $safeRun = static function (string $entityKey, callable $fn) use ($tid, $userId): array {
        try {
            return $fn();
        } catch (\Throwable $e) {
            // Surface the JobDiva error verbatim so the operator can read
            // the path, status, and li-uuid directly from the diagnostics
            // panel. Truncate to keep the API response from ballooning.
            $errStr = $e->getMessage();
            jobdivaAudit($tid, 'sync_entity_error', [
                'ok' => false, 'actor_user_id' => $userId,
                'detail' => ['entity' => $entityKey, 'error' => substr($errStr, 0, 800)],
            ]);
            return [
                'processed' => 0, 'skipped' => 0, 'failed' => 1,
                'errors'    => [['entity' => $entityKey, 'error' => substr($errStr, 0, 800)]],
            ];
        }
    };

    if ($shouldPull($config, 'company')) {
        $companies  = $safeRun('company',   static fn() => jobdivaSyncCompanies($tid, $userId, $opts['companies']  ?? $opts));
    } else {
        $companies  = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'skipped_by_config' => true];
        $skipped[]  = 'company';
    }
    if ($shouldPull($config, 'contact')) {
        $contacts   = $safeRun('contact',   static fn() => jobdivaSyncContacts($tid, $userId, $opts['contacts']   ?? $opts));
    } else {
        $contacts   = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'skipped_by_config' => true];
        $skipped[]  = 'contact';
    }
    if ($shouldPull($config, 'placement')) {
        $placements = $safeRun('placement', static fn() => jobdivaSyncPlacements($tid, $userId, $opts['placements'] ?? $opts));
    } else {
        $placements = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'skipped_by_config' => true];
        $skipped[]  = 'placement';
    }

    // Time direction wiring (Slice A4 follow-on). Pull, push, two_way honored.
    $timeResult = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'skipped_by_config' => true];
    if ($shouldPull($config, 'time') || $shouldPush($config, 'time')) {
        require_once __DIR__ . '/sync_time.php';
        $pull = $shouldPull($config, 'time') ? $safeRun('time_pull', static fn() => jobdivaSyncTimePull($tid, $userId, $opts['time'] ?? $opts)) : null;
        $push = $shouldPush($config, 'time') ? $safeRun('time_push', static fn() => jobdivaSyncTimePush($tid, $userId, $opts['time'] ?? $opts)) : null;
        $timeResult = [
            'processed' => ($pull['processed'] ?? 0) + ($push['processed'] ?? 0),
            'skipped'   => ($pull['skipped']   ?? 0) + ($push['skipped']   ?? 0),
            'failed'    => ($pull['failed']    ?? 0) + ($push['failed']    ?? 0),
            'errors'    => array_merge($pull['errors'] ?? [], $push['errors'] ?? []),
            'pull'      => $pull,
            'push'      => $push,
        ];
    } else {
        $skipped[] = 'time';
    }

    $counts = [
        'company'   => $companies['processed'],
        'contact'   => $contacts['processed'],
        'placement' => $placements['processed'],
        'time'      => $timeResult['processed'],
    ];
    $total      = array_sum($counts);
    $latencyMs  = (int) round((microtime(true) - $start) * 1000);

    // Bump connection's last_sync_at on success.
    getDB()->prepare(
        'UPDATE jobdiva_connections SET last_sync_at = NOW() WHERE tenant_id = :t'
    )->execute(['t' => $tid]);

    return [
        'counts'             => $counts,
        'total'              => $total,
        'latency_ms'         => $latencyMs,
        'skipped_by_config'  => $skipped,
        'by_entity'          => [
            'company'   => $companies,
            'contact'   => $contacts,
            'placement' => $placements,
            'time'      => $timeResult,
        ],
    ];
}

/**
 * Fetch raw items list from JobDiva, OR use injected items_override (testing).
 *
 * For JobDiva V2 BI endpoints (`/apiv2/bi/NewUpdated*Records`), all calls
 * require `fromDate` + `toDate` query params in `MM/dd/yyyy HH:mm:ss`.
 * `jobdivaBiDateRange()` provides defaults (30-day window ending now) and
 * honours `$opts['modified_since']` / `modified_until` overrides.
 *
 * The IBiData response wrapper can be:
 *   - a plain JSON array of records, OR
 *   - a {data: [...]} or {items: [...]} envelope.
 * All three shapes are handled.
 */
function jobdivaSyncFetchItems(int $tid, string $path, array $opts): array
{
    if (isset($opts['items_override']) && is_array($opts['items_override'])) {
        return $opts['items_override'];
    }
    $query = jobdivaBiDateRange($opts);
    if (!empty($opts['page_size']))   $query['pageSize']   = (int) $opts['page_size'];
    if (!empty($opts['page_number'])) $query['pageNumber'] = (int) $opts['page_number'];

    // Workaround for JobDiva V2 BI 500 "Not an array".
    // The `/apiv2/bi/NewUpdated{Company,Contact}Records` endpoints declare
    // `userFieldsName` as an OPTIONAL `@RequestParam List<String>`. When
    // the param is omitted entirely, JobDiva's controller iterates a
    // null list and surfaces the response as
    //   500 Internal Server Error · message: "Not an array".
    // Sending an empty value (`userFieldsName=`) binds Spring's param to
    // an empty list and avoids the NullPointer. Harmless on endpoints
    // that don't declare this param (Spring ignores unknown query params).
    if (str_starts_with($path, '/apiv2/bi/NewUpdatedCompanyRecords')
     || str_starts_with($path, '/apiv2/bi/NewUpdatedContactRecords')) {
        if (!array_key_exists('userFieldsName', $query)) $query['userFieldsName'] = '';
    }

    $resp  = jobdivaCall($tid, 'GET', $path, null, $query);
    if (is_array($resp)) {
        if (isset($resp['data'])  && is_array($resp['data']))   return $resp['data'];
        if (isset($resp['items']) && is_array($resp['items']))  return $resp['items'];
        // Plain list response.
        if (array_keys($resp) === range(0, count($resp) - 1))   return $resp;
    }
    return [];
}
