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
require_once __DIR__ . '/../integrations/payload_field_index.php';
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
 * Deep variant of jobdivaPluckField — walks the shallow item FIRST,
 * then drills into the enriched sub-objects (`_jd_job`,
 * `_jd_candidate`, `_jd_customer`, `_jd_contact`, `_jd_start`) that
 * jobdivaSyncEnrichRelatedEntities injects, plus a few legacy
 * nested keys JobDiva V2 has used.
 *
 * Why this exists: JobDiva's BI delta endpoints return placements
 * with mostly references (jobId, candidateId, customerId, contactId).
 * The detail records carry the rich payload (candidate's name/email,
 * job's title/description, customer's address, contact's phone). The
 * enricher fetches those detail records and grafts them on as
 * `_jd_*` keys — but every shallow pluck in the syncer used to ignore
 * them, so consumers got mostly-empty placements and had to be
 * back-filled by hand. Routing through this deep variant means the
 * sync uses the joined data the way operators expect.
 *
 * Search order:
 *   1. Shallow pluck on $item itself
 *   2. _jd_candidate (person fields)
 *   3. _jd_job       (job/title/description/dept)
 *   4. _jd_customer  (end-client / company name + address)
 *   5. _jd_contact   (account manager / hiring contact)
 *   6. _jd_start     (full start detail — rates, dates that BI nullified)
 *   7. legacy nest keys (`job`, `Job`, `jobInfo`, `candidate`, `customer`, `contact`)
 *
 * Caller can override the search order via the optional 4th arg.
 */
function jobdivaPluckFieldDeep(
    array $item,
    array $candidates,
    array $nestOrder = ['_jd_candidate', '_jd_job', '_jd_customer', '_jd_contact', '_jd_start',
                       'job', 'Job', 'jobInfo', 'jobObj', 'jobRecord',
                       'candidate', 'Candidate', 'customer', 'Customer', 'contact', 'Contact']
): string {
    // 1. shallow first
    $v = jobdivaPluckField($item, $candidates);
    if ($v !== '') return $v;
    // 2. walk enriched + legacy nests in priority order
    foreach ($nestOrder as $nest) {
        if (!isset($item[$nest]) || !is_array($item[$nest])) continue;
        $v = jobdivaPluckField($item[$nest], $candidates);
        if ($v !== '') return $v;
    }
    return '';
}

/**
 * Normalise a JobDiva date value into MySQL DATE (YYYY-MM-DD) format.
 *
 * JobDiva V2 BI is inconsistent: some endpoints return formatted strings
 * ("2026-05-22", "5/22/2026"), others return raw epoch milliseconds
 * (Java/Spring default JSON serialisation of `java.util.Date`, e.g.
 * `1779231290000`). Passing the latter straight into a prepared
 * statement against a DATE column produces:
 *   SQLSTATE[22007]: Incorrect date value: '1779231290000' for column 'start_date'
 * which silently fails 100% of placement inserts.
 *
 * This helper accepts every shape we've seen in the wild and returns a
 * MySQL-safe `Y-m-d` string, or null when the input is blank/uninterpretable.
 * Numeric thresholds:
 *   - ≥ 10^12 → epoch milliseconds (~year 2001+ in ms)
 *   - ≥ 10^8  → epoch seconds       (~year 1973+ in s, sane lower bound)
 */
function jobdivaNormaliseDate(mixed $raw): ?string
{
    if ($raw === null) return null;
    if (is_scalar($raw)) $raw = trim((string) $raw);
    else return null;
    if ($raw === '' || $raw === '0' || $raw === 'null') return null;

    // Numeric — epoch ms or s. ctype_digit on the trimmed string avoids
    // accepting floats / negatives (JobDiva never sends those for dates).
    if (ctype_digit($raw)) {
        $n = (int) $raw;
        // Java Date.getTime() values are 13 digits since the early 2000s.
        // Below 10^12 we assume epoch seconds (10 digits ≈ 1973+).
        if ($n >= 1_000_000_000_000) {
            $n = (int) ($n / 1000);
        }
        if ($n >= 100_000_000) {
            return gmdate('Y-m-d', $n);
        }
        // Fall through — too small to be a sensible epoch.
        return null;
    }

    // String — try strtotime first (handles ISO-8601, "5/22/2026",
    // "2026-05-22T08:32:43.000+0000", "Wed, 22 May 2026 ...", etc).
    $ts = strtotime($raw);
    if ($ts !== false) return gmdate('Y-m-d', $ts);

    // Last resort — if it already LOOKS like Y-m-d, return as-is so the
    // DB can complain in its own words.
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) return substr($raw, 0, 10);
    return null;
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
            // V2 BI fallback — JobDiva also returns "COMPANYID" / "COMPANY NAME"
            // in some tenant configs. jobdivaPluckField() catches those.
            if ($extId === '') $extId = jobdivaPluckField($jd, ['id', 'companyId', 'company_id', 'companyID', 'CompanyId', 'COMPANYID']);

            // Slice 5 wiring (2026-02): every settable column on `companies`
            // resolves through the tenant integration field-map registry
            // first, with the built-in JobDiva V2 candidate-key list as a
            // safe fallback. Operators can rewire ANY field at runtime
            // (e.g. map JobDiva's `customerName` → name) without code change.
            require_once __DIR__ . '/../integrations/field_map.php';
            $pluck = static function (string $internal, array $candidates) use ($tid, $jd) {
                return (string) tenantIntegrationFieldMapPluckInternal(
                    $tid, 'jobdiva', 'company', $internal, $jd,
                    static fn() => jobdivaPluckField($jd, $candidates)
                );
            };

            $name = trim((string) ($jd['name'] ?? $jd['companyName'] ?? $jd['company_name'] ?? ''));
            $registryName = $pluck('name', ['name', 'companyName', 'company_name', 'company name', 'COMPANY NAME']);
            if ($registryName !== '') $name = $registryName;
            if ($extId === '' || $name === '') { $skipped++; continue; }

            $patch = [
                'website'              => $pluck('website',       ['website', 'url', 'homepage', 'site']) ?: null,
                'phone'                => $pluck('phone',         ['phone', 'phoneNumber', 'phone_number', 'main phone']) ?: null,
                'legal_name'           => $pluck('legal_name',    ['legal_name', 'legalName', 'legal name']) ?: null,
                'duns'                 => $pluck('duns',          ['duns', 'dunsNumber', 'duns_number']) ?: null,
                'ein_last4'            => substr($pluck('ein_last4', ['einLast4', 'ein_last4', 'einLastFour']), -4) ?: null,
                'primary_contact_name' => $pluck('primary_contact_name',  ['primaryContactName',  'primary_contact_name',  'primary contact']) ?: null,
                'primary_contact_email'=> $pluck('primary_contact_email', ['primaryContactEmail', 'primary_contact_email']) ?: null,
                'primary_contact_phone'=> $pluck('primary_contact_phone', ['primaryContactPhone', 'primary_contact_phone']) ?: null,
                'address_line1'        => $pluck('address_line1', ['address1', 'address', 'street1', 'street_address']) ?: null,
                'address_line2'        => $pluck('address_line2', ['address2', 'street2', 'suite']) ?: null,
                'city'                 => $pluck('city',          ['city', 'town', 'locality']) ?: null,
                'state'                => $pluck('state',         ['state', 'region', 'province']) ?: null,
                'postal_code'          => $pluck('postal_code',   ['zip', 'postal_code', 'postalCode', 'postal code', 'zipcode']) ?: null,
                'country'              => $pluck('country',       ['country', 'countryCode', 'country_code']) ?: 'US',
                'notes'                => $pluck('notes',         ['notes', 'note', 'comments', 'comment']) ?: null,
                'msa_signed_at'        => $pluck('msa_signed_at', ['msaSignedAt', 'msa_signed_at', 'msaDate', 'msa_date']) ?: null,
                'created_by_user_id'   => $userId,
            ];

            $companyId = companiesUpsertByName($tid, $name, $patch, ['client']);

            mappingUpsert($tid, 'jobdiva', 'company', $extId, $companyId, $jd, 'pull', $userId);
            // Phase 2 — apply tenant mappings against the BI company payload.
            try {
                require_once __DIR__ . '/../integrations/field_map_apply.php';
                integrationFieldMapApplyAll($tid, 'jobdiva', 'company', $jd, ['self' => $companyId]);
            } catch (\Throwable $e) {
                error_log('[jobdiva company sync] applyAll failed: ' . $e->getMessage());
            }
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
            if (!$companyMapping && !empty($opts['backfill_companies_on_contact_pull'])) {
                // Backfill on-demand: the Companies delta window missed
                // this parent (likely because it hasn't been edited
                // recently). Fetch the single record by id and upsert it
                // before retrying the mapping lookup. Soft-fail — if
                // JobDiva 404s the company, we still fall through to the
                // skip path below so the contact is logged like before.
                try {
                    $resp = jobdivaCall(
                        $tid, 'POST', '/apiv2/jobdiva/searchCustomer',
                        ['customerId' => (int) $companyExtId]
                    );
                    $candidateRows = $resp['body']['data']
                        ?? $resp['body']['items']
                        ?? (is_array($resp['body'] ?? null) && array_keys($resp['body']) === range(0, count($resp['body']) - 1) ? $resp['body'] : null);
                    if (is_array($candidateRows) && !empty($candidateRows) && is_array($candidateRows[0])) {
                        $jdCo = $candidateRows[0];
                        $coName = trim((string) (
                            $jdCo['name']         ?? $jdCo['companyName'] ?? $jdCo['company_name']
                            ?? $jdCo['customerName'] ?? $jdCo['customer_name'] ?? ''
                        ));
                        if ($coName !== '') {
                            $newCoId = companiesUpsertByName($tid, $coName, [
                                'website'            => $jdCo['website'] ?? null,
                                'phone'              => $jdCo['phone']   ?? null,
                                'address_line1'      => $jdCo['address1'] ?? $jdCo['address'] ?? null,
                                'address_line2'      => $jdCo['address2'] ?? null,
                                'city'               => $jdCo['city']    ?? null,
                                'state'              => $jdCo['state']   ?? null,
                                'postal_code'        => $jdCo['zip']     ?? $jdCo['postal_code'] ?? null,
                                'country'            => $jdCo['country'] ?? 'US',
                                'created_by_user_id' => $userId,
                            ], ['client']);
                            mappingUpsert($tid, 'jobdiva', 'company', (string) $companyExtId, $newCoId, $jdCo, 'pull', $userId);
                            $companyMapping = mappingFindInternal($tid, 'jobdiva', 'company', $companyExtId);
                            $skipReasons['backfilled_companies'] = ($skipReasons['backfilled_companies'] ?? 0) + 1;
                        }
                    }
                } catch (\Throwable $bfe) {
                    // Backfill failure is non-fatal — the contact will
                    // just go through the existing skip+diagnostic path
                    // so the operator still sees the underlying problem.
                    error_log("[jobdiva] backfill_companies_on_contact_pull failed for customer={$companyExtId}: " . $bfe->getMessage());
                }
            }
            if (!$companyMapping) {
                $skipped++; $skipReasons['company_unmapped']++;
                if (count($unmappedCompanies) < 20) $unmappedCompanies[$companyExtId] = true;
                continue;
            }
            $companyId = (int) $companyMapping['internal_entity_id'];

            $internalId = jobdivaSyncUpsertContact($tid, $companyId, $jd, $name);
            mappingUpsert($tid, 'jobdiva', 'contact', $extId, $internalId, $jd, 'pull', $userId);
            try {
                require_once __DIR__ . '/../integrations/field_map_apply.php';
                integrationFieldMapApplyAll($tid, 'jobdiva', 'contact', $jd, ['self' => $internalId]);
            } catch (\Throwable $e) {
                error_log('[jobdiva contact sync] applyAll failed: ' . $e->getMessage());
            }
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
                '%d contact%s skipped: parent company has no mapping%s. Run Companies sync first with a wider window or set "backfill_companies_on_contact_pull" to fetch parents on demand. Unmapped external company IDs (first 20): %s',
                $skipReasons['company_unmapped'],
                $skipReasons['company_unmapped'] === 1 ? '' : 's',
                empty($opts['backfill_companies_on_contact_pull'])
                    ? '' : ' (backfill enabled but the company also could not be fetched on demand)',
                implode(', ', array_keys($unmappedCompanies))
            ),
        ];
    }
    if (!empty($skipReasons['backfilled_companies'])) {
        $errors[] = [
            'entity' => 'contact',
            'kind'   => 'companies_backfilled',
            'error'  => sprintf(
                '%d parent company%s auto-fetched via searchCustomer during this contact sync (backfill_companies_on_contact_pull=true). The contact(s) succeeded; no operator action needed.',
                $skipReasons['backfilled_companies'],
                $skipReasons['backfilled_companies'] === 1 ? '' : 'ies'
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
    require_once __DIR__ . '/../integrations/field_map.php';

    // Slice 5 wiring (2026-02) — every settable column on company_contacts
    // resolves through the tenant registry first, with built-in JobDiva
    // V2 candidate-key lists as fallback. Operators can rewire any field
    // (e.g. map JobDiva's `secondaryEmail` → email) without code changes.
    //
    // The caller already built a sensible $name from first+last; we let
    // the registry override that via a 'name', 'first_name', or 'last_name'
    // rule. Empty registry response → keep the caller's value.
    $registryName = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'contact', 'name', $jd,
        static fn() => ''
    );
    if ($registryName === '') {
        $firstOverride = (string) tenantIntegrationFieldMapPluckInternal(
            $tid, 'jobdiva', 'contact', 'first_name', $jd, static fn() => ''
        );
        $lastOverride = (string) tenantIntegrationFieldMapPluckInternal(
            $tid, 'jobdiva', 'contact', 'last_name', $jd, static fn() => ''
        );
        if ($firstOverride !== '' || $lastOverride !== '') {
            $registryName = trim($firstOverride . ' ' . $lastOverride);
        }
    }
    if ($registryName !== '') $name = $registryName;

    $email = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'contact', 'email', $jd,
        static fn() => jobdivaPluckField($jd, [
            'email', 'emailAddress', 'email_address', 'primary email', 'primaryEmail',
        ])
    );
    $phone = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'contact', 'phone', $jd,
        static fn() => jobdivaPluckField($jd, [
            'phone 1', 'phone', 'phoneNumber', 'phone_number', 'workPhone', 'work phone',
        ])
    );
    $title = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'contact', 'title', $jd,
        static fn() => jobdivaPluckField($jd, ['title', 'jobTitle', 'job_title', 'job title'])
    );
    $contactRoleRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'contact', 'contact_role', $jd,
        static fn() => jobdivaPluckField($jd, [
            'role', 'contactRole', 'contact_role', 'contactType', 'contact type',
        ])
    );
    // Coerce free-text role into the ENUM. Anything unrecognised falls back to 'other'.
    $contactRoleMap = [
        'account_mgr' => 'account_mgr', 'account manager' => 'account_mgr', 'am' => 'account_mgr',
        'recruiter' => 'recruiter',
        'ap' => 'ap', 'accounts payable' => 'ap',
        'ar' => 'ar', 'accounts receivable' => 'ar',
        'approver' => 'approver', 'timesheet approver' => 'approver',
        'technical' => 'technical', 'tech' => 'technical',
        'executive' => 'executive', 'exec' => 'executive', 'c-level' => 'executive',
    ];
    $contactRole = $contactRoleMap[strtolower(trim($contactRoleRaw))] ?? 'other';
    $isPrimaryRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'contact', 'is_primary', $jd,
        static fn() => jobdivaPluckField($jd, [
            'isPrimary', 'is_primary', 'primaryContact', 'primary_contact',
        ])
    );
    $isPrimary = in_array(strtolower(trim($isPrimaryRaw)), ['1', 'true', 'yes', 'y'], true) ? 1 : 0;
    $notes = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'contact', 'notes', $jd,
        static fn() => jobdivaPluckField($jd, ['notes', 'note', 'comments', 'comment'])
    );

    // Slice 5b broader-mapping additions (2026-02) — companies-v2
    // company_contacts columns. mobile_phone + linkedin_url + department
    // are common JobDiva ClientContacts payload fields; decision_role +
    // is_active capture sales-cycle context for downstream CRM views.
    $mobilePhone = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'contact', 'mobile_phone', $jd,
        static fn() => jobdivaPluckField($jd, [
            'mobilePhone', 'mobile_phone', 'cellPhone', 'cell_phone', 'mobile', 'cell',
        ])
    );
    $linkedinUrl = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'contact', 'linkedin_url', $jd,
        static fn() => jobdivaPluckField($jd, ['linkedinUrl', 'linkedin_url', 'linkedIn', 'linkedin'])
    );
    $department = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'contact', 'department', $jd,
        static fn() => jobdivaPluckField($jd, ['department', 'dept', 'departmentName'])
    );
    $decisionRoleRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'contact', 'decision_role', $jd,
        static fn() => jobdivaPluckField($jd, [
            'decisionRole', 'decision_role', 'decisionMakerRole', 'buyerRole',
        ])
    );
    $decisionRoleMap = [
        'decision_maker'  => 'decision_maker', 'decision maker' => 'decision_maker',
        'champion'        => 'champion',
        'influencer'      => 'influencer',
        'blocker'         => 'blocker',
        'gatekeeper'      => 'gatekeeper',
    ];
    $decisionRole = $decisionRoleMap[strtolower(trim($decisionRoleRaw))] ?? 'unknown';
    $isActiveRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'contact', 'is_active', $jd,
        static fn() => jobdivaPluckField($jd, ['isActive', 'is_active', 'active', 'status'])
    );
    // 'inactive' / 'disabled' / 'false' / '0' all collapse to 0; anything
    // else (including blank — JobDiva defaults the contact to active) is 1.
    $isActive = in_array(
        strtolower(trim($isActiveRaw)),
        ['0', 'false', 'no', 'n', 'inactive', 'disabled'],
        true
    ) ? 0 : 1;

    $pdo = getDB();
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT id FROM company_contacts WHERE tenant_id = :t AND company_id = :c AND email = :e LIMIT 1');
        $stmt->execute(['t' => $tid, 'c' => $companyId, 'e' => $email]);
        $existingId = (int) $stmt->fetchColumn();
        if ($existingId > 0) {
            // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
            $pdo->prepare(
                'UPDATE company_contacts
                    SET name = :n, title = :ti, phone = :ph,
                        contact_role = :cr, is_primary = :ip,
                        notes = :no,
                        mobile_phone = :mp, linkedin_url = :lu,
                        department = :dp, decision_role = :dr,
                        is_active = :ia
                  WHERE id = :id'
            )->execute([
                'n'  => $name,
                'ti' => $title ?: null,
                'ph' => $phone ?: null,
                'cr' => $contactRole,
                'ip' => $isPrimary,
                'no' => $notes !== '' ? mb_substr($notes, 0, 500) : null,
                'mp' => $mobilePhone ?: null,
                'lu' => $linkedinUrl ?: null,
                'dp' => $department ?: null,
                'dr' => $decisionRole,
                'ia' => $isActive,
                'id' => $existingId,
            ]);
            return $existingId;
        }
    }
    $pdo->prepare(
        'INSERT INTO company_contacts
            (tenant_id, company_id, name, title, email, phone, contact_role, is_primary, notes,
             mobile_phone, linkedin_url, department, decision_role, is_active)
         VALUES
            (:t, :c, :n, :ti, :e, :ph, :cr, :ip, :no,
             :mp, :lu, :dp, :dr, :ia)'
    )->execute([
        't'  => $tid, 'c'  => $companyId, 'n'  => $name,
        'ti' => $title ?: null, 'e'  => $email ?: null, 'ph' => $phone ?: null,
        'cr' => $contactRole, 'ip' => $isPrimary,
        'no' => $notes !== '' ? mb_substr($notes, 0, 500) : null,
        'mp' => $mobilePhone ?: null,
        'lu' => $linkedinUrl ?: null,
        'dp' => $department ?: null,
        'dr' => $decisionRole,
        'ia' => $isActive,
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

    // Enrich every placement item with its job title BEFORE the upsert
    // loop. JobDiva's V2 BI searchStart payload contains `job id` but
    // NOT `job title`; the title lives on the Job record itself. Without
    // this enrichment, every placement falls through to the synthetic
    // "JobDiva Placement {id}" title — observed 2026-02 on Andrew Lee's
    // placement (job id 27857851 → real title "Service Desk Analyst").
    // Resolved titles are injected into each item under
    // `__cf_resolved_job_title` so the existing title pluck chain in
    // jobdivaSyncUpsertPlacement picks them up at no extra cost.
    // The opt-in `enrich_start` flag adds a /apiv2/jobdiva/searchStart
    // detail call per placement to pick up fields (pay rate, etc.) the
    // discovery feed nulls out. Costs one extra API call per placement
    // — leave off unless operators ask for it.
    $items = jobdivaSyncEnrichRelatedEntities($tid, $items, $userId, [
        'enrich_start' => !empty($opts['enrich_start']),
    ]);

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

            // End-client company resolution. JobDiva's V2 BI payload
            // surfaces the end client via TWO key shapes (and the
            // distinction matters for some tenants):
            //
            //   • `companyId`              — JobDiva "company" entity id
            //     (Companies tab in JobDiva). Some tenants use this.
            //   • `customer id` + `customer name`  — JobDiva "customer"
            //     entity id (Customers tab). Most placements observed
            //     in this user's pod use this shape; `companyId` is
            //     absent. The customer IS the end client for staffing
            //     placements — Public Storage in the Andrew Lee example.
            //
            // Resolution chain (first hit wins):
            //   1. Existing `external_entity_mappings` row of kind
            //      'company' for `companyId`.
            //   2. Existing `external_entity_mappings` row of kind
            //      'jobdiva_customer' for `customer id`.
            //   3. NEW — auto-create a CoreFlux `companies` row from
            //      `customer name`, bind the mapping, and use it. This
            //      unblocks the "(no end client)" badge that's been
            //      showing on every JobDiva-synced placement, and lets
            //      the operator merge / rename the company later in the
            //      Companies UI without losing the mapping.
            $endClientCompanyId = null;
            if ($companyExtId !== '') {
                $cm = mappingFindInternal($tid, 'jobdiva', 'company', $companyExtId);
                if ($cm) $endClientCompanyId = (int) $cm['internal_entity_id'];
            }
            if ($endClientCompanyId === null) {
                // Deep pluck — pulls from `_jd_customer` when the placement
                // BI feed nullified the inline customer name (common).
                $customerExtId = jobdivaPluckFieldDeep($jd, [
                    'customerId', 'customer_id', 'customer id', 'clientId', 'client_id',
                ]);
                $customerName  = jobdivaPluckFieldDeep($jd, [
                    'customerName', 'customer_name', 'customer name', 'clientName', 'client_name',
                    // _jd_customer record stores the name as 'name'
                    'name',
                ]);
                if ($customerExtId !== '') {
                    $cm = mappingFindInternal($tid, 'jobdiva', 'jobdiva_customer', $customerExtId);
                    if ($cm) {
                        $endClientCompanyId = (int) $cm['internal_entity_id'];
                    } elseif ($customerName !== '') {
                        $endClientCompanyId = jobdivaResolveOrAutoCreateEndClient(
                            $tid, $customerExtId, $customerName, $userId, $jd
                        );
                    }
                }
            }

            $internalId = jobdivaSyncUpsertPlacement($tid, $personId, $endClientCompanyId, $jd, $extId);
            mappingUpsert($tid, 'jobdiva', 'placement', $extId, $internalId, $jd, 'pull', $userId);

            // Side-effect: index every joined sub-record (_jd_candidate,
            // _jd_job, _jd_customer, _jd_contact, _jd_start) under its OWN
            // entity_type so the Field Mapping Studio offers paths for
            // person / job / jobdiva_customer / contact / assignment after
            // any placement sync. Without this the picker only shows
            // "placement.*" paths; with this it shows real domain entity
            // pickers with sample values populated.
            try {
                jobdivaIndexJoinedSubPayloads($tid, $jd);
            } catch (\Throwable $e) {
                error_log('[jobdiva placement sync] index sub-payloads failed: ' . $e->getMessage());
            }

            // Phase 2 — apply ALL enabled tenant mappings against the
            // enriched payload. Tenant mappings ALWAYS win over the
            // hardcoded sync defaults above (decision (d) from the
            // 2026-02 spec re-audit). The context map tells the apply
            // step which row id to write to for each linked_entity slug.
            try {
                require_once __DIR__ . '/../integrations/field_map_apply.php';
                integrationFieldMapApplyAll($tid, 'jobdiva', 'placement', $jd, [
                    'self'                   => $internalId,
                    'placement_rates'        => $internalId,
                    'placement_corp_details' => $internalId,
                    'person'                 => $personId ?? 0,
                    'end_client_company'     => $endClientCompanyId ?? 0,
                ]);

                // Also apply mappings stored UNDER the joined entity
                // types (person/job/jobdiva_customer/contact/assignment),
                // treating each EXTRACTED sub-record (from nested _jd_*
                // OR flat prefix-keyed fields like candidate_first_name)
                // as the source payload. This is how the Field Mapping
                // Studio's entity-type dropdown becomes useful end-to-end:
                // an operator picks entity_type='person', maps
                // `first_name → people.first_name`, and the placement
                // sync honours it because the extracted candidate sub-
                // record is the active payload.
                static $JOINED_CTX = [
                    'person'           => 'person',
                    'job'              => 'self',
                    'jobdiva_customer' => 'end_client_company',
                    'contact'          => 'self',
                    'assignment'       => 'self',
                ];
                $joinedSubs = jobdivaExtractJoinedSubPayloads($jd);
                foreach ($joinedSubs as $joinedEntity => $subPayload) {
                    if (empty($subPayload)) continue;
                    $selfSlug = $JOINED_CTX[$joinedEntity] ?? 'self';
                    $ctx = [
                        'self'                   => ($selfSlug === 'person')             ? ($personId ?? 0)
                                                  : (($selfSlug === 'end_client_company') ? ($endClientCompanyId ?? 0)
                                                  : $internalId),
                        'person'                 => $personId ?? 0,
                        'end_client_company'     => $endClientCompanyId ?? 0,
                        'placement_rates'        => $internalId,
                        'placement_corp_details' => $internalId,
                    ];
                    try {
                        integrationFieldMapApplyAll($tid, 'jobdiva', $joinedEntity, $subPayload, $ctx);
                    } catch (\Throwable $e) {
                        error_log('[jobdiva placement sync] joined applyAll ' . $joinedEntity . ' failed: ' . $e->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                error_log('[jobdiva placement sync] applyAll failed: ' . $e->getMessage());
            }
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

/**
 * Resolve OR auto-create the CoreFlux companies row that backs a JobDiva
 * customer entity. Binds the resulting (jobdiva_customer, ext_id) →
 * companies.id mapping so subsequent syncs short-circuit.
 *
 * Auto-create path is intentionally minimal — name only, no
 * legal_name / DUNS / EIN. Operators can enrich the company record in
 * the Companies UI; the mapping persists across edits so a later JobDiva
 * resync won't create a duplicate. If a company with the same name
 * already exists for this tenant (case-insensitive), we bind to that
 * one instead of creating a dupe — common when the operator created
 * "Public Storage" manually before the first sync ran.
 *
 * Returns null only when the auto-create itself fails (e.g. DB outage)
 * — in normal operation it always returns a positive integer.
 */
/**
 * Bulk enrichment of placement records with full data from every
 * related JobDiva entity referenced by their FK IDs. Replaces the
 * earlier narrow `jobdivaSyncResolveJobTitles` (which only fetched job
 * titles).
 *
 * Why: JobDiva's V2 BI `searchStart` payload only carries scalar
 * placement attributes + FK ids (`job id`, `candidate id`, `customer id`,
 * `job contact id`). Real source data — job title, pay rate, candidate
 * address, customer billing address, contact email, etc. — lives on
 * the referenced records and requires separate API calls.
 *
 * This helper fetches every related record once per sync run and
 * injects the full result as a nested object on each placement under
 * a `_jd_<kind>` key:
 *
 *   __cf_resolved_job_title  ← legacy convenience (still set, see notes)
 *   _jd_job        ← /apiv2/jobdiva/searchJob       result row
 *   _jd_candidate  ← /apiv2/jobdiva/searchCandidate result row
 *   _jd_customer   ← /apiv2/jobdiva/searchCustomer  result row
 *   _jd_contact    ← /apiv2/jobdiva/searchContact   result row (job contact)
 *   _jd_start      ← /apiv2/jobdiva/searchStart     full detail (with rates)
 *
 * The operator then maps any nested field via the dotted-path syntax
 * supported by tenantIntegrationFieldMapPluckPath, e.g.:
 *   `_jd_candidate.address  → notes`
 *   `_jd_customer.address1  → notes`     (end-client billing addr)
 *   `_jd_job.department     → notes`
 *   `_jd_start.payRate      → pay_rate`  (when BI feed has it null)
 *
 * Endpoints are tried defensively — if a tenant's JobDiva install
 * doesn't expose one (404 / 400), the sync continues without that
 * enrichment for the rest of the batch. Cache is in-memory per run.
 */
function jobdivaSyncEnrichRelatedEntities(int $tid, array $items, ?int $userId, array $opts = [], ?array &$diagnostics = null): array
{
    // (kind, id-pluck-keys, endpoint, id-body-key, inject-key, broken-flag).
    // broken-flag is set to true on the first 4xx so subsequent items
    // skip the endpoint — avoids hammering JobDiva with calls that
    // can't possibly succeed on this tenant's configuration.
    $configs = [
        'job'       => [
            'ids' => ['job id', 'jobId', 'job_id', 'jobID'],
            'endpoint' => '/apiv2/jobdiva/searchJob',
            'body_key' => 'jobId',
            'inject'   => '_jd_job',
        ],
        'candidate' => [
            'ids' => ['candidate id', 'candidateId', 'candidate_id', 'employeeId'],
            'endpoint' => '/apiv2/jobdiva/searchCandidate',
            'body_key' => 'candidateId',
            'inject'   => '_jd_candidate',
        ],
        'customer'  => [
            'ids' => ['customer id', 'customerId', 'customer_id', 'clientId'],
            'endpoint' => '/apiv2/jobdiva/searchCustomer',
            'body_key' => 'customerId',
            'inject'   => '_jd_customer',
        ],
        'contact'   => [
            'ids' => ['job contact id', 'jobContactId', 'contactId'],
            'endpoint' => '/apiv2/jobdiva/searchContact',
            'body_key' => 'contactId',
            'inject'   => '_jd_contact',
        ],
        'start'     => [
            'ids' => ['id', 'startId', 'start_id', 'placementId'],
            'endpoint' => '/apiv2/jobdiva/searchStart',
            'body_key' => 'startId',
            'inject'   => '_jd_start',
        ],
    ];

    // Phase 1 — collect unique non-zero IDs per kind across the batch.
    $idsByKind = [];
    foreach ($configs as $kind => $cfg) {
        $idsByKind[$kind] = [];
        foreach ($items as $jd) {
            $raw = jobdivaPluckField($jd, $cfg['ids']);
            if ($raw === '' || !ctype_digit($raw) || (int) $raw <= 0) continue;
            $idsByKind[$kind][(int) $raw] = true;
        }
    }

    // Phase 2 — fetch each unique id once. Soft-fail per id; mark the
    // endpoint broken on first 4xx so we don't keep hammering it.
    $cache = [];       // [kind][id] => row (assoc array) | null on miss
    $brokenEndpoint = [];
    // Per-kind diagnostics — surfaced to operators via the API so they
    // can see exactly which JobDiva endpoints worked vs returned 4xx /
    // empty data vs were skipped.
    $diag = [];
    foreach (array_keys($configs) as $k) {
        $diag[$k] = [
            'endpoint'        => $configs[$k]['endpoint'],
            'ids_seen'        => 0,
            'attempted'       => 0,
            'succeeded'       => 0,
            'empty_response'  => 0,
            'failed'          => 0,
            'broken_endpoint' => false,
            'sample_error'    => null,
            'skipped_self'    => 0,
        ];
        $diag[$k]['ids_seen'] = count($idsByKind[$k] ?? []);
    }
    foreach ($configs as $kind => $cfg) {
        if (empty($idsByKind[$kind])) continue;
        foreach (array_keys($idsByKind[$kind]) as $id) {
            // Don't re-call the start endpoint when the id matches the
            // own row's id (we already HAVE the searchStart payload —
            // it IS this row's payload). This avoids a 1:1 fan-out of
            // useless API calls for the most common pattern. Operators
            // who need a fuller searchStart (e.g. to pick up pay rate
            // that JobDiva's BI feed nulls out on the Assignment
            // payload) can flip `enrich_start=1` in the sync opts.
            if ($kind === 'start' && empty($opts['enrich_start'])) {
                $diag[$kind]['skipped_self']++;
                continue;
            }

            if (!empty($brokenEndpoint[$cfg['endpoint']])) {
                $cache[$kind][$id] = null;
                continue;
            }
            $diag[$kind]['attempted']++;
            try {
                $resp = jobdivaCall($tid, 'POST', $cfg['endpoint'], [$cfg['body_key'] => $id]);
                $body = $resp['body'] ?? [];
                $rows = $body['data'] ?? $body['items'] ?? (is_array($body) && isset($body[0]) ? $body : []);
                if (is_array($rows) && count($rows) > 0 && is_array($rows[0])) {
                    $cache[$kind][$id] = $rows[0];
                    $diag[$kind]['succeeded']++;
                } else {
                    $cache[$kind][$id] = null;
                    $diag[$kind]['empty_response']++;
                }
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                error_log("[jobdiva] enrich {$kind} id={$id} failed: {$msg}");
                $diag[$kind]['failed']++;
                if ($diag[$kind]['sample_error'] === null) {
                    $diag[$kind]['sample_error'] = substr($msg, 0, 240);
                }
                // Heuristic: HTTP 4xx in the error message = endpoint
                // unavailable on this tenant. Skip the rest of this kind.
                if (preg_match('/\b4\d\d\b/', $msg)) {
                    $brokenEndpoint[$cfg['endpoint']] = true;
                    $diag[$kind]['broken_endpoint'] = true;
                }
                $cache[$kind][$id] = null;
            }
        }
    }
    if ($diagnostics !== null) $diagnostics = $diag;

    // Phase 3 — inject enriched rows back onto each placement item.
    // Also set the legacy `__cf_resolved_job_title` hint so existing
    // title pluck logic keeps working without changes.
    foreach ($items as &$jd) {
        foreach ($configs as $kind => $cfg) {
            $raw = jobdivaPluckField($jd, $cfg['ids']);
            if ($raw === '' || !ctype_digit($raw)) continue;
            $id = (int) $raw;
            if (!isset($cache[$kind][$id]) || $cache[$kind][$id] === null) continue;
            $jd[$cfg['inject']] = $cache[$kind][$id];
        }
        // Legacy convenience field for the title pluck chain.
        if (isset($jd['_jd_job']) && is_array($jd['_jd_job'])) {
            $title = jobdivaPluckField($jd['_jd_job'], [
                'title', 'jobTitle', 'job_title', 'job title',
                'positionTitle', 'position_title', 'roleName',
                'name', 'jobName',
            ]);
            if ($title !== '') $jd['__cf_resolved_job_title'] = $title;
        }
    }
    unset($jd);

    return $items;
}

/**
 * Legacy thin wrapper — `jobdivaSyncResolveJobTitles` was the original
 * narrow job-title-only resolver. Tests and existing callers still
 * reference the name; routing through the general enricher means they
 * also get candidate/customer/contact enrichment for free.
 */
function jobdivaSyncResolveJobTitles(int $tid, array $items, ?int $userId): array
{
    return jobdivaSyncEnrichRelatedEntities($tid, $items, $userId, []);
}

/**
 * Extract every joined sub-record out of a JobDiva placement payload —
 * BOTH from nested `_jd_*` enrichment objects AND from flat prefix-keyed
 * fields that the BI feed already carries (`job_id`, `candidate_first_name`,
 * `customer_address1`, etc.).
 *
 * Why both: JobDiva's V2 BI placement payload is already populated with
 * `job_*` / `candidate_*` / `customer_*` flat fields about the joined
 * entities. The optional `_jd_*` enrichment fans them out as nested
 * objects when the per-entity REST endpoints are reachable, but those
 * endpoints often 404 on a tenant's install. By extracting from both
 * sources we make the Field Mapping Studio see joined-entity fields
 * EVEN WITHOUT the optional enrichment endpoints.
 *
 * Returns an associative array keyed by CoreFlux entity_type:
 *   [
 *     'person'           => [first_name=>..., last_name=>..., email=>...],
 *     'job'              => [id=>..., title=>..., contact_id=>..., dept=>...],
 *     'jobdiva_customer' => [id=>..., name=>..., address1=>..., city=>...],
 *     'contact'          => [id=>..., name=>..., email=>...],
 *     'assignment'       => [pay_rate=>..., bill_rate=>..., start_date=>...],
 *   ]
 *
 * Buckets with zero extracted fields are omitted entirely so callers can
 * loop over the result without empty-check noise.
 *
 * Conservative prefix list — we only fan-out keys that are UNAMBIGUOUSLY
 * about a joined entity. Placement-level fields that happen to MENTION
 * a joined entity (e.g. `pay_rate` belongs to the placement, not the
 * job) stay under entity_type=placement where they belong.
 */
function jobdivaExtractJoinedSubPayloads(array $enriched): array
{
    $out = [
        'person'           => [],
        'job'              => [],
        'jobdiva_customer' => [],
        'contact'          => [],
        'assignment'       => [],
    ];

    // 1) Nested _jd_* enrichment objects (when available).
    static $NESTED_MAP = [
        '_jd_candidate' => 'person',
        '_jd_job'       => 'job',
        '_jd_customer'  => 'jobdiva_customer',
        '_jd_contact'   => 'contact',
        '_jd_start'     => 'assignment',
    ];
    foreach ($NESTED_MAP as $nestKey => $entityType) {
        if (isset($enriched[$nestKey]) && is_array($enriched[$nestKey]) && !empty($enriched[$nestKey])) {
            // Merge nested-record fields into the bucket. The nested record
            // is authoritative when both sources have the same key.
            $out[$entityType] = $enriched[$nestKey] + $out[$entityType];
        }
    }

    // 2) Flat prefix extraction — handles snake_case (job_id, candidate_first_name)
    //    AND camelCase (jobId, candidateFirstName, customerName). The matching
    //    list is intentionally narrow — only well-known joined-entity prefixes
    //    that JobDiva BI uses.
    static $PREFIX_MAP = [
        // prefix (lowercase) → CoreFlux entity_type
        'candidate' => 'person',
        'employee'  => 'person',
        'job'       => 'job',
        'customer'  => 'jobdiva_customer',
        // 'contact' is deliberately omitted from flat extraction — JobDiva
        // uses `job_contact_*` which we want under entity_type='job' (the
        // hiring contact is an attribute of the job, not a separate entity
        // at the BI level).
        // 'start' is deliberately omitted — `start_date` etc. are placement-
        // level attributes, not assignment-record attributes.
    ];

    foreach ($enriched as $k => $v) {
        if (!is_string($k)) continue;
        // Skip nested objects (handled above) + skip CoreFlux-internal keys.
        if (is_array($v)) continue;
        if (str_starts_with($k, '_jd_')) continue;
        if (str_starts_with($k, '__')) continue;

        foreach ($PREFIX_MAP as $prefix => $entityType) {
            $stripped = null;
            // snake_case: candidate_first_name → first_name
            if (str_starts_with($k, $prefix . '_')) {
                $stripped = substr($k, strlen($prefix) + 1);
            }
            // camelCase: candidateFirstName → firstName
            elseif (str_starts_with($k, $prefix)
                    && strlen($k) > strlen($prefix)
                    && ctype_upper($k[strlen($prefix)])) {
                $tail = substr($k, strlen($prefix));
                $stripped = lcfirst($tail);
            }
            if ($stripped !== null && $stripped !== '') {
                // Don't overwrite an existing value (nested _jd_* wins).
                if (!array_key_exists($stripped, $out[$entityType])) {
                    $out[$entityType][$stripped] = $v;
                }
                break; // matched one prefix; don't try others.
            }
        }
    }

    // Drop empty buckets — callers shouldn't care about them.
    return array_filter($out, fn($sub) => !empty($sub));
}

/**
 * Index every joined sub-record of an enriched placement payload UNDER
 * ITS OWN entity_type so the Field Mapping Studio surfaces paths for
 * `person`, `job`, `jobdiva_customer`, `contact`, `assignment` even
 * without separate top-level JobDiva endpoints for those entities.
 *
 * Side-effect only — never throws. Failures are logged + swallowed
 * because the indexer is best-effort.
 */
function jobdivaIndexJoinedSubPayloads(int $tenantId, array $enrichedPayload): void
{
    if ($tenantId <= 0) return;
    $subs = jobdivaExtractJoinedSubPayloads($enrichedPayload);
    foreach ($subs as $entityType => $subPayload) {
        if (empty($subPayload)) continue;
        try {
            integrationPayloadFieldIndexRecord($tenantId, 'jobdiva', $entityType, $subPayload);
        } catch (\Throwable $e) {
            error_log('[jobdivaIndexJoinedSubPayloads] ' . $entityType . ' index failed: ' . $e->getMessage());
        }
    }
}

/**
 * One-shot backfill: walk every existing placement `payload_snapshot`
 * already stored in external_entity_mappings for this tenant, extract
 * the joined sub-records, and index them under their own entity_types.
 *
 * Critical for operators on existing tenants: prior placement syncs
 * stored full payloads but only indexed them under entity_type=placement.
 * Without this backfill, the operator would have to trigger a brand
 * new JobDiva sync just to populate the picker for person / job /
 * customer / contact / assignment.
 *
 * Idempotent: re-running just bumps `occurrence_count` on existing
 * indexed paths (via integrationPayloadFieldIndexRecord's UPSERT).
 *
 * @return array{placements_walked:int, sub_records_indexed:array<string,int>}
 */
function jobdivaBackfillJoinedIndexes(int $tenantId): array
{
    $summary = [
        'placements_walked'   => 0,
        'sub_records_indexed' => [
            'person'           => 0,
            'job'              => 0,
            'jobdiva_customer' => 0,
            'contact'          => 0,
            'assignment'       => 0,
        ],
    ];
    if ($tenantId <= 0) return $summary;

    try {
        $pdo = getDB();
    } catch (\Throwable $e) {
        error_log('[jobdivaBackfillJoinedIndexes] no_db: ' . $e->getMessage());
        return $summary;
    }

    try {
        $st = $pdo->prepare(
            "SELECT id, payload_snapshot
               FROM external_entity_mappings
              WHERE tenant_id = :t
                AND source_system = 'jobdiva'
                AND internal_entity_type = 'placement'
                AND payload_snapshot IS NOT NULL"
        );
        $st->execute(['t' => $tenantId]);
    } catch (\Throwable $e) {
        error_log('[jobdivaBackfillJoinedIndexes] query failed: ' . $e->getMessage());
        return $summary;
    }

    // Phase 1 — collect every stored placement payload paired with its
    // mapping row id, so we can re-save the enriched payload back.
    $placements = [];   // [ ['mapping_id'=>..., 'payload'=>[...] ], ... ]
    while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
        $snap = $row['payload_snapshot'];
        if (!is_string($snap) || $snap === '') continue;
        $payload = json_decode($snap, true);
        if (!is_array($payload)) continue;
        $placements[] = ['mapping_id' => (int) $row['id'], 'payload' => $payload];
    }
    if (empty($placements)) return $summary;

    // Phase 2 — enrich any placements that don't already carry the full
    // joined sub-records. The enricher batches by unique id per kind,
    // marks 4xx endpoints broken after the first miss, and short-circuits
    // when nothing's missing. This is the step that pulls the FULL job /
    // candidate / customer / contact rows out of JobDiva so the picker
    // surfaces complete schemas instead of just flat ref-number stubs.
    $needsEnrichment = array_filter($placements, function ($p) {
        $jd = $p['payload'];
        return empty($jd['_jd_job']) || empty($jd['_jd_candidate'])
            || empty($jd['_jd_customer']);
    });
    $enrichmentRanFor = 0;
    $enrichmentBroken = [];
    $enrichDiag = null;
    if (!empty($needsEnrichment)) {
        try {
            $items = array_map(fn($p) => $p['payload'], $needsEnrichment);
            $enrichDiag = null;
            // enrich_start=1 is CRITICAL — without it the searchStart
            // endpoint is skipped (the optimistic "we already have it"
            // path), and _jd_start stays empty. Without _jd_start the
            // assignment entity has no rates / billing / pay / overhead
            // / VMS / division fields. Operator's literal ask: "get all
            // the fields so I can populate a screen like this!" (the
            // JobDiva Assignment edit screen). Flipping this on adds
            // ONE call per placement during backfill — acceptable.
            $enriched = jobdivaSyncEnrichRelatedEntities(
                $tenantId, $items, null,
                ['enrich_start' => 1],
                $enrichDiag
            );
            // Re-stitch enriched results back into $placements by index +
            // re-save the enriched payload to external_entity_mappings so
            // the next backfill / sync sees the full record.
            $i = 0;
            foreach ($needsEnrichment as $idx => $p) {
                if (!isset($enriched[$i])) { $i++; continue; }
                $newPayload = $enriched[$i];
                if (is_array($newPayload)) {
                    $placements[$idx]['payload'] = $newPayload;
                    // Persist enriched payload back so this is one-shot.
                    try {
                        $up = $pdo->prepare(
                            'UPDATE external_entity_mappings
                                SET payload_snapshot = :p, updated_at = NOW()
                              WHERE id = :id AND tenant_id = :t'
                        );
                        $up->execute([
                            'p'  => json_encode($newPayload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                            'id' => $p['mapping_id'],
                            't'  => $tenantId,
                        ]);
                    } catch (\Throwable $e) {
                        error_log('[jobdivaBackfillJoinedIndexes] re-save failed: ' . $e->getMessage());
                    }
                    $enrichmentRanFor++;
                }
                $i++;
            }
        } catch (\Throwable $e) {
            error_log('[jobdivaBackfillJoinedIndexes] enrich failed: ' . $e->getMessage());
            $enrichmentBroken[] = $e->getMessage();
        }
    }
    // Surface per-endpoint diagnostics from the enricher (if it ran).
    if (!empty($enrichDiag) && is_array($enrichDiag)) {
        $summary['endpoint_diagnostics'] = $enrichDiag;
    }

    // Phase 3 — extract + index every placement's joined sub-records
    // (now with any freshly-fetched _jd_* enrichment applied).
    foreach ($placements as $p) {
        $payload = $p['payload'];
        $summary['placements_walked']++;
        $subs = jobdivaExtractJoinedSubPayloads($payload);
        foreach ($subs as $entityType => $sub) {
            if (empty($sub)) continue;
            try {
                $n = integrationPayloadFieldIndexRecord($tenantId, 'jobdiva', $entityType, $sub);
                if ($n > 0 && isset($summary['sub_records_indexed'][$entityType])) {
                    $summary['sub_records_indexed'][$entityType]++;
                }
            } catch (\Throwable $e) {
                error_log('[jobdivaBackfillJoinedIndexes] index ' . $entityType . ': ' . $e->getMessage());
            }
        }
    }
    $summary['enrichment_ran_for'] = $enrichmentRanFor;
    $summary['enrichment_errors']  = $enrichmentBroken;
    return $summary;
}



function jobdivaResolveOrAutoCreateEndClient(
    int $tid,
    string $customerExtId,
    string $customerName,
    ?int $userId,
    array $payload
): ?int {
    $pdo = getDB();
    $customerName = trim($customerName);
    if ($customerName === '') return null;

    // Existing company with this name? Bind to it instead of duping.
    $stmt = $pdo->prepare(
        'SELECT id FROM companies
          WHERE tenant_id = :t AND LOWER(name) = LOWER(:n) AND deleted_at IS NULL
          LIMIT 1'
    );
    $stmt->execute(['t' => $tid, 'n' => $customerName]);
    $existingId = (int) $stmt->fetchColumn();

    if ($existingId > 0) {
        mappingUpsert($tid, 'jobdiva', 'jobdiva_customer', $customerExtId, $existingId, $payload, 'pull', $userId);
        return $existingId;
    }

    // Auto-create.
    $pdo->prepare(
        'INSERT INTO companies (tenant_id, name) VALUES (:t, :n)'
    )->execute(['t' => $tid, 'n' => $customerName]);
    $newId = (int) $pdo->lastInsertId();
    mappingUpsert($tid, 'jobdiva', 'jobdiva_customer', $customerExtId, $newId, $payload, 'pull', $userId);
    return $newId;
}

function jobdivaSyncUpsertPlacement(int $tid, int $personId, ?int $endClientCompanyId, array $jd, string $extId): int
{
    require_once __DIR__ . '/../integrations/field_map.php';
    $pdo = getDB();
    // Look up by external_id first (placements has a `external_id` column).
    $stmt = $pdo->prepare('SELECT id FROM placements WHERE tenant_id = :t AND external_id = :ext LIMIT 1');
    $stmt->execute(['t' => $tid, 'ext' => 'jd:' . $extId]);
    $existingId = (int) $stmt->fetchColumn();

    // Resolve each placement field via the tenant field-map registry,
    // falling back to the built-in candidate-key lookups when the
    // tenant hasn't configured an override. This is how Slice 4 wires
    // the per-tenant "payload field → CoreFlux column" registry into
    // the syncer — see /app/core/integrations/field_map.php.
    //
    // Title is NOT NULL on `placements`. JobDiva uses many shapes for
    // the job/role title — fall back to a deterministic placeholder
    // so we never bail out at the DB layer.
    $title = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'title', $jd,
        static function () use ($jd) {
            // Highest priority: the resolved job title injected by
            // jobdivaSyncResolveJobTitles() (so we use the real JobDiva
            // Job record's title instead of falling through to the
            // synthetic placeholder when the Assignment payload doesn't
            // carry one inline).
            if (!empty($jd['__cf_resolved_job_title'])) {
                return (string) $jd['__cf_resolved_job_title'];
            }
            // Deep pluck — walks shallow + `_jd_job` + legacy `job`/`Job`/etc.
            // so the title comes from the enriched Job record when the
            // placement BI feed left it null.
            return jobdivaPluckFieldDeep($jd, [
                'jobTitle', 'job_title', 'job title', 'title',
                'positionTitle', 'position_title', 'role', 'roleName',
            ]);
        }
    );
    // Last-resort placeholder. Kept distinct from the JobDiva ID so
    // operators can tell which placements had no Job Title available
    // (vs. genuinely synthetic ones). The Connected Sources panel
    // shows the actual JobDiva Start/Job IDs separately.
    if ($title === '') $title = 'JobDiva Placement ' . $extId;

    $startDate = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'start_date', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, ['startDate', 'start_date', 'start date', 'startdate'])
    );
    if ($startDate === '') $startDate = (string) ($jd['startDate'] ?? $jd['start_date'] ?? '');
    $endDate = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'end_date', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, ['endDate', 'end_date', 'end date', 'enddate'])
    );
    // JobDiva V2 BI returns dates as epoch-milliseconds in many envelopes;
    // normalise to MySQL DATE (Y-m-d) so the prepared statement doesn't
    // 22007 the whole batch. (If the registry already specified
    // 'date_normalise' as the transform, this is idempotent.)
    $startDate = jobdivaNormaliseDate($startDate) ?? '';
    $endDateNorm = jobdivaNormaliseDate($endDate);   // may be null — column is nullable

    $endClientName = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'end_client_name', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'endClientName', 'clientName', 'end_client_name', 'client_name', 'client name', 'end client name',
            // _jd_customer carries the customer record — name lives there as 'name' / 'customerName'.
            'customerName', 'customer name', 'name',
        ])
    );
    $statusRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'status', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, ['status', 'startStatus', 'placementStatus'])
    );
    $statusJd  = strtolower($statusRaw);
    if ($statusJd === '') $statusJd = 'active';
    $statusMap = ['active' => 'active', 'pending' => 'pending_start', 'ended' => 'ended', 'cancelled' => 'cancelled'];
    $status    = $statusMap[$statusJd] ?? 'active';

    // -----------------------------------------------------------------
    // Slice 4 expansion (2026-02): resolve every additional same-table
    // placement column the allow-list now exposes. Each call falls
    // through to a sensible JobDiva default-key list when the tenant
    // hasn't configured an override — so the syncer never silently
    // wipes a value that the registry didn't redirect.
    //
    // ENUM/boolean coercion happens AFTER resolution because tenants
    // who map e.g. JobDiva's `engagementType` -> CoreFlux `engagement_type`
    // may have free-text upstream values that need normalising.
    // -----------------------------------------------------------------
    $engagementRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'engagement_type', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'engagementType', 'engagement_type', 'workerType',
            'worker_type', 'classification', 'employmentType',
        ])
    );
    $engagementMap = [
        'w2' => 'w2', '1099' => '1099', 'c2c' => 'c2c',
        'corp-to-corp' => 'c2c', 'corp_to_corp' => 'c2c',
        'temp_to_perm' => 'temp_to_perm', 'temp-to-perm' => 'temp_to_perm',
        'direct_hire' => 'direct_hire', 'direct-hire' => 'direct_hire',
        'perm' => 'direct_hire',
    ];
    $engagement = $engagementMap[strtolower(trim($engagementRaw))] ?? 'w2';

    $worksiteState = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'worksite_state', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'worksiteState', 'worksite_state', 'state', 'workSiteState', 'jobState', 'job_state',
        ])
    );
    $worksiteCountry = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'worksite_country', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'worksiteCountry', 'worksite_country', 'country', 'jobCountry', 'job_country',
        ])
    );
    // worksite_country is CHAR(2) — coerce to ISO-2 if user mapped a name.
    if (strlen($worksiteCountry) > 2) {
        $worksiteCountry = strtoupper(substr($worksiteCountry, 0, 2));
    } else {
        $worksiteCountry = strtoupper($worksiteCountry);
    }
    if ($worksiteCountry === '') $worksiteCountry = null;

    $remoteRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'remote_policy', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'remotePolicy', 'remote_policy', 'workLocation', 'work_location', 'jobLocationType',
        ])
    );
    $remoteMap = [
        'onsite' => 'onsite', 'on-site' => 'onsite', 'on_site' => 'onsite',
        'hybrid' => 'hybrid',
        'remote' => 'remote', 'work_from_home' => 'remote', 'wfh' => 'remote',
    ];
    $remote = $remoteMap[strtolower(trim($remoteRaw))] ?? null;

    $notes = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'notes', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, ['notes', 'placementNotes', 'placement_notes'])
    );
    $approverName = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'client_approver_name', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'approverName', 'approver_name', 'clientApprover', 'client_approver', 'clientContactName',
            // _jd_contact carries the hiring contact — name lives there.
            'fullName', 'full_name', 'contactName', 'contact_name',
        ])
    );
    $approverEmail = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'client_approver_email', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'approverEmail', 'approver_email', 'clientApproverEmail', 'client_approver_email', 'clientContactEmail',
            // _jd_contact carries the hiring contact's email.
            'email', 'emailAddress',
        ])
    );
    // Slice 5b additions — capture JobDiva metadata we previously dropped.
    // jobdiva_job_id is the JobDiva *Job* entity ID (the role/req the
    // assignment was filled against), distinct from the assignment ID
    // which we already store in external_id. Useful for cross-linking
    // back to the Job record and rebooking detection.
    $jobdivaJobId = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'jobdiva_job_id', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, ['jobId', 'job_id', 'jobID', 'JOBID', 'reqId', 'req_id'])
    );
    $recruiterName = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'recruiter_name', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'recruiterName', 'recruiter_name', 'recruiter', 'recruiterFullName', 'primaryRecruiter',
        ])
    );
    $recruiterEmail = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'recruiter_email', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, ['recruiterEmail', 'recruiter_email'])
    );
    $accountManagerName = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'account_manager_name', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'accountManager', 'account_manager', 'accountManagerName', 'salesperson', 'salesPerson',
        ])
    );
    $accountManagerEmail = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'account_manager_email', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'accountManagerEmail', 'account_manager_email', 'salesPersonEmail', 'salespersonEmail',
        ])
    );
    $actualEndRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'actual_end_date', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, ['actualEndDate', 'actual_end_date', 'actualEnd'])
    );
    $actualEnd = jobdivaNormaliseDate($actualEndRaw);
    $dueDateRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'due_date', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, ['dueDate', 'due_date'])
    );
    $dueDate = jobdivaNormaliseDate($dueDateRaw);

    // Slice 5b — broader-mapping additions (2026-02). Billing + pay
    // cadence usually live in JobDiva's Assignment screen as
    // `billCycle` / `payCycle`; pulling them here means the operator
    // doesn't have to re-pick them in CoreFlux. ENUM coercion is
    // necessary because JobDiva uses free-text like "Bi-Weekly" or
    // "Weekly (Sun-Sat)" that we must collapse to the CoreFlux enum.
    $cycleEnumMap = [
        'weekly'        => 'weekly',
        'biweekly'      => 'biweekly', 'bi-weekly' => 'biweekly', 'bi_weekly' => 'biweekly',
        'semimonthly'   => 'semimonthly', 'semi-monthly' => 'semimonthly', 'semi_monthly' => 'semimonthly',
        'monthly'       => 'monthly',
        'adhoc'         => 'adhoc', 'ad-hoc' => 'adhoc', 'ad_hoc' => 'adhoc', 'as needed' => 'adhoc',
    ];
    $coerceCycle = static function (string $raw) use ($cycleEnumMap): ?string {
        if ($raw === '') return null;
        $key = strtolower(trim($raw));
        // Some JobDiva tenants embed extra qualifiers like "Weekly (Sun-Sat)";
        // strip everything after the first space/paren so the ENUM matches.
        $key = preg_replace('/[\s(].*$/', '', $key) ?? $key;
        return $cycleEnumMap[$key] ?? null;
    };
    $clientBillCycleRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'client_bill_cycle', $jd,
        static fn() => jobdivaPluckField($jd, [
            'billCycle', 'bill_cycle', 'clientBillCycle', 'client_bill_cycle',
            'invoiceFrequency', 'billingFrequency', 'billing_cycle',
        ])
    );
    $clientBillCycle = $coerceCycle($clientBillCycleRaw);
    $clientBillCycleAnchorRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'client_bill_cycle_anchor', $jd,
        static fn() => jobdivaPluckField($jd, [
            'billCycleAnchor', 'bill_cycle_anchor', 'clientBillCycleAnchor',
            'client_bill_cycle_anchor', 'billingAnchorDate', 'invoiceAnchor',
        ])
    );
    $clientBillCycleAnchor = jobdivaNormaliseDate($clientBillCycleAnchorRaw);
    $vendorPayCycleRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'vendor_pay_cycle', $jd,
        static fn() => jobdivaPluckField($jd, [
            'payCycle', 'pay_cycle', 'vendorPayCycle', 'vendor_pay_cycle',
            'payrollFrequency', 'pay_frequency',
        ])
    );
    $vendorPayCycle = $coerceCycle($vendorPayCycleRaw);
    $vendorPayCycleAnchorRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'vendor_pay_cycle_anchor', $jd,
        static fn() => jobdivaPluckField($jd, [
            'payCycleAnchor', 'pay_cycle_anchor', 'vendorPayCycleAnchor',
            'vendor_pay_cycle_anchor', 'payrollAnchorDate',
        ])
    );
    $vendorPayCycleAnchor = jobdivaNormaliseDate($vendorPayCycleAnchorRaw);

    if ($existingId > 0) {
        // Slice 2: respect coreflux_overridden_fields — fields the user edited
        // in CoreFlux must not be reverted on the next JobDiva pull. Strip
        // any overridden field from the SET clause and audit what we skipped.
        $overrideStmt = $pdo->prepare(
            'SELECT coreflux_overridden_fields FROM placements WHERE tenant_id = :t AND id = :id LIMIT 1'
        );
        $overrideStmt->execute(['t' => $tid, 'id' => $existingId]);
        $rawOverride = $overrideStmt->fetchColumn();
        $overrides = [];
        if (is_string($rawOverride) && $rawOverride !== '') {
            $decoded = json_decode($rawOverride, true);
            if (is_array($decoded)) {
                $overrides = array_values(array_filter(array_map('strval', $decoded)));
            }
        }

        $allFields = [
            'start_date'           => ['sd',    $startDate],
            'end_date'             => ['ed',    $endDateNorm ?: null],
            'actual_end_date'      => ['aed',   $actualEnd ?: null],
            'due_date'             => ['dd',    $dueDate ?: null],
            'status'               => ['st',    $status],
            'engagement_type'      => ['eng',   $engagement],
            'worksite_state'       => ['ws',    $worksiteState ?: null],
            'worksite_country'     => ['wc',    $worksiteCountry],
            'remote_policy'        => ['rp',    $remote],
            'notes'                => ['notes', $notes ?: null],
            'end_client_name'      => ['ecn',   $endClientName ?: null],
            'end_client_company_id' => ['ecc',  $endClientCompanyId],
            'client_approver_name' => ['can',   $approverName ?: null],
            'client_approver_email'=> ['cae',   $approverEmail ?: null],
            'title'                => ['ti',    $title],
            'jobdiva_job_id'       => ['jji',   $jobdivaJobId ?: null],
            'recruiter_name'       => ['rn',    $recruiterName ?: null],
            'recruiter_email'      => ['re',    $recruiterEmail ?: null],
            'account_manager_name' => ['amn',   $accountManagerName ?: null],
            'account_manager_email'=> ['ame',   $accountManagerEmail ?: null],
            // Slice 5b broader-mapping additions
            'client_bill_cycle'         => ['cbc',  $clientBillCycle],
            'client_bill_cycle_anchor'  => ['cbca', $clientBillCycleAnchor],
            'vendor_pay_cycle'          => ['vpc',  $vendorPayCycle],
            'vendor_pay_cycle_anchor'   => ['vpca', $vendorPayCycleAnchor],
        ];
        $assignments = [];
        $bindings = ['id' => $existingId];
        $skipped = [];
        foreach ($allFields as $col => [$bind, $val]) {
            if (in_array($col, $overrides, true)) {
                $skipped[] = $col;
                continue;
            }
            // ENUM columns reject empty strings AND reject NULL when
            // declared NOT NULL. `client_bill_cycle` / `vendor_pay_cycle`
            // are NOT NULL with defaults; skipping the assignment lets
            // the existing column value (or DB default) stick.
            if ($val === null && in_array($col, ['client_bill_cycle', 'vendor_pay_cycle'], true)) {
                $skipped[] = $col;
                continue;
            }
            $assignments[] = "{$col} = :{$bind}";
            $bindings[$bind] = $val;
        }
        if (!empty($skipped)) {
            error_log("[jobdiva] placement id={$existingId} skipping CoreFlux-overridden fields: " . implode(',', $skipped));
        }

        if (!empty($assignments)) {
            $sql = 'UPDATE placements SET ' . implode(', ', $assignments) . ' WHERE id = :id';
            // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
            $pdo->prepare($sql)->execute($bindings);
        }
        jobdivaSyncUpsertPlacementRates($tid, $existingId, $startDate, $jd);
        return $existingId;
    }
    $pdo->prepare(
        'INSERT INTO placements (tenant_id, person_id, external_id, jobdiva_job_id, status, start_date, end_date,
                                  actual_end_date, due_date, engagement_type, worksite_state, worksite_country,
                                  remote_policy, notes, end_client_name, end_client_company_id,
                                  client_approver_name, client_approver_email, title,
                                  recruiter_name, recruiter_email,
                                  account_manager_name, account_manager_email,
                                  client_bill_cycle, client_bill_cycle_anchor,
                                  vendor_pay_cycle, vendor_pay_cycle_anchor)
         VALUES (:t, :p, :ext, :jji, :st, :sd, :ed, :aed, :dd, :eng, :ws, :wc,
                 :rp, :notes, :ecn, :ecc, :can, :cae, :ti,
                 :rn, :re, :amn, :ame,
                 :cbc, :cbca, :vpc, :vpca)'
    )->execute([
        't'     => $tid,
        'p'     => $personId,
        'ext'   => 'jd:' . $extId,
        'jji'   => $jobdivaJobId ?: null,
        'st'    => $status,
        'sd'    => $startDate,
        'ed'    => $endDateNorm ?: null,
        'aed'   => $actualEnd ?: null,
        'dd'    => $dueDate ?: null,
        'eng'   => $engagement,
        'ws'    => $worksiteState ?: null,
        'wc'    => $worksiteCountry,
        'rp'    => $remote,
        'notes' => $notes ?: null,
        'ecn'   => $endClientName ?: null,
        'ecc'   => $endClientCompanyId,
        'can'   => $approverName ?: null,
        'cae'   => $approverEmail ?: null,
        'ti'    => $title,
        'rn'    => $recruiterName ?: null,
        're'    => $recruiterEmail ?: null,
        'amn'   => $accountManagerName ?: null,
        'ame'   => $accountManagerEmail ?: null,
        // Cycle columns are NOT NULL with defaults; fall back to the
        // schema defaults when the upstream payload didn't carry one.
        'cbc'   => $clientBillCycle ?? 'monthly',
        'cbca'  => $clientBillCycleAnchor,
        'vpc'   => $vendorPayCycle ?? 'biweekly',
        'vpca'  => $vendorPayCycleAnchor,
    ]);
    $placementId = (int) $pdo->lastInsertId();
    jobdivaSyncUpsertPlacementRates($tid, $placementId, $startDate, $jd);
    return $placementId;
}

/**
 * Cross-table writer for the current `placement_rates` row.
 *
 * The field-map registry surfaces bill_rate / pay_rate / currency / etc.
 * under entity_type='placement' (matches the operator's mental model —
 * JobDiva's Assignment screen shows rates alongside the placement) but
 * the schema separates them out so we can track rate history per
 * placement. This helper writes the CURRENT row (effective_to IS NULL).
 *
 * Resolution order per field:
 *   1. tenant_integration_field_map override (if the tenant configured one)
 *   2. Built-in JobDiva candidate-key fallback (covers the V2 payload
 *      keys observed in this user's pod: `final bill rate`,
 *      `agreed pay rate`, `bill rate currency/unit`, etc.)
 *   3. Sensible default (USD, hour, 1.50 OT, 2.00 DT)
 *
 * Skipped (returns false) when bill_rate resolves to a non-numeric / 0
 * — placements without a rate (e.g. direct-hire) should NOT create
 * placeholder rows; users can fix manually.
 */
function jobdivaSyncUpsertPlacementRates(int $tid, int $placementId, string $startDate, array $jd): bool
{
    require_once __DIR__ . '/../integrations/field_map.php';
    $pdo = getDB();

    // -- Resolve every rate field via the registry, with JobDiva-native
    //    default-key candidate lists shaped to the V2 BI payload.
    $billRateRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'bill_rate', $jd,
        static fn() => jobdivaPluckField($jd, [
            'final bill rate', 'finalBillRate', 'final_bill_rate',
            'bill rate', 'billRate', 'bill_rate',
            'quoted bill rate', 'quotedBillRate',
        ]) ?: (
            // Fall through to the enriched start detail (when present).
            // JobDiva BI feeds frequently null out the rate on the
            // Assignment-level payload; the searchStart detail call
            // (when wired) carries it.
            isset($jd['_jd_start']) && is_array($jd['_jd_start'])
                ? jobdivaPluckField($jd['_jd_start'], [
                    'finalBillRate', 'billRate', 'final_bill_rate', 'bill_rate',
                ])
                : ''
        )
    );
    $billRate = is_numeric($billRateRaw) ? (float) $billRateRaw : 0.0;
    if ($billRate <= 0) {
        // No rate present — placement is rate-less (direct hire,
        // perm placement, etc). Skip writing a rate row so we don't
        // pollute placement_rates with zero-valued placeholders.
        return false;
    }

    $payRateRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'pay_rate', $jd,
        static fn() => jobdivaPluckField($jd, [
            'agreed pay rate', 'agreedPayRate', 'agreed_pay_rate',
            'pay rate', 'payRate', 'pay_rate',
        ]) ?: (
            isset($jd['_jd_start']) && is_array($jd['_jd_start'])
                ? jobdivaPluckField($jd['_jd_start'], [
                    'payRate', 'agreedPayRate', 'pay_rate', 'agreed_pay_rate',
                ])
                : ''
        )
    );
    // pay_rate is NOT NULL on the schema — if JobDiva didn't supply
    // one, mirror bill_rate (overrideable by the operator later).
    $payRate = is_numeric($payRateRaw) ? (float) $payRateRaw : $billRate;

    // Coerce 'h' / 'hourly' / 'USD/Hour' / etc. to the ENUM values.
    // Per-rate units may differ (e.g. day rate + hourly OT) but the
    // ENUM is `hour|day|week|month|project` per the schema.
    $coerceUnit = static function (string $raw): string {
        $s = strtolower(trim($raw));
        if ($s === '' || $s === 'h' || str_contains($s, 'hour')) return 'hour';
        if (str_contains($s, 'day'))     return 'day';
        if (str_contains($s, 'week'))    return 'week';
        if (str_contains($s, 'month'))   return 'month';
        if (str_contains($s, 'project') || str_contains($s, 'fixed')) return 'project';
        return 'hour';
    };
    $billRateUnit = $coerceUnit((string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'bill_rate_unit', $jd,
        static fn() => jobdivaPluckField($jd, [
            'final bill rate unit', 'bill rate currency/unit', 'billRateUnit', 'bill_rate_unit',
        ])
    ));
    $payRateUnit = $coerceUnit((string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'pay_rate_unit', $jd,
        static fn() => jobdivaPluckField($jd, [
            'pay rate currency/unit', 'payRateUnit', 'pay_rate_unit', 'hourly unit',
        ])
    ));

    // Currency: extract from "USD/Hour" style strings if needed.
    $currencyRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'currency', $jd,
        static fn() => jobdivaPluckField($jd, [
            'currency', 'final bill rate currency', 'hourly currency',
        ])
    );
    if ($currencyRaw === '') $currencyRaw = 'USD';
    if (preg_match('/\b([A-Z]{3})\b/', strtoupper($currencyRaw), $m)) {
        $currency = $m[1];
    } else {
        $currency = strtoupper(substr($currencyRaw, 0, 3));
    }
    if (strlen($currency) !== 3) $currency = 'USD';

    $otRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'ot_multiplier', $jd,
        static fn() => jobdivaPluckField($jd, ['ot_multiplier', 'otMultiplier', 'overtime_multiplier'])
    );
    $dtRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'dt_multiplier', $jd,
        static fn() => jobdivaPluckField($jd, ['dt_multiplier', 'dtMultiplier', 'doubletime_multiplier'])
    );
    $otMul = is_numeric($otRaw) ? (float) $otRaw : 1.50;
    $dtMul = is_numeric($dtRaw) ? (float) $dtRaw : 2.00;

    // Locate the current rate row (effective_to IS NULL). If multiple
    // exist (data anomaly), update the most recent one.
    $existing = $pdo->prepare(
        'SELECT id FROM placement_rates
          WHERE tenant_id = :t AND placement_id = :p AND effective_to IS NULL
          ORDER BY effective_from DESC, id DESC LIMIT 1'
    );
    $existing->execute(['t' => $tid, 'p' => $placementId]);
    $rateId = (int) $existing->fetchColumn();

    if ($rateId > 0) {
        // tenant-leak-allow: id was just fetched under tenant scope above
        $pdo->prepare(
            'UPDATE placement_rates
                SET bill_rate = :br, bill_rate_unit = :bru,
                    pay_rate  = :pr, pay_rate_unit  = :pru,
                    currency  = :cur,
                    ot_multiplier = :ot, dt_multiplier = :dt
              WHERE id = :id'
        )->execute([
            'br'  => $billRate, 'bru' => $billRateUnit,
            'pr'  => $payRate,  'pru' => $payRateUnit,
            'cur' => $currency,
            'ot'  => $otMul, 'dt' => $dtMul,
            'id'  => $rateId,
        ]);
        return true;
    }

    $pdo->prepare(
        'INSERT INTO placement_rates
            (tenant_id, placement_id, effective_from, bill_rate, bill_rate_unit,
             pay_rate, pay_rate_unit, currency, ot_multiplier, dt_multiplier)
         VALUES (:t, :p, :ef, :br, :bru, :pr, :pru, :cur, :ot, :dt)'
    )->execute([
        't'   => $tid, 'p'   => $placementId,
        'ef'  => $startDate !== '' ? $startDate : date('Y-m-d'),
        'br'  => $billRate, 'bru' => $billRateUnit,
        'pr'  => $payRate,  'pru' => $payRateUnit,
        'cur' => $currency,
        'ot'  => $otMul, 'dt' => $dtMul,
    ]);
    return true;
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
