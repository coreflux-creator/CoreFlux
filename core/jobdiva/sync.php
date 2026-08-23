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
require_once __DIR__ . '/assignment_identity.php';
require_once __DIR__ . '/assignment_contract.php';
require_once __DIR__ . '/canonical_graph.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';
require_once __DIR__ . '/../integrations/payload_field_index.php';
require_once __DIR__ . '/../../modules/people/lib/companies.php';
require_once __DIR__ . '/../../modules/staffing/lib/clients.php';
require_once __DIR__ . '/../../modules/staffing/lib/jobs.php';
require_once __DIR__ . '/../../modules/placements/lib/economics.php';
require_once __DIR__ . '/projector.php';

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
 * Build a supported searchStart request from an already-known placement.
 *
 * JobDiva's current V2 contract does not accept `startId` as search criteria.
 * The narrowest supported lookup is jobId + candidateid; the returned rows
 * still have to echo the requested Start ID before they are trusted.
 */
function jobdivaAssignmentSearchStartCriteria(array $hints): array
{
    $jobId = jobdivaAssignmentIdentityPluck($hints, [
        'job id', 'jobId', 'job_id', 'jobID', 'JOBID', 'reqId', 'req_id',
    ]);
    $candidateId = jobdivaAssignmentIdentityPluck($hints, [
        'candidate id', 'candidateId', 'candidate_id', 'candidateID', 'CANDIDATEID',
        'employeeId', 'employee_id',
    ]);
    $candidateEmail = jobdivaAssignmentIdentityPluck($hints, [
        'candidate email', 'candidateEmail', 'candidate_email',
    ]);

    $criteria = [];
    if ($jobId !== '' && ctype_digit($jobId)) {
        $criteria['jobId'] = (int) $jobId;
    }
    if ($candidateId !== '' && ctype_digit($candidateId)) {
        $criteria['candidateid'] = (int) $candidateId;
    } elseif ($candidateEmail !== '') {
        $criteria['candidateemail'] = $candidateEmail;
    }
    if ($criteria === []) return [];

    $criteria['maxreturned'] = 100;
    $criteria['offset'] = 0;
    return $criteria;
}

/**
 * Fetch one Start/Assignment and require JobDiva to echo the same identity
 * and the same job/candidate context.
 *
 * A successful HTTP response containing a different row is not a match.
 */
function jobdivaFetchExactAssignmentById(
    int $tenantId,
    string $assignmentId,
    array $hints = []
): array
{
    $assignmentId = jobdivaAssignmentIdentityNormaliseId($assignmentId);
    if ($tenantId <= 0 || $assignmentId === '') {
        return ['status' => 'invalid_request', 'row' => null, 'error' => 'missing assignment id'];
    }
    $criteria = jobdivaAssignmentSearchStartCriteria($hints);
    if ($criteria === []) {
        return [
            'status' => 'inconclusive',
            'row' => null,
            'error' => 'JobDiva searchStart requires job or candidate criteria; no supported exact lookup hints were available',
            'seen_ids' => [],
            'criteria' => [],
        ];
    }

    $seenIds = [];
    try {
        $response = jobdivaCall(
            $tenantId,
            'POST',
            '/apiv2/jobdiva/searchStart',
            $criteria
        );
        $matchedButUnqualified = null;
        foreach (jobdivaAssignmentRowsFromResponse($response) as $row) {
            if (!is_array($row)) continue;
            $rowId = jobdivaAssignmentRowId($row);
            if ($rowId !== '') $seenIds[] = $rowId;
            if ($rowId !== $assignmentId) continue;
            $context = jobdivaAssignmentContextEvidence($row, $hints);
            if (empty($context['matches'])) {
                $matchedButUnqualified = [
                    'reason' => 'assignment_context_mismatch',
                    'context' => $context,
                ];
                continue;
            }
            $identity = jobdivaAssignmentValidate($row, $assignmentId, 'searchStart:criteria_exact');
            if (empty($identity['valid'])) {
                $matchedButUnqualified = $identity;
                continue;
            }
            $row = jobdivaAssignmentMarkVerified($row, $assignmentId, 'searchStart:criteria_exact');
            return [
                'status' => 'verified',
                'row' => $row,
                'error' => null,
                'seen_ids' => $seenIds,
                'identity' => $identity,
                'criteria' => array_keys($criteria),
            ];
        }
        if ($matchedButUnqualified !== null) {
            return [
                'status' => 'not_assignment',
                'row' => null,
                'error' => 'JobDiva row is not a qualified Start/Assignment: '
                    . (string) ($matchedButUnqualified['reason'] ?? 'unqualified state'),
                'seen_ids' => $seenIds,
                'identity' => $matchedButUnqualified,
                'criteria' => array_keys($criteria),
            ];
        }
        return [
            'status' => 'not_found',
            'row' => null,
            'error' => $seenIds
                ? 'JobDiva returned different assignment ids: ' . implode(',', array_values(array_unique($seenIds)))
                : 'JobDiva did not return a qualified assignment with the requested Start ID',
            'seen_ids' => $seenIds,
            'criteria' => array_keys($criteria),
        ];
    } catch (\Throwable $e) {
        return [
            'status' => 'error',
            'row' => null,
            'error' => 'searchStart: ' . substr($e->getMessage(), 0, 300),
            'seen_ids' => $seenIds,
            'criteria' => array_keys($criteria),
        ];
    }
}

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
    array $nestOrder = ['_jd_contract', '_jd_candidate', '_jd_job', '_jd_customer', '_jd_contact', '_jd_start',
                       'job', 'Job', 'jobInfo', 'jobObj', 'jobRecord',
                       'candidate', 'Candidate', 'customer', 'Customer', 'contact', 'Contact',
                       'person', 'company', 'Company', 'client', 'Client',
                       'assignment', 'start', 'Start', 'jobdiva_assignment']
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

function jobdivaPluckNestedField(array $item, array $candidates, array $nestOrder): string
{
    foreach ($nestOrder as $nest) {
        if (!isset($item[$nest]) || !is_array($item[$nest])) continue;
        $v = jobdivaPluckField($item[$nest], $candidates);
        if ($v !== '') return $v;
    }
    return '';
}

function jobdivaEndClientNameFromPayload(array $item): string
{
    $companySpecific = [
        'endClientCompanyName', 'end_client_company_name', 'end client company name',
        'companyName', 'company_name', 'company name', 'COMPANYNAME',
        'jobCompanyName', 'job_company_name', 'job company name',
    ];
    $ambiguousNames = [
        'endClientName', 'end_client_name', 'end client name',
        'customerName', 'customer_name', 'customer name',
        'clientName', 'client_name', 'client name',
    ];

    foreach (['_jd_job', 'job', 'Job', 'jobInfo', 'jobObj', 'jobRecord'] as $nest) {
        if (!isset($item[$nest]) || !is_array($item[$nest])) continue;
        $v = jobdivaPluckField($item[$nest], $companySpecific);
        if ($v !== '') return $v;
    }
    foreach (['_jd_customer', 'customer', 'Customer', 'company', 'Company', 'client', 'Client'] as $nest) {
        if (!isset($item[$nest]) || !is_array($item[$nest])) continue;
        $v = jobdivaPluckField($item[$nest], array_merge($companySpecific, ['legalName', 'legal_name', 'name']));
        if ($v !== '') return $v;
    }

    $v = jobdivaPluckField($item, $companySpecific);
    if ($v !== '') return $v;

    foreach (['_jd_start', 'assignment', 'start', 'Start'] as $nest) {
        if (!isset($item[$nest]) || !is_array($item[$nest])) continue;
        $v = jobdivaPluckField($item[$nest], $companySpecific);
        if ($v !== '') return $v;
    }

    $v = jobdivaPluckField($item, $ambiguousNames);
    if ($v !== '') return $v;

    foreach (['_jd_customer', 'customer', 'Customer', 'client', 'Client'] as $nest) {
        if (!isset($item[$nest]) || !is_array($item[$nest])) continue;
        $v = jobdivaPluckField($item[$nest], array_merge($ambiguousNames, ['name']));
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

function jobdivaRowsFromResponse($resp): array
{
    $body = $resp;
    if (is_array($resp) && array_key_exists('body', $resp)
        && (array_key_exists('status', $resp) || array_key_exists('headers', $resp))) {
        $body = $resp['body'];
    }
    if (!is_array($body)) return [];
    if (isset($body['data']) && is_array($body['data'])) {
        $data = $body['data'];
        return $data === [] || array_is_list($data) ? $data : [$data];
    }
    if (isset($body['items']) && is_array($body['items'])) {
        $items = $body['items'];
        return $items === [] || array_is_list($items) ? $items : [$items];
    }
    if (!empty($body) && array_keys($body) === range(0, count($body) - 1)) return $body;
    return !empty($body) ? [$body] : [];
}

function jobdivaEnsureStaffingClientForCompany(int $tid, int $companyId, string $name, ?int $userId): void
{
    if ($companyId <= 0 || trim($name) === '') return;
    try {
        staffingClientEnsureForCompany($tid, $companyId, $name, [
            'created_by_user_id' => $userId,
        ]);
    } catch (\Throwable $e) {
        error_log('[jobdiva company sync] staffing client bridge failed: ' . $e->getMessage());
    }
}

function jobdivaBridgeStaffingJobFromPayload(int $tid, string $jobdivaJobId, array $payload, ?int $userId): ?int
{
    $jobdivaJobId = trim($jobdivaJobId);
    if ($jobdivaJobId === '') return null;
    try {
        $job = staffingJobEnsureFromJobDivaPayload($tid, $jobdivaJobId, $payload, $userId);
        $staffingJobId = (int) ($job['id'] ?? 0);
        if ($staffingJobId > 0) {
            mappingUpsert($tid, 'jobdiva', 'staffing_job', $jobdivaJobId, $staffingJobId, $payload, 'pull', $userId);
            staffingJobLinkPlacementsByJobDivaId($tid, $jobdivaJobId, $staffingJobId);
            return $staffingJobId;
        }
    } catch (\Throwable $e) {
        error_log('[jobdiva job sync] staffing job bridge failed: ' . $e->getMessage());
    }
    return null;
}

function jobdivaUpsertCompanyMapped(
    int $tid,
    string $extId,
    string $name,
    array $patch,
    array $payload,
    ?int $userId,
    array $roles = ['client']
): int {
    $name = trim($name);
    if ($extId === '' || $name === '') throw new \InvalidArgumentException('company external id and name required');

    $mapped = mappingFindInternal($tid, 'jobdiva', 'company', $extId);
    if ($mapped) {
        $companyId = (int) $mapped['internal_entity_id'];
        $pdo = getDB();
        $st = $pdo->prepare('SELECT id, name FROM companies WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL LIMIT 1');
        $st->execute(['t' => $tid, 'id' => $companyId]);
        $current = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($current) {
            unset($patch['created_by_user_id']);
            $updatable = array_filter($patch, static fn($v) => $v !== null && $v !== '');
            $dupeId = 0;
            if ($name !== '' && $name !== (string) ($current['name'] ?? '')) {
                $dupe = $pdo->prepare('SELECT id FROM companies WHERE tenant_id = :t AND name = :n AND id != :id AND deleted_at IS NULL LIMIT 1');
                $dupe->execute(['t' => $tid, 'n' => $name, 'id' => $companyId]);
                $dupeId = (int) $dupe->fetchColumn();
                if ($dupeId > 0) {
                    $dupeMap = mappingFindExternal($tid, 'jobdiva', 'company', $dupeId);
                    if ($dupeMap && (string) ($dupeMap['external_id'] ?? '') !== $extId) $dupeId = 0;
                }
                if ($dupeId <= 0) $updatable['name'] = $name;
            }
            if ($dupeId > 0) {
                $pdo->prepare('UPDATE company_contacts SET company_id = :new WHERE tenant_id = :t AND company_id = :old')
                    ->execute(['new' => $dupeId, 't' => $tid, 'old' => $companyId]);
                $companyId = $dupeId;
            } elseif (!empty($updatable)) {
                $setSql = implode(', ', array_map(static fn($k) => "{$k} = :{$k}", array_keys($updatable)));
                $params = $updatable + ['id' => $companyId, 'tenant_id' => $tid];
                $pdo->prepare("UPDATE companies SET {$setSql} WHERE id = :id AND tenant_id = :tenant_id")
                    ->execute($params);
            }
            foreach ($roles as $role) companiesAddRole($companyId, $role);
            if (in_array('client', $roles, true)) {
                jobdivaEnsureStaffingClientForCompany($tid, $companyId, $name, $userId);
            }
            mappingUpsert($tid, 'jobdiva', 'company', $extId, $companyId, $payload, 'pull', $userId);
            return $companyId;
        }
    }

    $companyId = companiesUpsertByName($tid, $name, $patch, $roles);
    if (in_array('client', $roles, true)) {
        jobdivaEnsureStaffingClientForCompany($tid, $companyId, $name, $userId);
    }
    mappingUpsert($tid, 'jobdiva', 'company', $extId, $companyId, $payload, 'pull', $userId);
    return $companyId;
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

            $companyId = jobdivaUpsertCompanyMapped($tid, $extId, $name, $patch, $jd, $userId, ['client']);
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
    $companyMappingCache = [];
    $companyBackfillMisses = [];

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
            // Cache by JobDiva company ID so large contact syncs do not repeat the
            // same mapping lookup/backfill call hundreds of times.
            $companyCacheKey = (string) $companyExtId;
            if (array_key_exists($companyCacheKey, $companyMappingCache)) {
                $companyMapping = $companyMappingCache[$companyCacheKey];
            } else {
                $companyMapping = mappingFindInternal($tid, 'jobdiva', 'company', $companyExtId);
                $companyMappingCache[$companyCacheKey] = $companyMapping ?: null;
            }
            if (!$companyMapping
                && !empty($opts['backfill_companies_on_contact_pull'])
                && empty($companyBackfillMisses[$companyCacheKey])) {
                // Backfill on-demand: the Companies delta window missed
                // this parent (likely because it hasn't been edited
                // recently). Fetch the single record by id and upsert it
                // before retrying the mapping lookup. Soft-fail — if
                // JobDiva 404s the company, we still fall through to the
                // skip path below so the contact is logged like before.
                try {
                    $candidateRows = jobdivaCallBulkIds(
                        $tid,
                        '/apiv2/bi/CompaniesDetail',
                        'companyIds',
                        [(string) $companyExtId],
                        [],
                        1
                    );
                    if (is_array($candidateRows) && !empty($candidateRows) && is_array($candidateRows[0])) {
                        $jdCo = $candidateRows[0];
                        $coName = trim((string) (
                            $jdCo['name']         ?? $jdCo['companyName'] ?? $jdCo['company_name']
                            ?? $jdCo['customerName'] ?? $jdCo['customer_name'] ?? ''
                        ));
                        if ($coName !== '') {
                            $newCoId = jobdivaUpsertCompanyMapped($tid, (string) $companyExtId, $coName, [
                                'website'            => $jdCo['website'] ?? null,
                                'phone'              => $jdCo['phone']   ?? null,
                                'address_line1'      => $jdCo['address1'] ?? $jdCo['address'] ?? null,
                                'address_line2'      => $jdCo['address2'] ?? null,
                                'city'               => $jdCo['city']    ?? null,
                                'state'              => $jdCo['state']   ?? null,
                                'postal_code'        => $jdCo['zip']     ?? $jdCo['postal_code'] ?? null,
                                'country'            => $jdCo['country'] ?? 'US',
                                'created_by_user_id' => $userId,
                            ], $jdCo, $userId, ['client']);
                            $companyMapping = mappingFindInternal($tid, 'jobdiva', 'company', $companyExtId);
                            $companyMappingCache[$companyCacheKey] = $companyMapping ?: null;
                            $skipReasons['backfilled_companies'] = ($skipReasons['backfilled_companies'] ?? 0) + 1;
                        }
                    }
                } catch (\Throwable $bfe) {
                    // Backfill failure is non-fatal — the contact will
                    // just go through the existing skip+diagnostic path
                    // so the operator still sees the underlying problem.
                    error_log("[jobdiva] backfill_companies_on_contact_pull failed for company={$companyExtId}: " . $bfe->getMessage());
                }
                if (!$companyMapping) $companyBackfillMisses[$companyCacheKey] = true;
            }
            if (!$companyMapping && !empty($opts['backfill_companies_on_contact_pull'])) {
                $placeholderName = 'JobDiva Company ' . (string) $companyExtId;
                $placeholderPayload = [
                    'id' => (string) $companyExtId,
                    'name' => $placeholderName,
                    '_coreflux_placeholder' => true,
                    '_coreflux_placeholder_reason' => 'JobDiva contact parent company detail was unavailable during contact sync.',
                ];
                $placeholderId = jobdivaUpsertCompanyMapped($tid, (string) $companyExtId, $placeholderName, [
                    'notes' => 'Placeholder created from JobDiva contact sync; later JobDiva company detail can enrich this record.',
                    'created_by_user_id' => $userId,
                ], $placeholderPayload, $userId, ['client']);
                $companyMapping = mappingFindInternal($tid, 'jobdiva', 'company', $companyExtId);
                $companyMappingCache[$companyCacheKey] = $companyMapping ?: null;
                if ($placeholderId > 0) {
                    $skipReasons['placeholder_companies'] = ($skipReasons['placeholder_companies'] ?? 0) + 1;
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
                '%d parent company%s auto-fetched via CompaniesDetail during this contact sync (backfill_companies_on_contact_pull=true). The contact(s) succeeded; no operator action needed.',
                $skipReasons['backfilled_companies'],
                $skipReasons['backfilled_companies'] === 1 ? '' : 'ies'
            ),
        ];
    }
    if (!empty($skipReasons['placeholder_companies'])) {
        $errors[] = [
            'entity' => 'contact',
            'kind'   => 'placeholder_companies',
            'error'  => sprintf(
                '%d parent company placeholder%s created because JobDiva did not return detail for those company IDs. Contacts were preserved and the placeholders can be enriched by a later company/detail sync.',
                $skipReasons['placeholder_companies'],
                $skipReasons['placeholder_companies'] === 1 ? '' : 's'
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
    // Start/Assignment detail is core placement evidence. It carries
    // rates, vendor/pay-cycle fields, MSP discounts, C2C markers, and
    // assignment-level dates. Keep an explicit opt-out for tests or
    // emergency API throttling; otherwise sync the complete graph.
    $enrichStart = array_key_exists('enrich_start', $opts)
        ? (bool) $opts['enrich_start']
        : true;
    // EmployeeAssignmentRecordsDetail is one request per Start ID. Running
    // that fan-out inside the main placement request exceeds common 60-second
    // PHP limits on real tenants. The UI follows the ordinary sync with the
    // resumable assignment-contract batch action below.
    $enrichFinancial = array_key_exists('enrich_financial', $opts)
        ? (bool) $opts['enrich_financial']
        : false;
    $items = jobdivaSyncEnrichRelatedEntities($tid, $items, $userId, [
        'enrich_start' => $enrichStart,
        'enrich_financial' => $enrichFinancial,
    ]);

    $processed = 0; $skipped = 0; $failed = 0; $errors = [];
    $skipReasons = ['missing_fields' => 0, 'no_person' => 0, 'invalid_assignment_source' => 0];

    foreach ($items as $jd) {
        try {
            $jd = jobdivaAssignmentSanitisePayload($jd);
            $jd = jobdivaCanonicalPlacementPayload($jd, jobdivaExtractJoinedSubPayloads($jd));
            $sourceIdentity = jobdivaAssignmentValidate($jd);
            if (empty($sourceIdentity['valid'])) {
                $skipped++;
                $skipReasons['invalid_assignment_source']++;
                continue;
            }
            $extId        = (string) ($sourceIdentity['assignment_id'] ?? '');
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

            // Persist verified Start evidence before projecting it. The
            // assignment mirror is the authoritative set that reconciliation
            // and final replay use; a CoreFlux placement snapshot is only an
            // output of this source record.
            $jd = jobdivaAssignmentMarkVerified(
                $jd,
                $extId,
                (string) ($sourceIdentity['channel'] ?? $channel ?: 'placement_sync')
            );
            $assignmentMirror = jobdivaMirrorStoreAndIndex(
                $tid,
                'jobdiva_assignment',
                [$jd],
                ['id', 'startId', 'start_id', 'startID', 'STARTID', 'placementId'],
                $userId
            );
            if ((int) ($assignmentMirror['processed'] ?? 0) !== 1) {
                throw new \RuntimeException("could not persist verified JobDiva assignment {$extId}");
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
            //   • `customer name` / job COMPANYNAME — end-client name
            //     evidence. In this tenant the placement `customer id`
            //     is a contact id, not a company id, so the projector
            //     does not bind that id as jobdiva_customer -> companies.
            //
            // Resolution chain (first hit wins):
            //   1. Existing `external_entity_mappings` row of kind
            //      'company' for `companyId`.
            //   2. Trusted nested customer/company payload ids only
            //      (never the shallow placement `customer id`).
            //   3. Auto-create or reuse a CoreFlux `companies` row from
            //      the end-client name and use it. This
            //      unblocks the "(no end client)" badge that's been
            //      showing on every JobDiva-synced placement, and lets
            //      the operator merge / rename the company later in the
            //      Companies UI without losing the placement link.
            $endClientCompanyId = jobdivaProjectorResolveEndClientCompany($tid, $jd, $userId);
            $projection = jobdivaProjectorProjectPlacement($tid, $jd, $userId, [
                'payload_is_enriched' => true,
                'external_id' => $extId,
                'person_id' => $personId,
                'end_client_company_id' => $endClientCompanyId,
            ]);
            if (empty($projection['projected'])) {
                throw new \RuntimeException(implode('; ', $projection['errors'] ?? ['projection failed']));
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
 *   _jd_customer   ← /apiv2/bi/CompaniesDetail      result row
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
    // Per-kind config. Each kind can declare MULTIPLE id_options so we
    // can fall back to a string-based identifier when the numeric one
    // isn't present in the payload (notably JobDiva's `jobRefNo`,
    // e.g. "26-03327", when `jobId` itself isn't carried). Each option
    // has its own body_key so the API call uses the matching parameter.
    //
    //   numeric=true   → reject non-digit strings (default for internal ids)
    //   numeric=false  → accept any non-empty string (ref-style ids)
    $configs = [
        'job'       => [
            'id_options' => [
                // Preferred: numeric internal job id.
                ['ids' => ['job id', 'jobId', 'job_id', 'jobID', 'JOBID'],
                 'body_key' => 'jobId', 'numeric' => true],
                // Fallback: human-readable JobDiva Job # (e.g. "26-03327").
                // JobDiva's /apiv2/jobdiva/searchJob accepts {req: "..."}
                // as an alternative to jobId.
                ['ids' => ['jobRefNo', 'job ref no', 'job_ref_no', 'jobRefNumber',
                           'reqNo', 'req_no', 'req'],
                 'body_key' => 'req', 'numeric' => false],
            ],
            'endpoint' => '/apiv2/jobdiva/searchJob',
            'inject'   => '_jd_job',
        ],
        'candidate' => [
            'id_options' => [
                ['ids' => ['candidate id', 'candidateId', 'candidate_id', 'employeeId'],
                 'body_key' => 'candidateId', 'numeric' => true],
            ],
            'endpoint' => '/apiv2/jobdiva/searchCandidate',
            'inject'   => '_jd_candidate',
        ],
        'customer'  => [
            'id_options' => [
                ['ids' => ['companyId', 'company_id', 'company id', 'companyID', 'COMPANYID',
                           'endClientCompanyId', 'end_client_company_id', 'end client company id'],
                 'body_key' => 'companyIds', 'numeric' => true],
            ],
            'method'   => 'GET',
            'endpoint' => '/apiv2/bi/CompaniesDetail',
            'inject'   => '_jd_customer',
        ],
        'contact'   => [
            'id_options' => [
                ['ids' => ['job contact id', 'jobContactId', 'contactId'],
                 'body_key' => 'contactId', 'numeric' => true],
            ],
            'endpoint' => '/apiv2/jobdiva/searchContact',
            'inject'   => '_jd_contact',
        ],
        'start'     => [
            'id_options' => [
                ['ids' => ['id', 'startId', 'start_id', 'placementId'],
                 'body_key' => 'startId', 'numeric' => true],
            ],
            'endpoint' => 'exact searchStart placement snapshot -> supported jobId/candidateid lookup',
            'inject'   => '_jd_start',
        ],
        'financial' => [
            'id_options' => [
                ['ids' => ['id', 'startId', 'start_id', 'placementId'],
                 'body_key' => 'startId', 'numeric' => true],
            ],
            'method'   => 'GET',
            'endpoint' => '/apiv2/bi/EmployeeAssignmentRecordsDetail',
            'inject'   => '_jd_assignment_detail',
        ],
    ];
    if (isset($opts['kinds']) && is_array($opts['kinds'])) {
        $requestedKinds = array_fill_keys(array_map('strval', $opts['kinds']), true);
        $configs = array_intersect_key($configs, $requestedKinds);
    }

    // Helper — try the kind's id_options in order against one payload.
    // Returns ['id'=>..., 'body_key'=>...] or null.
    $pluckIdOption = function (array $cfg, array $jd): ?array {
        foreach ($cfg['id_options'] as $opt) {
            $raw = jobdivaPluckField($jd, $opt['ids']);
            if ($raw === '' || $raw === null) continue;
            $raw = (string) $raw;
            if (!empty($opt['numeric'])) {
                if (!ctype_digit($raw) || (int) $raw <= 0) continue;
            } else {
                if (trim($raw) === '') continue;
            }
            return ['id' => $raw, 'body_key' => $opt['body_key']];
        }
        return null;
    };

    // Phase 1 — collect unique IDs per kind across the batch, paired
    // with the body_key each one needs (e.g. "27857851" → "jobId",
    // "26-03327" → "req").
    $idsByKind = [];
    $startHints = [];
    foreach ($configs as $kind => $cfg) {
        $idsByKind[$kind] = []; // id_string => body_key
        foreach ($items as $jd) {
            $pick = $pluckIdOption($cfg, $jd);
            if ($pick !== null) {
                $idsByKind[$kind][$pick['id']] = $pick['body_key'];
                if ($kind === 'start' || $kind === 'financial') {
                    $startHints[(string) $pick['id']] = jobdivaAssignmentStripDerivedFacets($jd);
                }
            }
        }
    }

    // Phase 2 — fetch each unique id once. Soft-fail per id; mark the
    // endpoint broken on first 4xx so we don't keep hammering it.
    $cache = [];       // [kind][id_string] => row (assoc array) | null on miss
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
        foreach ($idsByKind[$kind] as $id => $bodyKey) {
            // Don't re-call the start endpoint when the id matches the
            // own row's id (we already HAVE the searchStart payload —
            // it IS this row's payload). This avoids a 1:1 fan-out of
            // useless API calls for the most common pattern. Operators
            // Normal sync now keeps Start/Assignment enrichment on because
            // this is where JobDiva carries rates and vendor economics. The
            // `enrich_start=0` path exists only for tests or throttling.
            if ($kind === 'start' && empty($opts['enrich_start'])) {
                $diag[$kind]['skipped_self']++;
                continue;
            }
            if ($kind === 'financial'
                && array_key_exists('enrich_financial', $opts)
                && empty($opts['enrich_financial'])) {
                $diag[$kind]['skipped_self']++;
                continue;
            }

            if (!empty($brokenEndpoint[$cfg['endpoint']])) {
                $cache[$kind][$id] = null;
                continue;
            }
            $diag[$kind]['attempted']++;
            try {
                if ($kind === 'start') {
                    $hint = $startHints[(string) $id] ?? [];
                    $seed = jobdivaAssignmentMarkVerified(
                        $hint,
                        (string) $id,
                        'searchStart:placement_snapshot'
                    );
                    $seedIdentity = jobdivaAssignmentValidate(
                        $seed,
                        (string) $id,
                        'searchStart:placement_snapshot'
                    );
                    $exact = !empty($seedIdentity['valid'])
                        ? ['status' => 'verified', 'row' => $seed, 'error' => null]
                        : jobdivaFetchExactAssignmentById($tid, (string) $id, $hint);
                    if (($exact['status'] ?? '') === 'verified' && is_array($exact['row'] ?? null)) {
                        $cache[$kind][$id] = $exact['row'];
                        $diag[$kind]['succeeded']++;
                    } else {
                        $cache[$kind][$id] = null;
                        $diag[$kind]['empty_response']++;
                        if ($diag[$kind]['sample_error'] === null) {
                            $diag[$kind]['sample_error'] = substr(
                                (string) ($exact['error'] ?? 'Exact assignment detail was unavailable'),
                                0,
                                240
                            );
                        }
                    }
                    continue;
                }
                $method = strtoupper((string) ($cfg['method'] ?? 'POST'));
                if ($method === 'GET') {
                    $resp = jobdivaCall($tid, 'GET', $cfg['endpoint'], null, [
                        $bodyKey => $id,
                        'userFieldsName' => '',
                    ], $kind === 'financial' ? [400, 404] : []);
                } else {
                    $resp = jobdivaCall($tid, 'POST', $cfg['endpoint'], [$bodyKey => $id]);
                }
                $rows = jobdivaRowsFromResponse($resp);
                if ($kind === 'financial') {
                    $financialRows = array_values(array_filter($rows, 'is_array'));
                    if ($financialRows === []) {
                        $cache[$kind][$id] = null;
                        $diag[$kind]['empty_response']++;
                        continue;
                    }
                    $contract = jobdivaAssignmentContractBuild(
                        $financialRows,
                        $startHints[(string) $id] ?? [],
                        (string) $id
                    );
                    if ($contract !== []) {
                        $cache[$kind][$id] = [
                            'rows' => $financialRows,
                            'contract' => $contract,
                        ];
                        $diag[$kind]['succeeded']++;
                    } else {
                        $cache[$kind][$id] = null;
                        $diag[$kind]['empty_response']++;
                    }
                    continue;
                }
                if (is_array($rows) && count($rows) > 0 && is_array($rows[0])) {
                    $cache[$kind][$id] = $rows[0];
                    $diag[$kind]['succeeded']++;
                } else {
                    $cache[$kind][$id] = null;
                    $diag[$kind]['empty_response']++;
                }
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                error_log("[jobdiva] enrich {$kind} id={$id} body_key={$bodyKey} failed: {$msg}");
                $diag[$kind]['failed']++;
                if ($diag[$kind]['sample_error'] === null) {
                    $diag[$kind]['sample_error'] = substr($msg, 0, 240);
                }
                // Authentication/method failures apply to the whole endpoint.
                // Record-specific 400/404 responses must not suppress the rest
                // of the batch: some tenants retain historical Starts that the
                // financial-detail endpoint no longer returns.
                if (preg_match('/\b(?:401|403|405)\b/', $msg)) {
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
            $pick = $pluckIdOption($cfg, $jd);
            if ($pick === null) continue;
            $idStr = $pick['id'];
            if (!isset($cache[$kind][$idStr]) || $cache[$kind][$idStr] === null) continue;
            if ($kind === 'financial') {
                $financial = $cache[$kind][$idStr];
                $jd['_jd_assignment_detail'] = $financial['rows'] ?? [];
                $jd['_jd_contract'] = $financial['contract'] ?? [];
                continue;
            }
            $jd[$cfg['inject']] = $cache[$kind][$idStr];
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
 * Enrich and project a bounded page of stored JobDiva placements.
 *
 * EmployeeAssignmentRecordsDetail cannot be fetched in bulk. A cursor keeps
 * every HTTP request comfortably below the host execution limit while the UI
 * preserves one continuous Sync workflow for the operator.
 *
 * @return array{processed:int,projected:int,failed:int,errors:array<int,array<string,mixed>>,cursor:int,done:bool}
 */
function jobdivaSyncAssignmentContractsBatch(
    int $tenantId,
    ?int $userId,
    int $cursor = 0,
    int $limit = 8
): array {
    $cursor = max(0, $cursor);
    $limit = max(1, min(8, $limit));
    $result = [
        'processed' => 0,
        'projected' => 0,
        'failed' => 0,
        'errors' => [],
        'cursor' => $cursor,
        'done' => true,
    ];
    if ($tenantId <= 0) return $result;

    $pdo = getDB();
    $st = $pdo->prepare(
        "SELECT m.id, m.external_id, m.internal_entity_id, m.payload_snapshot,
                p.person_id AS existing_person_id
           FROM external_entity_mappings m
           LEFT JOIN placements p
             ON p.tenant_id = m.tenant_id
            AND p.id = m.internal_entity_id
          WHERE m.tenant_id = :tenant_id
            AND m.source_system = 'jobdiva'
            AND m.internal_entity_type = 'placement'
            AND m.sync_status = 'ok'
            AND m.payload_snapshot IS NOT NULL
            AND m.id > :cursor
          ORDER BY m.id ASC
          LIMIT {$limit}"
    );
    $st->execute(['tenant_id' => $tenantId, 'cursor' => $cursor]);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) return $result;

    $items = [];
    $meta = [];
    foreach ($rows as $row) {
        $mappingId = (int) ($row['id'] ?? 0);
        $result['cursor'] = max($result['cursor'], $mappingId);
        $externalId = jobdivaAssignmentIdentityNormaliseId((string) ($row['external_id'] ?? ''));
        $payload = json_decode((string) ($row['payload_snapshot'] ?? ''), true);
        if ($mappingId <= 0 || $externalId === '' || !is_array($payload)) {
            $result['failed']++;
            $result['errors'][] = ['mapping_id' => $mappingId, 'error' => 'Invalid stored placement payload'];
            continue;
        }
        $payload = jobdivaAssignmentSanitisePayload($payload, $externalId);
        $mirrorStats = [];
        $payload = jobdivaPlacementPayloadWithMirrors($tenantId, $payload, $mirrorStats, $externalId);
        $meta[] = [
            'mapping_id' => $mappingId,
            'external_id' => $externalId,
            'placement_id' => (int) ($row['internal_entity_id'] ?? 0),
            'person_id' => (int) ($row['existing_person_id'] ?? 0),
        ];
        $items[] = $payload;
    }

    if ($items !== []) {
        $diagnostics = null;
        $items = jobdivaSyncEnrichRelatedEntities(
            $tenantId,
            $items,
            $userId,
            [
                'kinds' => ['financial'],
                'enrich_start' => false,
                'enrich_financial' => true,
            ],
            $diagnostics
        );

        foreach ($items as $index => $payload) {
            $rowMeta = $meta[$index] ?? null;
            if (!is_array($rowMeta)) continue;
            $result['processed']++;
            $contract = $payload['_jd_contract'] ?? null;
            if (!is_array($contract) || $contract === []) {
                $result['failed']++;
                $result['errors'][] = [
                    'start_id' => $rowMeta['external_id'],
                    'error' => 'JobDiva returned no assignment financial detail',
                ];
                continue;
            }

            try {
                $payload = jobdivaCanonicalPlacementPayload(
                    $payload,
                    jobdivaExtractJoinedSubPayloads($payload)
                );
                $pdo->beginTransaction();
                $up = $pdo->prepare(
                    'UPDATE external_entity_mappings
                        SET payload_snapshot = :payload, updated_at = NOW()
                      WHERE id = :mapping_id AND tenant_id = :tenant_id'
                );
                $up->execute([
                    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    'mapping_id' => $rowMeta['mapping_id'],
                    'tenant_id' => $tenantId,
                ]);
                $projection = jobdivaProjectorProjectPlacement(
                    $tenantId,
                    $payload,
                    $userId,
                    [
                        'payload_is_enriched' => true,
                        'external_id' => $rowMeta['external_id'],
                        'existing_placement_id' => $rowMeta['placement_id'],
                        'person_id' => $rowMeta['person_id'],
                    ]
                );
                if (empty($projection['projected'])) {
                    throw new \RuntimeException(implode(
                        '; ',
                        array_map('strval', $projection['errors'] ?? ['projection failed'])
                    ));
                }
                $pdo->commit();
                $result['projected']++;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $result['failed']++;
                $result['errors'][] = [
                    'start_id' => $rowMeta['external_id'],
                    'error' => substr($e->getMessage(), 0, 300),
                ];
            }
        }
    }

    $result['done'] = count($rows) < $limit;
    jobdivaAudit($tenantId, 'sync_assignment_contracts_batch', [
        'entity_type' => 'jobdiva_assignment_contract',
        'direction' => 'pull',
        'ok' => $result['failed'] === 0,
        'items_processed' => $result['projected'],
        'items_skipped' => 0,
        'items_failed' => $result['failed'],
        'detail' => [
            'cursor' => $result['cursor'],
            'done' => $result['done'],
            'errors' => array_slice($result['errors'], 0, 10),
        ],
        'actor_user_id' => $userId,
    ]);
    return $result;
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
        '_jd_contract'  => 'assignment',
    ];
    foreach ($NESTED_MAP as $nestKey => $entityType) {
        if (isset($enriched[$nestKey]) && is_array($enriched[$nestKey]) && !empty($enriched[$nestKey])) {
            // Merge nested-record fields into the bucket. The nested record
            // is authoritative when both sources have the same key.
            $out[$entityType] = $enriched[$nestKey] + $out[$entityType];
        }
    }

    // 2) Flat prefix extraction — handles snake_case (job_id, candidate_first_name)
    //    AND camelCase (jobId, candidateFirstName, customerName). JobDiva's BI
    //    placement payload already carries joined-entity fields flat, so we
    //    surface them all under their natural entity_type even when the optional
    //    nested `_jd_*` enrichment endpoints fail (which is the common case).
    static $PREFIX_MAP = [
        // prefix (lowercase) → CoreFlux entity_type
        'candidate'  => 'person',
        'employee'   => 'person',
        'job'        => 'job',
        'customer'   => 'jobdiva_customer',
        'start'      => 'assignment',  // JobDiva calls assignment records "starts" in BI; payRate / billRate / markup live here.
        'assignment' => 'assignment',  // Some BI feeds prefix the same fields as `assignment_*` instead of `start_*`.
        // 'contact' is deliberately omitted from flat extraction — JobDiva
        // uses `job_contact_*` which we want under entity_type='job' (the
        // hiring contact is an attribute of the job, not a separate entity
        // at the BI level).
    ];

    // PASS 1 — prefix matches. Routes prefixed keys (`candidate id`,
    // `start pay rate`, `customerName`, …) into their joined-entity
    // buckets. Tracks which top-level keys did NOT match any prefix
    // so PASS 2 can apply the assignment-flavor heuristic to them.
    $unprefixed = [];
    foreach ($enriched as $k => $v) {
        if (!is_string($k)) continue;
        // Skip nested objects (handled above) + skip CoreFlux-internal keys.
        if (is_array($v)) continue;
        if (str_starts_with($k, '_jd_')) continue;
        if (str_starts_with($k, '__')) continue;

        $matchedPrefix = false;
        foreach ($PREFIX_MAP as $prefix => $entityType) {
            $stripped = null;
            // snake_case: candidate_first_name → first_name
            if (str_starts_with($k, $prefix . '_')) {
                $stripped = substr($k, strlen($prefix) + 1);
            }
            // space-separated: `candidate first name` → `first name` → `first_name`.
            //   JobDiva's V2 BI Placement endpoint returns flat keys with
            //   SPACES (e.g. `candidate id`, `job id`, `start pay rate`,
            //   `customer name`). Operators report this as "field mapping
            //   isn't right — pay rate isn't there." The indexer would
            //   normalize the spaces to `_` only at PATH level; the keys
            //   themselves stay space-separated in the raw payload, which
            //   is what we walk here. We must therefore match the space
            //   convention explicitly.
            elseif (str_starts_with($k, $prefix . ' ')) {
                $stripped = substr($k, strlen($prefix) + 1);
                $stripped = preg_replace('/\s+/', '_', trim($stripped)) ?? $stripped;
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
                $matchedPrefix = true;
                break; // matched one prefix; don't try others.
            }
        }
        if (!$matchedPrefix) {
            $unprefixed[$k] = $v;
        }
    }

    // PASS 2 — assignment-flavor heuristic for unprefixed top-level
    // scalars only. Runs AFTER the prefix pass so prefix-specific
    // matches (e.g. `start pay rate` → assignment.pay_rate=88) win
    // over generic top-level matches (`pay rate` → assignment.pay_rate).
    //
    // Operator ask: "I want every single available data point to
    // come across and become mappable. a + c."
    //
    // JobDiva's V2 BI Placement payload flattens MANY assignment-
    // record fields onto the placement WITHOUT any prefix —
    // `final bill rate`, `agreed pay rate`, `quoted bill rate`,
    // `pay rate currency/unit`, `pay agreed date`, `start date`,
    // `end date`. Operators expect to find these under the
    // `assignment` bucket (where JobDiva's UI shows them as
    // Assignment-record fields), not buried in placement.
    $assignmentKeywords = [
        'rate', 'pay', 'bill', 'markup', 'salary', 'overtime',
        'doubletime', 'commission', 'vms ', 'hourly', 'currency',
    ];
    $assignmentExactWhole = [
        'start date', 'end date', 'hire date',
        'pay agreed date', 'startstatus',
    ];
    foreach ($unprefixed as $k => $v) {
        $lowerK = strtolower($k);
        $isAssignmentFlavor = false;
        foreach ($assignmentKeywords as $kw) {
            if (str_contains($lowerK, $kw)) {
                $isAssignmentFlavor = true;
                break;
            }
        }
        if (!$isAssignmentFlavor && in_array($lowerK, $assignmentExactWhole, true)) {
            $isAssignmentFlavor = true;
        }
        if ($isAssignmentFlavor) {
            // Normalize the key into a snake_case path safe for the
            // indexer: lowercase, every non-alphanumeric → `_`,
            // trimmed of leading/trailing `_`.
            $norm = preg_replace('/[^A-Za-z0-9_]+/', '_', trim($lowerK)) ?? '';
            $norm = trim($norm, '_');
            if ($norm !== '' && !array_key_exists($norm, $out['assignment'])) {
                $out['assignment'][$norm] = $v;
            }
        }
    }

    // Drop empty buckets — callers shouldn't care about them.
    return array_filter($out, fn($sub) => !empty($sub));
}

/**
 * Index every joined sub-record of an enriched placement payload UNDER
 * ITS OWN entity_type so the Field Mapping Studio surfaces paths for
 * canonical `person`, `company`, `contact`, and `placement` roots even
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
            foreach (jobdivaCanonicalFieldIndexEntityTypes($entityType) as $indexEntityType) {
                $payloadForIndex = jobdivaCanonicalPayloadForEntity($entityType, $indexEntityType, $subPayload);
                integrationPayloadFieldIndexRecord($tenantId, 'jobdiva', $indexEntityType, $payloadForIndex);
            }
        } catch (\Throwable $e) {
            error_log('[jobdivaIndexJoinedSubPayloads] ' . $entityType . ' index failed: ' . $e->getMessage());
        }
    }
}

function jobdivaPlacementStaffingJobId(int $tenantId, int $placementId): int
{
    if ($tenantId <= 0 || $placementId <= 0) return 0;
    try {
        $st = getDB()->prepare(
            'SELECT staffing_job_id
               FROM placements
              WHERE tenant_id = :t AND id = :id
              LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'id' => $placementId]);
        return (int) $st->fetchColumn();
    } catch (\Throwable $e) {
        error_log('[jobdiva placement mapping context] staffing_job_id failed: ' . $e->getMessage());
        return 0;
    }
}

function jobdivaPlacementCurrentRateId(int $tenantId, int $placementId): int
{
    if ($tenantId <= 0 || $placementId <= 0) return 0;
    try {
        $st = getDB()->prepare(
            'SELECT id
               FROM placement_rates
              WHERE tenant_id = :t
                AND placement_id = :p
                AND effective_to IS NULL
              ORDER BY (approved_at IS NULL) DESC, effective_from DESC, id DESC
              LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'p' => $placementId]);
        return (int) $st->fetchColumn();
    } catch (\Throwable $e) {
        error_log('[jobdiva placement mapping context] placement_rates id failed: ' . $e->getMessage());
        return 0;
    }
}

function jobdivaPlacementChainContextIds(int $tenantId, int $placementId): array
{
    $ctx = [
        'placement_chain_end_client' => 0,
        'placement_chain_msp' => 0,
        'placement_chain_prime_vendor' => 0,
        'placement_chain_sub_vendor' => 0,
        'placement_chain_direct' => 0,
    ];
    if ($tenantId <= 0 || $placementId <= 0) return $ctx;
    try {
        $st = getDB()->prepare(
            'SELECT id, party_role
               FROM placement_client_chain
              WHERE tenant_id = :t
                AND placement_id = :p
              ORDER BY position ASC, id ASC'
        );
        $st->execute(['t' => $tenantId, 'p' => $placementId]);
        while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
            $role = (string) ($row['party_role'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || $role === '') continue;
            $key = 'placement_chain_' . $role;
            if (array_key_exists($key, $ctx) && (int) $ctx[$key] <= 0) {
                $ctx[$key] = $id;
            }
        }
    } catch (\Throwable $e) {
        error_log('[jobdiva placement mapping context] placement_client_chain ids failed: ' . $e->getMessage());
    }
    return $ctx;
}

function jobdivaPlacementCommissionContextIds(int $tenantId, int $placementId): array
{
    $ctx = [
        'placement_commission_recruiter' => 0,
        'placement_commission_account_manager' => 0,
        'placement_commission_lead' => 0,
        'placement_commission_team' => 0,
        'placement_commission_other' => 0,
    ];
    if ($tenantId <= 0 || $placementId <= 0) return $ctx;
    try {
        $st = getDB()->prepare(
            'SELECT id, role
               FROM placement_commissions
              WHERE tenant_id = :t
                AND placement_id = :p
              ORDER BY effective_from DESC, id ASC'
        );
        $st->execute(['t' => $tenantId, 'p' => $placementId]);
        while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
            $role = (string) ($row['role'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || $role === '') continue;
            $key = 'placement_commission_' . $role;
            if (array_key_exists($key, $ctx) && (int) $ctx[$key] <= 0) {
                $ctx[$key] = $id;
            }
        }
    } catch (\Throwable $e) {
        error_log('[jobdiva placement mapping context] placement_commissions ids failed: ' . $e->getMessage());
    }
    return $ctx;
}

function jobdivaPlacementReferralContextId(int $tenantId, int $placementId): int
{
    if ($tenantId <= 0 || $placementId <= 0) return 0;
    try {
        $st = getDB()->prepare(
            'SELECT id
               FROM placement_referrals
              WHERE tenant_id = :t AND placement_id = :p
              ORDER BY CASE
                         WHEN notes LIKE "Source: JobDiva referral projection%" THEN 0
                         WHEN notes LIKE "Source: %integration%projection%" THEN 1
                         ELSE 2
                       END,
                       id ASC
              LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'p' => $placementId]);
        return (int) $st->fetchColumn();
    } catch (\Throwable $e) {
        error_log('[jobdiva placement mapping context] placement_referrals id failed: ' . $e->getMessage());
        return 0;
    }
}

function jobdivaApplyPlacementFieldMappings(
    int $tenantId,
    int $placementId,
    int $personId,
    ?int $endClientCompanyId,
    int $staffingJobId,
    array $payload,
    int $contactId = 0
): array {
    $summary = ['attempted' => 0, 'written' => 0, 'skipped' => 0, 'errors' => []];
    if ($tenantId <= 0 || $placementId <= 0) return $summary;

    require_once __DIR__ . '/../integrations/field_map_apply.php';

    $baseCtx = [
        'self'                   => $placementId,
        'placement'              => $placementId,
        'placement_rates'        => jobdivaPlacementCurrentRateId($tenantId, $placementId),
        'placement_corp_details' => $placementId,
        'placement_referral'     => jobdivaPlacementReferralContextId($tenantId, $placementId),
        'person'                 => $personId,
        'end_client_company'     => $endClientCompanyId ?? 0,
        'company'                => $endClientCompanyId ?? 0,
        'jobdiva_customer'       => $endClientCompanyId ?? 0,
        'staffing_job'           => $staffingJobId,
        'job'                    => $staffingJobId,
        'jobdiva_job'            => $staffingJobId,
        'contact'                => $contactId,
        'jobdiva_contact'        => $contactId,
    ];
    $baseCtx = array_merge(
        $baseCtx,
        jobdivaPlacementChainContextIds($tenantId, $placementId),
        jobdivaPlacementCommissionContextIds($tenantId, $placementId)
    );

    $mergeSummary = static function (array $part) use (&$summary): void {
        $summary['attempted'] += (int) ($part['attempted'] ?? 0);
        $summary['written']   += (int) ($part['written'] ?? 0);
        $summary['skipped']   += (int) ($part['skipped'] ?? 0);
        foreach (($part['errors'] ?? []) as $err) {
            $summary['errors'][] = (string) $err;
        }
    };

    $apply = static function (string $entityType, array $payloadForApply, array $ctx) use (
        $tenantId,
        &$summary,
        $mergeSummary
    ): void {
        try {
            $mergeSummary(integrationFieldMapApplyAll($tenantId, 'jobdiva', $entityType, $payloadForApply, $ctx));
        } catch (\Throwable $e) {
            $msg = '[jobdiva placement field_map] applyAll ' . $entityType . ' failed: ' . $e->getMessage();
            error_log($msg);
            $summary['errors'][] = $msg;
        }
    };

    $apply('placement', $payload, $baseCtx);

    static $JOINED_CTX = [
        'person'           => 'person',
        'job'              => 'self',
        'jobdiva_customer' => 'end_client_company',
        'contact'          => 'contact',
        'assignment'       => 'self',
    ];
    foreach (jobdivaExtractJoinedSubPayloads($payload) as $joinedEntity => $subPayload) {
        if (empty($subPayload)) continue;
        $defaultOwner = $JOINED_CTX[$joinedEntity] ?? 'self';
        foreach (jobdivaCanonicalApplyEntityTypes($joinedEntity) as $mapEntityType) {
            $owner = in_array($mapEntityType, ['staffing_job', 'jobdiva_job'], true)
                ? 'staffing_job'
                : $defaultOwner;
            $ctx = $baseCtx;
            $ctx['self'] = match ($owner) {
                'person'             => $baseCtx['person'],
                'end_client_company' => $baseCtx['end_client_company'],
                'staffing_job'       => $baseCtx['staffing_job'],
                'contact'            => $baseCtx['contact'],
                default              => $baseCtx['placement'],
            };
            $payloadForApply = jobdivaCanonicalPayloadForEntity($joinedEntity, $mapEntityType, $subPayload);
            $apply($mapEntityType, $payloadForApply, $ctx);
        }
    }

    return $summary;
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
            'placement'        => 0,
            'staffing_job'     => 0,
            'person'           => 0,
            'company'          => 0,
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
            "SELECT id, external_id, payload_snapshot
               FROM external_entity_mappings
              WHERE tenant_id = :t
                AND source_system = 'jobdiva'
                AND internal_entity_type = 'placement'
                AND sync_status = 'ok'
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
        $externalId = jobdivaAssignmentIdentityNormaliseId((string) ($row['external_id'] ?? ''));
        if ($externalId === '') continue;
        $payload = jobdivaAssignmentSanitisePayload($payload, $externalId);
        if (function_exists('jobdivaPlacementPayloadWithMirrors')) {
            $mirrorStats = [];
            $payload = jobdivaPlacementPayloadWithMirrors(
                $tenantId,
                $payload,
                $mirrorStats,
                $externalId
            );
        }
        $placements[] = [
            'placement_index' => count($placements),
            'mapping_id' => (int) $row['id'],
            'external_id' => $externalId,
            'payload' => $payload,
        ];
    }
    if (empty($placements)) return $summary;

    // Phase 2 — enrich any placements that don't already carry the full
    // joined sub-records. The enricher batches by unique id per kind,
    // marks 4xx endpoints broken after the first miss, and short-circuits
    // when nothing's missing. This is the step that pulls the FULL job /
    // candidate / customer / contact rows out of JobDiva so the picker
    // surfaces complete schemas instead of just flat ref-number stubs.
    $needsEnrichment = array_values(array_filter($placements, function ($p) {
        $jd = $p['payload'];
        return empty($jd['_jd_job']) || empty($jd['_jd_candidate'])
            || empty($jd['_jd_customer'])
            || empty($jd['_jd_start'])
            || empty($jd['_jd_contract']);
    }));
    $enrichmentRanFor = 0;
    $enrichmentBroken = [];
    $enrichDiag = null;
    if (!empty($needsEnrichment)) {
        try {
            $items = array_map(fn($p) => $p['payload'], $needsEnrichment);
            $enrichDiag = null;
            // The exact Start proves placement identity. The financial BI
            // detail supplies the commercial contract and overheads. Both
            // facets must be refreshed before replaying canonical graphs.
            $enriched = jobdivaSyncEnrichRelatedEntities(
                $tenantId, $items, null,
                ['enrich_start' => 1],
                $enrichDiag
            );
            // Re-stitch enriched results back into $placements by index +
            // re-save the enriched payload to external_entity_mappings so
            // the next backfill / sync sees the full record.
            foreach ($needsEnrichment as $i => $p) {
                if (!isset($enriched[$i])) continue;
                $newPayload = $enriched[$i];
                if (is_array($newPayload)) {
                    $externalId = (string) ($p['external_id'] ?? '');
                    $newPayload = jobdivaAssignmentSanitisePayload($newPayload, $externalId);
                    $mirrorStats = [];
                    $newPayload = jobdivaPlacementPayloadWithMirrors(
                        $tenantId,
                        $newPayload,
                        $mirrorStats,
                        $externalId
                    );
                    $placements[$p['placement_index']]['payload'] = $newPayload;
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
                foreach (jobdivaCanonicalFieldIndexEntityTypes($entityType) as $indexEntityType) {
                    $payloadForIndex = jobdivaCanonicalPayloadForEntity($entityType, $indexEntityType, $sub);
                    $n = integrationPayloadFieldIndexRecord($tenantId, 'jobdiva', $indexEntityType, $payloadForIndex);
                    if ($n > 0 && isset($summary['sub_records_indexed'][$indexEntityType])) {
                        $summary['sub_records_indexed'][$indexEntityType]++;
                    }
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

function jobdivaMirrorPayloadByExternalId(int $tenantId, string $entityType, string $externalId): ?array
{
    $externalId = trim($externalId);
    if ($tenantId <= 0 || $entityType === '' || $externalId === '') return null;
    try {
        $st = getDB()->prepare(
            "SELECT payload_snapshot
               FROM external_entity_mappings
              WHERE tenant_id = :t
                AND source_system = 'jobdiva'
                AND internal_entity_type = :et
                AND external_id = :eid
                AND sync_status = 'ok'
                AND payload_snapshot IS NOT NULL
              LIMIT 1"
        );
        $st->execute(['t' => $tenantId, 'et' => $entityType, 'eid' => $externalId]);
        $snap = $st->fetchColumn();
        if (!is_string($snap) || $snap === '') return null;
        $decoded = json_decode($snap, true);
        return is_array($decoded) ? $decoded : null;
    } catch (\Throwable $e) {
        error_log('[jobdiva mirror payload lookup] ' . $entityType . ' failed: ' . $e->getMessage());
        return null;
    }
}

function jobdivaPlacementPayloadWithMirrors(
    int $tenantId,
    array $payload,
    array &$stats = [],
    ?string $expectedStartId = null
): array
{
    $stats += [
        'jobs_joined'        => 0,
        'candidates_joined'  => 0,
        'contacts_joined'    => 0,
        'companies_joined'   => 0,
        'assignments_joined' => 0,
        'stale_facets_removed'=> 0,
    ];

    $expectedStartId = jobdivaAssignmentIdentityNormaliseId(
        $expectedStartId ?? jobdivaAssignmentRowId($payload)
    );
    $payload = jobdivaAssignmentSanitisePayload($payload, $expectedStartId);

    // Resolve relation identities from the root Start only. Derived facets
    // are precisely what may be stale, so they cannot choose their own join.
    $jobId = jobdivaPluckField($payload, ['job id', 'jobId', 'job_id', 'jobID', 'JOBID']);
    $candidateId = jobdivaPluckField($payload, [
        'candidate id', 'candidateId', 'candidate_id', 'candidateID', 'CANDIDATEID', 'employeeId',
    ]);
    $contactId = jobdivaPluckField($payload, [
        'job contact id', 'jobContactId', 'job_contact_id',
        'contactId', 'contact id', 'contact_id',
    ]);

    $dropMismatchedFacet = static function (
        array &$target,
        array $keys,
        string $expectedId,
        array $idCandidates,
        array &$targetStats
    ): void {
        if ($expectedId === '') return;
        foreach ($keys as $key) {
            if (!isset($target[$key]) || !is_array($target[$key])) continue;
            $facet = $target[$key];
            $facetId = jobdivaAssignmentFacetIsList($facet)
                ? ''
                : jobdivaPluckField($facet, $idCandidates);
            if ($facetId !== $expectedId) {
                unset($target[$key]);
                $targetStats['stale_facets_removed']++;
            }
        }
    };
    $dropMismatchedFacet(
        $payload,
        ['_jd_job', 'job', 'Job', 'jobInfo', 'jobObj', 'jobRecord', 'staffing_job'],
        $jobId,
        ['job id', 'jobId', 'job_id', 'jobID', 'JOBID', 'id'],
        $stats
    );
    $dropMismatchedFacet(
        $payload,
        ['_jd_candidate', 'person', 'candidate', 'Candidate', 'employee', 'worker', 'jobdiva_candidate'],
        $candidateId,
        ['candidate id', 'candidateId', 'candidate_id', 'candidateID', 'CANDIDATEID', 'employeeId', 'id'],
        $stats
    );
    $dropMismatchedFacet(
        $payload,
        ['_jd_contact', 'contact', 'Contact', 'jobdiva_contact'],
        $contactId,
        ['job contact id', 'jobContactId', 'job_contact_id', 'contactId', 'contact id', 'contact_id', 'id'],
        $stats
    );

    if ($jobId !== '') {
        $job = jobdivaMirrorPayloadByExternalId($tenantId, 'jobdiva_job', $jobId);
        if ($job) {
            $payload['_jd_job'] = $job;
            $payload['job'] = $job;
            $stats['jobs_joined']++;
        }
    }

    // Resolve the company only from a real company identity. JobDiva's
    // shallow `customer id` is frequently a contact id and is never safe for
    // this join. A companyId on the Start or its exact Job mirror is safe.
    $companyId = jobdivaPluckField($payload, [
        'companyId', 'company_id', 'company id', 'companyID', 'COMPANYID',
        'endClientCompanyId', 'end_client_company_id',
    ]);
    if ($companyId === '') {
        $companyId = jobdivaPluckNestedField(
            $payload,
            ['companyId', 'company_id', 'company id', 'companyID', 'COMPANYID', 'clientCompanyId'],
            ['_jd_job', 'job', 'Job', 'jobInfo', 'jobObj', 'jobRecord']
        );
    }
    if ($companyId !== '') {
        $company = jobdivaMirrorPayloadByExternalId($tenantId, 'company', $companyId);
        if ($company) {
            $payload['_jd_customer'] = $company;
            $stats['companies_joined']++;
        }
    }

    if ($candidateId !== '') {
        $candidate = jobdivaMirrorPayloadByExternalId($tenantId, 'jobdiva_candidate', $candidateId);
        if ($candidate) {
            $payload['_jd_candidate'] = $candidate;
            $stats['candidates_joined']++;
        }
    }

    if ($contactId !== '') {
        $contact = jobdivaMirrorPayloadByExternalId($tenantId, 'jobdiva_contact', $contactId);
        if ($contact) {
            $payload['_jd_contact'] = $contact;
            $stats['contacts_joined']++;
        }
    }

    $startId = $expectedStartId;
    if ($startId !== '') {
        $assignment = jobdivaMirrorPayloadByExternalId($tenantId, 'jobdiva_assignment', $startId);
        if ($assignment) {
            $identity = jobdivaAssignmentValidate($assignment, $startId);
            $context = jobdivaAssignmentContextEvidence($assignment, $payload);
            if (!empty($identity['valid']) && !empty($context['matches'])) {
                $payload['_jd_start'] = $assignment;
                $payload['assignment'] = $assignment;
                $stats['assignments_joined']++;
            }
        }
    }

    $payload = jobdivaAssignmentSanitisePayload($payload, $expectedStartId);
    return jobdivaCanonicalPlacementPayload($payload, jobdivaExtractJoinedSubPayloads($payload));
}

function jobdivaReprojectMirroredPlacementGraphs(int $tenantId, ?int $userId, int $limit = 1000): array
{
    $summary = [
        'placements_seen'     => 0,
        'placements_projected'=> 0,
        'jobs_joined'         => 0,
        'candidates_joined'   => 0,
        'contacts_joined'     => 0,
        'companies_joined'    => 0,
        'assignments_joined'  => 0,
        'stale_facets_removed'=> 0,
        'mapping_writes'      => 0,
        'field_map_writes'    => 0,
        'errors'              => [],
    ];
    if ($tenantId <= 0) return $summary;

    try {
        $pdo = getDB();
        $st = $pdo->prepare(
            "SELECT m.external_id, m.payload_snapshot, m.internal_entity_id AS placement_id,
                    p.person_id, p.end_client_company_id, p.staffing_job_id
               FROM external_entity_mappings m
               JOIN placements p
                 ON p.tenant_id = m.tenant_id
                AND p.id = m.internal_entity_id
              WHERE m.tenant_id = :t
                AND m.source_system = 'jobdiva'
                AND m.internal_entity_type = 'placement'
                AND m.sync_status = 'ok'
                AND m.payload_snapshot IS NOT NULL
                AND (p.deleted_at IS NULL OR p.deleted_at = '0000-00-00 00:00:00')
              ORDER BY m.updated_at DESC, m.id DESC
              LIMIT " . max(1, min(5000, $limit))
        );
        $st->execute(['t' => $tenantId]);
    } catch (\Throwable $e) {
        $summary['errors'][] = 'query_failed: ' . $e->getMessage();
        return $summary;
    }

    while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
        $summary['placements_seen']++;
        try {
            $payload = json_decode((string) ($row['payload_snapshot'] ?? ''), true);
            if (!is_array($payload)) continue;

            $placementId = (int) ($row['placement_id'] ?? 0);
            $externalId = jobdivaAssignmentIdentityNormaliseId((string) ($row['external_id'] ?? ''));
            if ($placementId <= 0 || $externalId === '') continue;

            $joinStats = [];
            $payload = jobdivaPlacementPayloadWithMirrors(
                $tenantId,
                $payload,
                $joinStats,
                $externalId
            );
            foreach ([
                'jobs_joined',
                'candidates_joined',
                'contacts_joined',
                'companies_joined',
                'assignments_joined',
                'stale_facets_removed',
            ] as $k) {
                $summary[$k] += (int) ($joinStats[$k] ?? 0);
            }

            $projection = jobdivaProjectorProjectPlacement($tenantId, $payload, $userId, [
                'payload_is_enriched' => true,
                'external_id' => $externalId,
                'existing_placement_id' => $placementId,
                'person_id' => (int) ($row['person_id'] ?? 0),
            ]);
            if (empty($projection['projected'])) {
                throw new \RuntimeException(implode('; ', $projection['errors'] ?? ['projection failed']));
            }
            $summary['mapping_writes'] += (int) ($projection['mapping_writes'] ?? 0);
            $summary['field_map_writes'] += (int) ($projection['field_map']['written'] ?? 0);
            $summary['placements_projected']++;
        } catch (\Throwable $e) {
            $summary['errors'][] = [
                'placement_id' => (int) ($row['placement_id'] ?? 0),
                'error' => substr($e->getMessage(), 0, 240),
            ];
            if (count($summary['errors']) >= 20) break;
        }
    }

    return $summary;
}

/**
 * Build an operator-readable projection plan from the authoritative stored
 * JobDiva Start/Assignment mirrors.
 *
 * A placement snapshot is a projection result, not source identity. Starting
 * here prevents deleted, duplicated, or stale CoreFlux placements from
 * deciding which JobDiva assignments exist.
 */
function jobdivaStoredAssignmentProjectionPlan(
    int $tenantId,
    int $limit = 5000,
    array $onlyStartIds = []
): array {
    require_once __DIR__ . '/../integrations/field_map.php';
    $limit = max(1, min(5000, $limit));
    $onlyLookup = [];
    foreach ($onlyStartIds as $startId) {
        $normalised = jobdivaAssignmentIdentityNormaliseId((string) $startId);
        if ($normalised !== '') $onlyLookup[$normalised] = true;
    }

    $summary = [
        'assignments' => 0,
        'create' => 0,
        'update' => 0,
        'restore' => 0,
        'blocked' => 0,
        'fully_joined' => 0,
        'missing_job' => 0,
        'missing_candidate' => 0,
        'missing_contact' => 0,
        'missing_company' => 0,
        'with_rates' => 0,
        'with_economic_participants' => 0,
    ];
    $rows = [];
    if ($tenantId <= 0) {
        return ['summary' => $summary, 'rows' => [], 'dry_run_token' => hash('sha256', 'invalid-tenant')];
    }

    $pdo = getDB();
    $st = $pdo->prepare(
        "SELECT external_id, payload_snapshot, updated_at
           FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = 'jobdiva'
            AND internal_entity_type = 'jobdiva_assignment'
            AND sync_status = 'ok'
            AND payload_snapshot IS NOT NULL
          ORDER BY updated_at DESC, id DESC
          LIMIT {$limit}"
    );
    $st->execute(['t' => $tenantId]);

    while ($sourceRow = $st->fetch(\PDO::FETCH_ASSOC)) {
        $startId = jobdivaAssignmentIdentityNormaliseId((string) ($sourceRow['external_id'] ?? ''));
        if ($onlyLookup && !isset($onlyLookup[$startId])) continue;
        $summary['assignments']++;

        $errors = [];
        $warnings = [];
        $payload = json_decode((string) ($sourceRow['payload_snapshot'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
            $errors[] = 'Stored assignment payload is not valid JSON.';
        }

        $identity = $payload ? jobdivaAssignmentValidate($payload, $startId, 'stored_assignment_preview') : [
            'valid' => false,
            'reason' => 'missing_payload',
        ];
        if (empty($identity['valid'])) {
            $errors[] = 'Assignment identity is not verified: ' . (string) ($identity['reason'] ?? 'unknown reason');
        }

        $joinStats = [];
        $enriched = $payload
            ? jobdivaPlacementPayloadWithMirrors($tenantId, $payload, $joinStats, $startId)
            : [];
        $enrichedIdentity = $enriched
            ? jobdivaAssignmentValidate($enriched, $startId, 'stored_assignment_preview')
            : ['valid' => false, 'reason' => 'missing_payload'];
        if ($payload && empty($enrichedIdentity['valid'])) {
            $errors[] = 'Joined graph failed exact assignment validation: '
                . (string) ($enrichedIdentity['reason'] ?? 'unknown reason');
        }
        $mapped = static function (
            string $internalField,
            callable $fallback
        ) use ($tenantId, $enriched): mixed {
            return tenantIntegrationFieldMapPluckInternal(
                $tenantId,
                'jobdiva',
                'placement',
                $internalField,
                $enriched,
                $fallback
            );
        };

        $candidateId = $enriched ? jobdivaPluckFieldDeep($enriched, [
            'candidate id', 'candidateId', 'candidate_id', 'candidateID', 'CANDIDATEID', 'employeeId',
        ]) : '';
        $jobId = $enriched ? jobdivaPluckFieldDeep($enriched, [
            'job id', 'jobId', 'job_id', 'jobID', 'JOBID', 'reqId', 'req_id',
        ]) : '';
        $contactId = $enriched ? jobdivaPluckFieldDeep($enriched, [
            'job contact id', 'jobContactId', 'job_contact_id', 'contactId', 'contact id', 'contact_id',
        ]) : '';
        $companyId = $enriched ? jobdivaPluckField($enriched, [
            'companyId', 'company_id', 'company id', 'companyID', 'COMPANYID',
            'endClientCompanyId', 'end_client_company_id',
        ]) : '';
        if ($companyId === '' && $enriched) {
            $companyId = jobdivaPluckNestedField(
                $enriched,
                ['companyId', 'company_id', 'company id', 'companyID', 'COMPANYID', 'clientCompanyId'],
                ['_jd_job', 'job', 'Job', 'jobInfo', 'jobObj', 'jobRecord']
            );
        }
        $startDateRaw = $enriched ? jobdivaPluckFieldDeep($enriched, [
            'startDate', 'start_date', 'start date', 'startdate',
        ]) : '';
        $startDate = $startDateRaw !== '' ? jobdivaNormaliseDate($startDateRaw) : null;
        if ($candidateId === '') $errors[] = 'Assignment has no candidate identity.';
        if ($startDate === null) $errors[] = 'Assignment has no usable start date.';
        if ($jobId !== '' && empty($joinStats['jobs_joined'])) $warnings[] = "Job {$jobId} is referenced but its mirror is unavailable.";
        if ($candidateId !== '' && empty($joinStats['candidates_joined'])) {
            $warnings[] = "Candidate {$candidateId} is referenced but its mirror is unavailable.";
        }
        if ($contactId !== '' && empty($joinStats['contacts_joined'])) {
            $warnings[] = "Contact {$contactId} is referenced but its mirror is unavailable.";
        }
        if ($companyId !== '' && empty($joinStats['companies_joined'])) {
            $warnings[] = "Company {$companyId} is referenced but its canonical company mirror is unavailable.";
        }

        $placementMapping = mappingFindInternal($tenantId, 'jobdiva', 'placement', $startId);
        $mappedPlacementId = (int) ($placementMapping['internal_entity_id'] ?? 0);
        $placementId = $mappedPlacementId;
        $current = [];
        $currentGraph = [];
        $exactPlacementStmt = $pdo->prepare(
            'SELECT id, deleted_at
               FROM placements
              WHERE tenant_id = :t
                AND external_id IN (:canonical, :raw)
              ORDER BY id ASC'
        );
        $exactPlacementStmt->execute([
            't' => $tenantId,
            'canonical' => 'jd:' . $startId,
            'raw' => $startId,
        ]);
        $exactPlacementRows = $exactPlacementStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $activeExactIds = [];
        $archivedExactIds = [];
        foreach ($exactPlacementRows as $exactPlacementRow) {
            $exactId = (int) ($exactPlacementRow['id'] ?? 0);
            if ($exactId <= 0) continue;
            $deletedAt = (string) ($exactPlacementRow['deleted_at'] ?? '');
            if ($deletedAt === '' || $deletedAt === '0000-00-00 00:00:00') {
                $activeExactIds[] = $exactId;
            } else {
                $archivedExactIds[] = $exactId;
            }
        }
        if (count($activeExactIds) > 1) {
            $errors[] = 'More than one active CoreFlux placement has this exact JobDiva external identity: '
                . implode(', ', $activeExactIds) . '.';
        } elseif (count($activeExactIds) === 1) {
            $placementId = $activeExactIds[0];
            if ($mappedPlacementId !== $placementId) {
                $warnings[] = "Exact active placement {$placementId} will replace the stale or missing JobDiva binding.";
            }
        } elseif ($mappedPlacementId <= 0 && count($archivedExactIds) === 1) {
            $placementId = $archivedExactIds[0];
            $warnings[] = "Exact archived placement {$placementId} was found without its JobDiva binding.";
        } elseif ($mappedPlacementId <= 0 && count($archivedExactIds) > 1) {
            $errors[] = 'More than one archived CoreFlux placement has this exact JobDiva external identity: '
                . implode(', ', $archivedExactIds) . '.';
        }
        if ($placementId > 0) {
            $placementStmt = $pdo->prepare(
                'SELECT id, external_id, title, status, engagement_type, start_date, end_date,
                        end_client_name, person_id, end_client_company_id, staffing_job_id, deleted_at
                   FROM placements
                  WHERE tenant_id = :t AND id = :id
                  LIMIT 1'
            );
            $placementStmt->execute(['t' => $tenantId, 'id' => $placementId]);
            $current = $placementStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            if (!$current) {
                $errors[] = "Placement mapping points to missing CoreFlux placement {$placementId}.";
            } else {
                $currentGraph = jobdivaPlacementProjectionAuditSnapshot($tenantId, $placementId);
                try {
                    $timeStmt = $pdo->prepare(
                        'SELECT COUNT(*) AS row_count, COALESCE(SUM(hours), 0) AS total_hours,
                                MAX(updated_at) AS latest_update
                           FROM time_entries
                          WHERE tenant_id = :t AND placement_id = :p'
                    );
                    $timeStmt->execute(['t' => $tenantId, 'p' => $placementId]);
                    $currentGraph['time_summary'] = $timeStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
                } catch (\Throwable $e) {
                    $currentGraph['time_summary'] = [];
                }
                try {
                    $feedStmt = $pdo->prepare(
                        'SELECT COUNT(*) AS row_count,
                                COALESCE(SUM(total_amount_bill), 0) AS total_bill,
                                COALESCE(SUM(total_amount_pay), 0) AS total_pay
                           FROM time_downstream_feed
                          WHERE tenant_id = :t AND placement_id = :p'
                    );
                    $feedStmt->execute(['t' => $tenantId, 'p' => $placementId]);
                    $currentGraph['downstream_summary'] = $feedStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
                } catch (\Throwable $e) {
                    $currentGraph['downstream_summary'] = [];
                }
            }
        }

        $title = $enriched ? (string) $mapped(
            'title',
            static fn() => jobdivaPluckFieldDeep($enriched, [
                'jobTitle', 'job_title', 'job title', 'positionTitle', 'position_title', 'role', 'roleName', 'title',
            ])
        ) : '';
        $candidateName = $enriched ? jobdivaPluckFieldDeep($enriched, [
            'candidateName', 'candidate_name', 'candidate name', 'fullName', 'full_name', 'name',
        ]) : '';
        $candidateEmail = $enriched ? jobdivaPluckFieldDeep($enriched, [
            'candidateEmail', 'candidate_email', 'candidate email', 'email', 'emailAddress', 'email_address',
        ]) : '';
        $endClientName = $enriched ? (string) $mapped(
            'end_client_name',
            static fn() => jobdivaEndClientNameFromPayload($enriched)
        ) : '';
        $engagementRaw = $enriched ? (string) $mapped(
            'engagement_type',
            static fn() => jobdivaPluckFieldDeep($enriched, [
                'engagementType', 'engagement_type', 'workerType', 'worker_type',
                'classification', 'employmentType', 'employment type',
                'employmentCategory', 'employment_category', 'EMPLOYMENT_CATEGORY',
                'positionType', 'position_type', 'position type',
            ])
        ) : '';
        $engagement = $enriched
            ? jobdivaNormalisePlacementEngagementType(
                $engagementRaw,
                jobdivaInferPlacementEngagementTypeFromPayload($enriched, '')
            )
            : '';
        $endDateRaw = $enriched ? jobdivaPluckFieldDeep($enriched, [
            'endDate', 'end_date', 'end date', 'enddate',
        ]) : '';
        $billRate = $enriched ? jobdivaParseRateAmount($mapped(
            'bill_rate',
            static fn() => jobdivaPluckFieldDeep($enriched, [
                'final bill rate', 'finalBillRate', 'final_bill_rate', 'bill rate', 'billRate', 'bill_rate',
                'client bill rate', 'clientBillRate', 'client_bill_rate',
            ])
        )) : 0.0;
        $payRate = $enriched ? jobdivaParseRateAmount($mapped(
            'pay_rate',
            static fn() => jobdivaPluckFieldDeep($enriched, [
                'agreed pay rate', 'agreedPayRate', 'agreed_pay_rate', 'pay rate', 'payRate', 'pay_rate',
                'vendor pay rate', 'vendorPayRate', 'vendor_pay_rate',
                'contractor pay rate', 'contractorPayRate', 'contractor_pay_rate',
            ])
        )) : 0.0;
        $economicOptions = $enriched ? jobdivaSyncPlacementEconomicOptions($enriched) : [];
        $economicSignals = [
            'vendor' => $enriched ? jobdivaPluckFieldDeep($enriched, [
                'vendorName', 'vendor_name', 'supplierName', 'supplier_name',
                'contractorCompany', 'contractor_company', 'corpName', 'corp_name',
            ]) : '',
            'referrer' => $enriched ? jobdivaPluckFieldDeep($enriched, [
                'referrerName', 'referrer_name', 'referralVendor', 'referral_vendor',
            ]) : '',
            'commission' => $enriched ? jobdivaPluckFieldDeep($enriched, [
                'commissionPct', 'commission_pct', 'commissionPercent', 'commission_percent',
                'recruiterSplit', 'recruiter_split',
            ]) : '',
            'vendor_payment_terms' => (string) ($economicOptions['payment_terms'] ?? ''),
            'client_payment_terms' => (string) ($economicOptions['client_payment_terms'] ?? ''),
            'paid_when_paid' => $economicOptions['pwp_enabled'] ?? null,
        ];
        $hasEconomicParticipants = count(array_filter(
            $economicSignals,
            static fn($value): bool => $value !== '' && $value !== null && $value !== false
        )) > 0;

        $fullyJoined = $jobId !== ''
            && $candidateId !== ''
            && (int) ($joinStats['jobs_joined'] ?? 0) > 0
            && (int) ($joinStats['candidates_joined'] ?? 0) > 0;
        if ($fullyJoined) $summary['fully_joined']++;
        if ($jobId !== '' && empty($joinStats['jobs_joined'])) $summary['missing_job']++;
        if ($candidateId !== '' && empty($joinStats['candidates_joined'])) $summary['missing_candidate']++;
        if ($contactId !== '' && empty($joinStats['contacts_joined'])) $summary['missing_contact']++;
        if ($companyId !== '' && empty($joinStats['companies_joined'])) $summary['missing_company']++;
        if ($billRate > 0 && $payRate > 0) $summary['with_rates']++;
        if ($hasEconomicParticipants) $summary['with_economic_participants']++;

        $isArchived = !empty($current['deleted_at'])
            && $current['deleted_at'] !== '0000-00-00 00:00:00';
        $outcome = $isArchived ? 'restore' : ($placementId > 0 ? 'update' : 'create');
        if ($isArchived) {
            $warnings[] = "Placement {$placementId} is archived. It will be restored only if this exact Start ID is selected.";
        }
        $selectable = !$errors;
        if (!$selectable) {
            $outcome = 'blocked';
            $summary['blocked']++;
        } else {
            $summary[$outcome]++;
        }

        $rows[] = [
            'start_id' => $startId,
            'outcome' => $outcome,
            'selectable' => $selectable,
            'placement_id' => $placementId ?: null,
            'placement_title' => (string) ($current['title'] ?? ''),
            'source_updated_at' => (string) ($sourceRow['updated_at'] ?? ''),
            'identity' => $enrichedIdentity,
            'joins' => $joinStats,
            'source' => [
                'candidate_id' => $candidateId,
                'candidate_name' => $candidateName,
                'candidate_email' => $candidateEmail,
                'job_id' => $jobId,
                'title' => $title,
                'contact_id' => $contactId,
                'company_id' => $companyId,
                'end_client_name' => $endClientName,
                'engagement_type' => $engagement,
                'start_date' => $startDate,
                'end_date' => $endDateRaw !== '' ? jobdivaNormaliseDate($endDateRaw) : null,
                'bill_rate' => $billRate > 0 ? $billRate : null,
                'pay_rate' => $payRate > 0 ? $payRate : null,
            ],
            'economics' => $economicSignals,
            'current' => $current,
            'current_graph' => $currentGraph,
            'errors' => $errors,
            'warnings' => $warnings,
            '__payload' => $enriched,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $priority = ['blocked' => 0, 'restore' => 1, 'create' => 2, 'update' => 3];
        $byOutcome = ($priority[$a['outcome']] ?? 9) <=> ($priority[$b['outcome']] ?? 9);
        if ($byOutcome !== 0) return $byOutcome;
        return strcmp((string) $a['start_id'], (string) $b['start_id']);
    });

    $tokenRows = array_map(static fn(array $row): array => [
        'start_id' => $row['start_id'],
        'outcome' => $row['outcome'],
        'selectable' => $row['selectable'],
        'placement_id' => $row['placement_id'],
        'source_updated_at' => $row['source_updated_at'],
        'source' => $row['source'],
        'current' => $row['current'],
        'current_graph' => $row['current_graph'],
    ], $rows);
    $dryRunToken = hash('sha256', json_encode(
        ['tenant_id' => $tenantId, 'rows' => $tokenRows],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: '');

    $publicRows = array_map(static function (array $row): array {
        unset($row['__payload']);
        return $row;
    }, $rows);

    return [
        'summary' => $summary,
        'rows' => $rows,
        'public_rows' => $publicRows,
        'dry_run_token' => $dryRunToken,
        'safety' => [
            'identity' => 'Exact verified JobDiva Start ID',
            'source' => 'Stored jobdiva_assignment mirror',
            'joins' => 'Job, candidate, contact, company, rate, and economic facets by source foreign key',
            'writes' => 'Canonical projector only; selected rows only',
            'deletes' => false,
            'archives' => false,
            'restores' => 'Explicit selection only for an exact archived Start-ID match',
        ],
    ];
}

function jobdivaApplyStoredAssignmentProjection(
    int $tenantId,
    ?int $userId,
    array $selectedStartIds,
    string $expectedToken,
    int $limit = 5000
): array {
    $selected = [];
    foreach ($selectedStartIds as $startId) {
        $normalised = jobdivaAssignmentIdentityNormaliseId((string) $startId);
        if ($normalised !== '') $selected[$normalised] = true;
    }
    if (!$selected) throw new \InvalidArgumentException('Select at least one verified Start ID.');
    if (count($selected) > 500) throw new \InvalidArgumentException('Apply is limited to 500 Start IDs at a time.');

    $plan = jobdivaStoredAssignmentProjectionPlan($tenantId, $limit);
    if ($expectedToken === '' || !hash_equals((string) $plan['dry_run_token'], $expectedToken)) {
        throw new \RuntimeException('Stored JobDiva evidence or CoreFlux records changed after preview. Refresh the preview.');
    }
    $rowsByStart = [];
    foreach ($plan['rows'] as $row) $rowsByStart[(string) $row['start_id']] = $row;
    foreach (array_keys($selected) as $startId) {
        if (!isset($rowsByStart[$startId])) {
            throw new \RuntimeException("Start ID {$startId} is not present in the stored assignment preview.");
        }
        if (empty($rowsByStart[$startId]['selectable'])) {
            throw new \RuntimeException("Start ID {$startId} is blocked and cannot be projected.");
        }
    }

    $pdo = getDB();
    $result = [
        'projected' => 0,
        'created' => 0,
        'updated' => 0,
        'restored' => 0,
        'mapping_writes' => 0,
        'field_map_writes' => 0,
        'rows' => [],
    ];
    try {
        $pdo->beginTransaction();
        foreach (array_keys($selected) as $startId) {
            $row = $rowsByStart[$startId];
            $before = !empty($row['placement_id'])
                ? jobdivaPlacementProjectionAuditSnapshot($tenantId, (int) $row['placement_id'])
                : [];
            if ($row['outcome'] === 'restore' && !empty($row['placement_id'])) {
                $pdo->prepare(
                    'UPDATE placements
                        SET deleted_at = NULL
                      WHERE tenant_id = :t AND id = :id'
                )->execute([
                    't' => $tenantId,
                    'id' => (int) $row['placement_id'],
                ]);
            }
            $projection = jobdivaProjectorProjectPlacement(
                $tenantId,
                (array) $row['__payload'],
                $userId,
                [
                    'payload_is_enriched' => true,
                    'external_id' => $startId,
                    'existing_placement_id' => (int) ($row['placement_id'] ?? 0),
                ]
            );
            if (empty($projection['projected'])) {
                throw new \RuntimeException(
                    "Start ID {$startId}: "
                    . implode('; ', array_map('strval', $projection['errors'] ?? ['projection failed']))
                );
            }
            $placementId = (int) ($projection['placement_id'] ?? 0);
            $after = jobdivaPlacementProjectionAuditSnapshot($tenantId, $placementId);
            jobdivaAudit($tenantId, 'stored_assignment_reconciliation', [
                'entity_type' => 'placement',
                'direction' => 'pull',
                'ok' => true,
                'items_processed' => 1,
                'actor_user_id' => $userId,
                'detail' => [
                    'start_id' => $startId,
                    'placement_id' => $placementId,
                    'mode' => $row['outcome'],
                    'join_stats' => $projection['join_stats'] ?? [],
                    'readiness' => $projection['readiness'] ?? [],
                    'before' => $before,
                    'after' => $after,
                ],
            ]);
            $result['projected']++;
            if ($row['outcome'] === 'create') {
                $result['created']++;
            } elseif ($row['outcome'] === 'restore') {
                $result['restored']++;
            } else {
                $result['updated']++;
            }
            $result['mapping_writes'] += (int) ($projection['mapping_writes'] ?? 0);
            $result['field_map_writes'] += (int) ($projection['field_map']['written'] ?? 0);
            $result['rows'][] = [
                'start_id' => $startId,
                'placement_id' => $placementId,
                'mode' => $row['outcome'],
                'readiness' => $projection['readiness'] ?? [],
            ];
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $result;
}

function jobdivaReprojectStoredAssignmentGraphs(int $tenantId, ?int $userId, int $limit = 5000): array
{
    $plan = jobdivaStoredAssignmentProjectionPlan($tenantId, $limit);
    $pdo = getDB();
    $summary = [
        'assignments_seen' => (int) ($plan['summary']['assignments'] ?? 0),
        'placements_projected' => 0,
        'blocked' => (int) ($plan['summary']['blocked'] ?? 0),
        'restores_pending_selection' => (int) ($plan['summary']['restore'] ?? 0),
        'mapping_writes' => 0,
        'field_map_writes' => 0,
        'errors' => [],
    ];
    foreach ($plan['rows'] as $row) {
        if (empty($row['selectable']) || $row['outcome'] === 'restore') continue;
        try {
            $pdo->beginTransaction();
            $projection = jobdivaProjectorProjectPlacement(
                $tenantId,
                (array) $row['__payload'],
                $userId,
                [
                    'payload_is_enriched' => true,
                    'external_id' => (string) $row['start_id'],
                    'existing_placement_id' => (int) ($row['placement_id'] ?? 0),
                ]
            );
            if (empty($projection['projected'])) {
                throw new \RuntimeException(implode(
                    '; ',
                    array_map('strval', $projection['errors'] ?? ['projection failed'])
                ));
            }
            $pdo->commit();
            $summary['placements_projected']++;
            $summary['mapping_writes'] += (int) ($projection['mapping_writes'] ?? 0);
            $summary['field_map_writes'] += (int) ($projection['field_map']['written'] ?? 0);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (count($summary['errors']) < 50) {
                $summary['errors'][] = [
                    'start_id' => (string) ($row['start_id'] ?? ''),
                    'placement_id' => (int) ($row['placement_id'] ?? 0),
                    'error' => substr($e->getMessage(), 0, 300),
                ];
            }
        }
    }
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
        try {
            mappingUpsert($tid, 'jobdiva', 'jobdiva_customer', $customerExtId, $existingId, $payload, 'pull', $userId);
        } catch (\Throwable $e) {
            error_log('[jobdiva end-client resolver] customer mapping bind skipped: ' . $e->getMessage());
        }
        try {
            integrationPayloadFieldIndexRecord($tid, 'jobdiva', 'company', $payload);
        } catch (\Throwable $e) {
            error_log('[jobdiva end-client resolver] company index failed: ' . $e->getMessage());
        }
        return $existingId;
    }

    // Auto-create.
    $pdo->prepare(
        'INSERT INTO companies (tenant_id, name) VALUES (:t, :n)'
    )->execute(['t' => $tid, 'n' => $customerName]);
    $newId = (int) $pdo->lastInsertId();
    try {
        mappingUpsert($tid, 'jobdiva', 'jobdiva_customer', $customerExtId, $newId, $payload, 'pull', $userId);
    } catch (\Throwable $e) {
        error_log('[jobdiva end-client resolver] customer mapping bind skipped: ' . $e->getMessage());
    }
    try {
        integrationPayloadFieldIndexRecord($tid, 'jobdiva', 'company', $payload);
    } catch (\Throwable $e) {
        error_log('[jobdiva end-client resolver] company index failed: ' . $e->getMessage());
    }
    return $newId;
}

function jobdivaNormalisePlacementEngagementType(string $raw, ?string $fallback = null): string
{
    $allowed = ['w2', '1099', 'c2c', 'temp_to_perm', 'direct_hire'];
    $fallback = in_array((string) $fallback, $allowed, true)
        ? (string) $fallback
        : ($fallback === '' ? '' : 'w2');
    $s = strtolower(trim($raw));
    if ($s === '') return $fallback;

    $s = str_replace(['_', '-', '/', '\\'], ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s) ?: $s;

    if (str_contains($s, 'temp to perm')
        || str_contains($s, 'contract to hire')
        || preg_match('/\bcth\b/', $s)) {
        return 'temp_to_perm';
    }
    if (str_contains($s, 'direct hire')
        || str_contains($s, 'direct placement')
        || preg_match('/\bperm(?:anent)?\b/', $s)) {
        return 'direct_hire';
    }
    if (str_contains($s, '1099')
        || str_contains($s, 'independent contractor')
        || preg_match('/\bic\b/', $s)) {
        return '1099';
    }
    if (str_contains($s, 'c2c')
        || str_contains($s, 'corp to corp')
        || str_contains($s, 'crop to crop')
        || str_contains($s, 'corp 2 corp')
        || str_contains($s, 'corporation to corporation')
        || str_contains($s, 'inc to inc')) {
        return 'c2c';
    }
    if (str_contains($s, 'w2')
        || str_contains($s, 'w 2')
        || str_contains($s, 'employee')
        || str_contains($s, 'payroll')) {
        return 'w2';
    }

    return $fallback;
}

function jobdivaPlacementScalarHasC2CSignal(string $key, mixed $value): bool
{
    return jobdivaPlacementC2CFlagState($key, $value) === true;
}

/**
 * Return true/false only when a key is an explicit C2C flag.
 *
 * searchStart includes `crop to crop` on every Start. JobDiva serialises an
 * unchecked flag as null, so null/empty is negative evidence, not C2C proof.
 */
function jobdivaPlacementC2CFlagState(string $key, mixed $value): ?bool
{
    if (!is_scalar($value) && $value !== null) return null;
    $keyNorm = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    $valueRaw = trim((string) $value);
    $valueNorm = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $valueRaw));
    $c2cKey = str_contains($keyNorm, 'c2c')
        || str_contains($keyNorm, 'corptocorp')
        || str_contains($keyNorm, 'croptocrop')
        || str_contains($keyNorm, 'corporationtocorporation');
    if (!$c2cKey) return null;
    if ($valueRaw === '') return false;
    if (jobdivaBoolishTrue($valueRaw)) return true;
    if (in_array($valueNorm, ['0', 'false', 'no', 'n', 'off', 'unchecked', 'null'], true)) return false;
    if (jobdivaNormalisePlacementEngagementType($valueRaw, '') === 'c2c') return true;
    return null;
}

function jobdivaPlacementScalarHasTypedC2CSignal(string $key, mixed $value): bool
{
    if (!is_scalar($value) && $value !== null) return false;
    $keyNorm = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    $valueRaw = trim((string) $value);
    $valueNorm = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $valueRaw));
    $keyValue = trim($key . ' ' . $valueRaw);
    $negative = in_array($valueNorm, ['0', 'false', 'no', 'n', 'off', 'unchecked'], true);
    $typedKey = str_contains($keyNorm, 'engagementtype')
        || str_contains($keyNorm, 'workertype')
        || str_contains($keyNorm, 'classification')
        || str_contains($keyNorm, 'employmenttype')
        || str_contains($keyNorm, 'employmentcategory')
        || str_contains($keyNorm, 'employeetype')
        || str_contains($keyNorm, 'positiontype')
        || str_contains($keyNorm, 'taxtype')
        || str_contains($keyNorm, 'payrolltype')
        || str_contains($keyNorm, 'contracttype')
        || str_contains($keyNorm, 'jobtype')
        || str_contains($keyNorm, 'hiretype');
    if (!$typedKey || $negative) return false;

    return jobdivaNormalisePlacementEngagementType($valueRaw, '') === 'c2c'
        || jobdivaNormalisePlacementEngagementType($keyValue, '') === 'c2c';
}

function jobdivaPlacementPayloadHasC2CProof(array $payload): bool
{
    $scan = static function (array $node, string $prefix = '') use (&$scan): bool {
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            if (is_array($value)) {
                if ($scan($value, $path)) return true;
                continue;
            }
            if (jobdivaPlacementScalarHasC2CSignal($path, $value)
                || jobdivaPlacementScalarHasTypedC2CSignal($path, $value)) return true;
        }
        return false;
    };

    foreach ([
        '_jd_start', 'assignment', 'start', 'Start', 'jobdiva_assignment',
    ] as $root) {
        if (isset($payload[$root]) && is_array($payload[$root]) && $scan($payload[$root], (string) $root)) {
            return true;
        }
    }

    foreach ($payload as $key => $value) {
        if (is_array($value)) continue;
        if (jobdivaPlacementScalarHasC2CSignal((string) $key, $value)
            || jobdivaPlacementScalarHasTypedC2CSignal((string) $key, $value)) return true;
    }

    return false;
}

function jobdivaPlacementPayloadC2CFlagState(array $payload): ?bool
{
    $state = null;
    $scan = static function (array $node, string $prefix = '') use (&$scan, &$state): void {
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            if (is_array($value)) {
                $scan($value, $path);
                if ($state === true) return;
                continue;
            }
            $hit = jobdivaPlacementC2CFlagState($path, $value);
            if ($hit === true) {
                $state = true;
                return;
            }
            if ($hit === false && $state === null) $state = false;
        }
    };

    foreach (['_jd_contract', '_jd_start', 'assignment', 'start', 'Start', 'jobdiva_assignment'] as $root) {
        if (!isset($payload[$root]) || !is_array($payload[$root])) continue;
        $scan($payload[$root], (string) $root);
        if ($state === true) return true;
    }
    foreach ($payload as $key => $value) {
        if (is_array($value)) continue;
        $hit = jobdivaPlacementC2CFlagState((string) $key, $value);
        if ($hit === true) return true;
        if ($hit === false && $state === null) $state = false;
    }
    return $state;
}

function jobdivaInferPlacementEngagementTypeFromPayload(array $payload, ?string $fallback = null): string
{
    $allowed = ['w2', '1099', 'c2c', 'temp_to_perm', 'direct_hire'];
    $fallback = in_array((string) $fallback, $allowed, true)
        ? (string) $fallback
        : ($fallback === '' ? '' : 'w2');

    $contractEngagement = jobdivaNormalisePlacementEngagementType(
        (string) ($payload['_jd_contract']['engagement_type'] ?? ''),
        ''
    );
    if ($contractEngagement !== '') return $contractEngagement;

    $classify = static function (string $key, mixed $value): ?string {
        if (!is_scalar($value) && $value !== null) return null;
        $keyNorm = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
        $valueRaw = trim((string) $value);
        $keyValue = trim($key . ' ' . $valueRaw);

        if (jobdivaPlacementScalarHasC2CSignal($key, $value)
            || jobdivaPlacementScalarHasTypedC2CSignal($key, $value)) return 'c2c';

        $typedKey = str_contains($keyNorm, 'engagementtype')
            || str_contains($keyNorm, 'workertype')
            || str_contains($keyNorm, 'classification')
            || str_contains($keyNorm, 'employmenttype')
            || str_contains($keyNorm, 'employmentcategory')
            || str_contains($keyNorm, 'employeetype')
            || str_contains($keyNorm, 'positiontype')
            || str_contains($keyNorm, 'taxtype')
            || str_contains($keyNorm, 'payrolltype')
            || str_contains($keyNorm, 'contracttype')
            || str_contains($keyNorm, 'jobtype')
            || str_contains($keyNorm, 'hiretype');
        if ($typedKey) {
            $type = jobdivaNormalisePlacementEngagementType($valueRaw, '');
            if ($type !== '') return $type;
            $type = jobdivaNormalisePlacementEngagementType($keyValue, '');
            if ($type !== '') return $type;
        }

        // Do not classify from arbitrary scalar values. Company/legal names
        // like "Acme Staffing LLC" or chain labels like "prime vendor" are
        // business-entity evidence, not worker-classification evidence.
        return null;
    };

    $walk = static function (array $node, string $prefix = '') use (&$walk, $classify): ?string {
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            if (is_array($value)) {
                $hit = $walk($value, $path);
                if ($hit !== null) return $hit;
                continue;
            }
            $hit = $classify($path, $value);
            if ($hit !== null) return $hit;
        }
        return null;
    };

    $scanNested = static function (array $roots) use ($payload, $walk): ?string {
        foreach ($roots as $root) {
            if (!isset($payload[$root]) || !is_array($payload[$root])) continue;
            $hit = $walk($payload[$root], (string) $root);
            if ($hit !== null) return $hit;
        }
        return null;
    };

    // Only the Start/Assignment owns the worker classification. A Job can be
    // open to several engagement types and a candidate can have preferences;
    // neither is the economic contract represented by this placement.
    $hit = $scanNested([
        '_jd_contract', '_jd_start', 'assignment', 'start', 'Start', 'jobdiva_assignment',
    ]);
    if ($hit !== null) return $hit;

    foreach ($payload as $key => $value) {
        if (is_array($value)) continue;
        $hit = $classify((string) $key, $value);
        if ($hit !== null) return $hit;
    }

    // JobDiva's documented searchStart response always carries the C2C flag.
    // An explicit null/false flag means this Start is not corp-to-corp. When
    // no stronger 1099/direct-hire classification exists, it is W2.
    if (jobdivaPlacementPayloadC2CFlagState($payload) === false) return 'w2';

    return $fallback;
}

function jobdivaBoolishTrue(mixed $raw): bool
{
    if ($raw === true) return true;
    if (is_int($raw) || is_float($raw)) return (float) $raw > 0;
    $s = strtolower(trim((string) $raw));
    if ($s === '') return false;
    return in_array($s, ['1', 'true', 'yes', 'y', 'on', 'checked'], true);
}

function jobdivaSyncUpsertPlacementChainRow(
    int $tid,
    int $placementId,
    int $position,
    string $partyRole,
    ?string $partyName,
    ?int $companyId = null,
    array $extras = [],
    ?int $userId = null
): int {
    $partyName = trim((string) $partyName);
    if ($tid <= 0 || $placementId <= 0 || $position < 0 || $partyRole === '') return 0;

    $pdo = getDB();
    $existing = $pdo->prepare(
        'SELECT id, party_name
           FROM placement_client_chain
          WHERE tenant_id = :t AND placement_id = :p AND position = :pos
          LIMIT 1'
    );
    $existing->execute(['t' => $tid, 'p' => $placementId, 'pos' => $position]);
    $existingRow = $existing->fetch(\PDO::FETCH_ASSOC) ?: null;

    if ($partyName === '' && $existingRow) {
        $partyName = (string) ($existingRow['party_name'] ?? '');
    }
    if ($partyName === '' && $companyId !== null && $companyId > 0 && function_exists('companiesGet')) {
        $company = companiesGet($companyId);
        $partyName = trim((string) ($company['name'] ?? ''));
    }
    if ($partyName === '') return 0;

    $roleForCompany = $partyRole === 'end_client'
        ? 'client'
        : ($partyRole === 'direct' ? 'client' : $partyRole);
    if (($companyId === null || $companyId <= 0) && function_exists('companiesUpsertByName')) {
        try {
            $companyId = companiesUpsertByName($tid, $partyName, [
                'created_by_user_id' => $userId,
            ], [$roleForCompany]);
        } catch (\Throwable $e) {
            error_log('[jobdiva placement chain] company upsert skipped: ' . $e->getMessage());
            $companyId = null;
        }
    } elseif ($companyId !== null && $companyId > 0 && function_exists('companiesAddRole')) {
        try {
            companiesAddRole($companyId, $roleForCompany);
        } catch (\Throwable $e) {
            error_log('[jobdiva placement chain] company role skipped: ' . $e->getMessage());
        }
    }
    if ($companyId !== null && $companyId > 0 && function_exists('companiesBumpUsage')) {
        try { companiesBumpUsage($companyId); } catch (\Throwable $e) { /* non-fatal */ }
    }

    $payload = [
        'party_name' => $partyName,
        'party_role' => $partyRole,
        'company_id' => ($companyId !== null && $companyId > 0) ? $companyId : null,
    ];
    foreach ([
        'portal_fee_pct', 'portal_fee_flat', 'submittal_id', 'vms_job_id',
        'payment_terms_override', 'pwp_enabled', 'is_payable',
    ] as $k) {
        if (array_key_exists($k, $extras) && $extras[$k] !== null && $extras[$k] !== '') {
            $payload[$k] = $extras[$k];
        }
    }

    if ($existingRow) {
        $sets = [];
        $params = ['id' => (int) $existingRow['id']];
        foreach ($payload as $col => $value) {
            $sets[] = "{$col} = :{$col}";
            $params[$col] = $value;
        }
        $pdo->prepare(
            'UPDATE placement_client_chain
                SET ' . implode(', ', $sets) . '
              WHERE id = :id'
        )->execute($params);
        return (int) $existingRow['id'];
    }

    $pdo->prepare(
        'INSERT INTO placement_client_chain
            (tenant_id, placement_id, position, party_name, party_role, company_id,
             portal_fee_pct, portal_fee_flat, payment_terms_override, pwp_enabled, is_payable,
             submittal_id, vms_job_id)
         VALUES
            (:t, :p, :pos, :name, :role, :company_id,
             :fee_pct, :fee_flat, :terms, :pwp, :is_payable, :submittal, :vms)'
    )->execute([
        't' => $tid,
        'p' => $placementId,
        'pos' => $position,
        'name' => $payload['party_name'],
        'role' => $payload['party_role'],
        'company_id' => $payload['company_id'],
        'fee_pct' => $payload['portal_fee_pct'] ?? null,
        'fee_flat' => $payload['portal_fee_flat'] ?? null,
        'terms' => $payload['payment_terms_override'] ?? null,
        'pwp' => !empty($payload['pwp_enabled']) ? 1 : 0,
        'is_payable' => !empty($payload['is_payable']) ? 1 : 0,
        'submittal' => $payload['submittal_id'] ?? null,
        'vms' => $payload['vms_job_id'] ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

function jobdivaSyncUpsertPlacementChain(
    int $tid,
    int $placementId,
    ?int $endClientCompanyId,
    ?string $endClientName,
    array $jd,
    ?int $userId = null
): array {
    $summary = ['written' => 0, 'rows' => []];
    if ($tid <= 0 || $placementId <= 0) return $summary;

    $endClientName = trim((string) ($endClientName ?: jobdivaPluckFieldDeep($jd, [
        'end_client_name', 'endClientName', 'customer name', 'customerName',
        'companyName', 'company name', 'job.COMPANYNAME', 'job.companyName',
    ])));
    if ($endClientName !== '') {
        $id = jobdivaSyncUpsertPlacementChainRow(
            $tid,
            $placementId,
            0,
            'end_client',
            $endClientName,
            $endClientCompanyId,
            [],
            $userId
        );
        if ($id > 0) {
            $summary['written']++;
            $summary['rows']['end_client'] = $id;
        }
    }

    $defs = [
        [
            'role' => 'msp',
            'position' => 1,
            'name_keys' => [
                'msp', 'msp name', 'mspName', 'managed service provider',
                'vms', 'vms name', 'vmsName', 'vms provider', 'vmsProvider',
                'program', 'program name', 'programName',
                'client portal', 'clientPortal', 'portal', 'portal name', 'portalName',
                'beeline', 'fieldglass', 'iqnavigator',
            ],
            'fee_keys' => [
                'msp fee pct', 'msp_fee_pct', 'mspFeePct',
                'vms fee pct', 'vms_fee_pct', 'discount pct',
                'discount %', 'discount percent', 'discountPercentage',
                'client discount pct', 'client discount %', 'client discount percent',
                'msp discount pct', 'msp discount %', 'vms discount pct',
                'portal fee pct', 'portal fee %', 'program fee pct',
                'admin fee pct', 'management fee pct',
                'beeline fee pct', 'fieldglass fee pct',
            ],
            'flat_keys' => [
                'msp fee flat', 'msp_fee_flat', 'vms fee flat', 'discount flat', 'discount amount',
                'portal fee flat', 'program fee flat', 'admin fee flat',
                'beeline fee flat', 'fieldglass fee flat',
            ],
        ],
        [
            'role' => 'prime_vendor',
            'position' => 2,
            'name_keys' => [
                'prime vendor', 'primeVendor', 'prime_vendor',
                'vendor name', 'vendorName', 'supplier name', 'supplierName',
                'vendor', 'vendor company', 'vendorCompany',
                'supplier', 'supplier company', 'supplierCompany',
                'agency name', 'agencyName', 'agency company', 'agencyCompany',
                'employer company', 'employerCompany', 'payrolling company', 'payrollingCompany',
                'vendor legal name', 'vendorLegalName',
                'supplier legal name', 'supplierLegalName',
                'payee company', 'payeeCompany',
                'payee legal name', 'payeeLegalName',
                'employer of record', 'employerOfRecord',
                'eor', 'payroll provider', 'payrollProvider',
            ],
            'fee_keys' => [
                'prime vendor fee pct', 'vendor fee pct', 'vendor fee percent',
                'supplier fee pct', 'supplier fee percent', 'agency fee pct',
                'vendor discount pct', 'vendor discount %', 'vendor discount percent',
                'vendor portal fee pct', 'supplier discount pct',
                'agency discount pct', 'payroll provider fee pct',
            ],
            'flat_keys' => [
                'prime vendor fee flat', 'vendor fee flat', 'supplier fee flat',
                'agency fee flat', 'vendor discount flat', 'vendor discount amount',
                'vendor portal fee flat', 'supplier discount flat',
                'agency discount flat', 'payroll provider fee flat',
            ],
        ],
        [
            'role' => 'sub_vendor',
            'position' => 3,
            'name_keys' => [
                'sub vendor', 'subVendor', 'sub_vendor',
                'subcontractor', 'subcontractor name', 'subcontractor company',
                'sub supplier', 'subSupplier', 'sub supplier name', 'subSupplierName',
                'secondary vendor', 'secondaryVendor',
                'downstream vendor', 'downstreamVendor',
            ],
            'fee_keys' => [
                'sub vendor fee pct', 'subvendor fee pct', 'subcontractor fee pct',
                'sub vendor discount pct', 'subcontractor discount pct',
                'secondary vendor fee pct', 'downstream vendor fee pct',
            ],
            'flat_keys' => [
                'sub vendor fee flat', 'subvendor fee flat', 'subcontractor fee flat',
                'sub vendor discount flat', 'subcontractor discount flat',
                'secondary vendor fee flat', 'downstream vendor fee flat',
            ],
        ],
    ];

    foreach ($defs as $def) {
        $name = trim((string) jobdivaPluckFieldDeep($jd, $def['name_keys']));
        if ($name === '') continue;
        if ($endClientName !== '' && strtolower($name) === strtolower($endClientName)) continue;

        $feePctRaw = jobdivaPluckFieldDeep($jd, $def['fee_keys']);
        $feePct = jobdivaParsePercent($feePctRaw);
        $feeFlatRaw = jobdivaPluckFieldDeep($jd, $def['flat_keys']);
        $feeFlat = jobdivaParseRateAmount($feeFlatRaw);
        $roleKey = (string) $def['role'];
        $roleLabel = str_replace('_', ' ', $roleKey);
        $termsRaw = jobdivaPluckFieldDeep($jd, [
            $roleLabel . ' payment terms', $roleKey . '_payment_terms', $roleKey . 'PaymentTerms',
            $roleLabel . ' terms', $roleKey . '_terms', $roleKey . 'Terms',
            'vendor payment terms', 'vendorPaymentTerms', 'supplier payment terms', 'supplierPaymentTerms',
        ]);
        $terms = $termsRaw !== '' ? placementEconomicsNormaliseTerms($termsRaw) : null;
        $pwpRaw = jobdivaPluckFieldDeep($jd, [
            $roleLabel . ' paid when paid', $roleKey . '_paid_when_paid', $roleKey . 'PaidWhenPaid',
            $roleLabel . ' pwp', $roleKey . '_pwp', $roleKey . 'Pwp',
            'vendor paid when paid', 'vendorPaidWhenPaid', 'paidWhenPaid', 'payWhenPaid', 'pwp',
        ]);
        $pwp = $pwpRaw !== ''
            ? jobdivaBoolishTrue($pwpRaw)
            : ($terms !== null ? placementEconomicsTermsArePwp($terms) : null);
        $payableRaw = jobdivaPluckFieldDeep($jd, [
            $roleLabel . ' is payable', $roleKey . '_is_payable', $roleKey . 'IsPayable',
            $roleLabel . ' payable', $roleKey . '_payable', $roleKey . 'Payable',
        ]);
        $isPayable = $payableRaw !== ''
            ? jobdivaBoolishTrue($payableRaw)
            : ((($feePct !== null && $feePct > 0) || $feeFlat > 0) ? true : null);
        $submittalId = jobdivaPluckFieldDeep($jd, [
            'submittal id', 'submittalId', 'submittal_id',
            $def['role'] . ' submittal id', $def['role'] . '_submittal_id',
        ]);
        $vmsJobId = jobdivaPluckFieldDeep($jd, [
            'vms job id', 'vmsJobId', 'vms_job_id',
            'client job id', 'clientJobId', 'client_req_id',
            $def['role'] . ' vms job id', $def['role'] . '_vms_job_id',
        ]);

        $id = jobdivaSyncUpsertPlacementChainRow(
            $tid,
            $placementId,
            (int) $def['position'],
            (string) $def['role'],
            $name,
            null,
            [
                'portal_fee_pct' => $feePct,
                'portal_fee_flat' => $feeFlat > 0 ? $feeFlat : null,
                'payment_terms_override' => $terms,
                'pwp_enabled' => $pwp === null ? null : ($pwp ? 1 : 0),
                'is_payable' => $isPayable === null ? null : ($isPayable ? 1 : 0),
                'submittal_id' => $submittalId !== '' ? $submittalId : null,
                'vms_job_id' => $vmsJobId !== '' ? $vmsJobId : null,
            ],
            $userId
        );
        if ($id > 0) {
            $summary['written']++;
            $summary['rows'][(string) $def['role']] = $id;
        }
    }

    return $summary;
}

function jobdivaCorpMappedOrDefault(
    int $tid,
    string $targetColumn,
    array $jd,
    callable $defaultFn
): mixed {
    if (function_exists('tenantIntegrationFieldMapPluckTarget')) {
        return tenantIntegrationFieldMapPluckTarget(
            $tid,
            'jobdiva',
            'placement',
            'placement_corp_details',
            $targetColumn,
            'placement_corp_details',
            $jd,
            $defaultFn
        );
    }
    return $defaultFn();
}

function jobdivaSyncCorpPluck(int $tid, array $jd, string $targetColumn, array $defaultKeys): string
{
    return trim((string) jobdivaCorpMappedOrDefault(
        $tid,
        $targetColumn,
        $jd,
        static fn() => jobdivaPluckFieldDeep($jd, $defaultKeys)
    ));
}

function jobdivaSyncUpsertPlacementCorpDetails(
    int $tid,
    int $placementId,
    array $jd,
    ?int $userId = null,
    ?string $resolvedEngagement = null
): array {
    $summary = ['written' => 0, 'removed' => 0, 'row' => 0, 'skipped' => false, 'errors' => []];
    if ($tid <= 0 || $placementId <= 0) return $summary;

    $engagement = jobdivaNormalisePlacementEngagementType((string) ($resolvedEngagement ?? ''), '');
    if ($engagement === '') {
        $engagement = jobdivaInferPlacementEngagementTypeFromPayload($jd, '');
    }
    if ($engagement !== 'c2c') {
        try {
            $pdo = getDB();
            $st = $pdo->prepare(
                'DELETE pcd
                   FROM placement_corp_details pcd
                   JOIN placements p
                     ON p.tenant_id = pcd.tenant_id
                    AND p.id = pcd.placement_id
                  WHERE pcd.tenant_id = :tenant_id
                    AND pcd.placement_id = :placement_id
                    AND p.engagement_type <> "c2c"'
            );
            $st->execute(['tenant_id' => $tid, 'placement_id' => $placementId]);
            $summary['removed'] = $st->rowCount();
            if ($summary['removed'] > 0 && function_exists('placementsAudit')) {
                placementsAudit('placement.corp.cleared_non_c2c_jobdiva', [
                    'placement_id' => $placementId,
                    'actor_user_id' => $userId,
                ], $placementId);
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = $e->getMessage();
            error_log('[jobdiva placement corp details clear] ' . $e->getMessage());
        }
        $summary['skipped'] = true;
        return $summary;
    }

    $legalName = jobdivaSyncCorpPluck($tid, $jd, 'corp_legal_name', [
        'corp legal name', 'corp_legal_name', 'corpLegalName',
        'corporation name', 'corporationName', 'company corporation name',
        'contractor company', 'contractorCompany', 'contractor corp',
        'candidate corp', 'candidateCorp', 'employee corp', 'employeeCorp',
        'payee company', 'payeeCompany', 'payee legal name',
        'vendor legal name', 'vendorLegalName', 'vendor company', 'vendorCompany',
        'supplier legal name', 'supplierLegalName', 'supplier company', 'supplierCompany',
        'subcontractor company', 'subcontractorCompany',
        'employer company', 'employerCompany', 'payrolling company', 'payrollingCompany',
    ]);
    if ($legalName === '' && $engagement === 'c2c') {
        $legalName = trim((string) jobdivaPluckFieldDeep($jd, [
            'payee name', 'payeeName',
            'vendor name', 'vendorName',
            'supplier name', 'supplierName',
            'subcontractor name', 'subcontractorName',
        ]));
    }
    $fields = [
        'corp_legal_name' => $legalName,
        'corp_address_line1' => jobdivaSyncCorpPluck($tid, $jd, 'corp_address_line1', [
            'corp address line1', 'corp address 1', 'corpAddress1', 'corp_address_line1',
            'vendor address line1', 'vendor address 1', 'vendorAddress1',
            'supplier address line1', 'supplier address 1', 'payee address line1',
        ]),
        'corp_address_line2' => jobdivaSyncCorpPluck($tid, $jd, 'corp_address_line2', [
            'corp address line2', 'corp address 2', 'corpAddress2', 'corp_address_line2',
            'vendor address line2', 'vendor address 2', 'vendorAddress2',
            'supplier address line2', 'supplier address 2', 'payee address line2',
        ]),
        'corp_city' => jobdivaSyncCorpPluck($tid, $jd, 'corp_city', [
            'corp city', 'corpCity', 'corp_city',
            'vendor city', 'vendorCity', 'supplier city', 'payee city',
        ]),
        'corp_state' => jobdivaSyncCorpPluck($tid, $jd, 'corp_state', [
            'corp state', 'corpState', 'corp_state',
            'vendor state', 'vendorState', 'supplier state', 'payee state',
        ]),
        'corp_postal_code' => jobdivaSyncCorpPluck($tid, $jd, 'corp_postal_code', [
            'corp postal code', 'corp zip', 'corpZip', 'corp_postal_code',
            'vendor postal code', 'vendor zip', 'vendorZip',
            'supplier postal code', 'payee postal code',
        ]),
        'corp_country' => jobdivaSyncCorpPluck($tid, $jd, 'corp_country', [
            'corp country', 'corpCountry', 'corp_country',
            'vendor country', 'vendorCountry', 'supplier country', 'payee country',
        ]),
        'corp_contact_name' => jobdivaSyncCorpPluck($tid, $jd, 'corp_contact_name', [
            'corp contact name', 'corpContactName', 'corp_contact_name',
            'vendor contact name', 'vendorContactName',
            'supplier contact name', 'payee contact name',
        ]),
        'corp_contact_email' => jobdivaSyncCorpPluck($tid, $jd, 'corp_contact_email', [
            'corp contact email', 'corpContactEmail', 'corp_contact_email',
            'vendor contact email', 'vendorContactEmail',
            'supplier contact email', 'payee contact email',
        ]),
        'corp_contact_phone' => jobdivaSyncCorpPluck($tid, $jd, 'corp_contact_phone', [
            'corp contact phone', 'corpContactPhone', 'corp_contact_phone',
            'vendor contact phone', 'vendorContactPhone',
            'supplier contact phone', 'payee contact phone',
        ]),
        'coi_expiry' => jobdivaNormaliseDate(jobdivaSyncCorpPluck($tid, $jd, 'coi_expiry', [
            'coi expiry', 'coiExpiry', 'coi_expiry',
            'certificate of insurance expiry', 'insurance expiry',
        ])),
    ];

    if ($fields['corp_country'] !== '') {
        $fields['corp_country'] = strtoupper(substr($fields['corp_country'], 0, 2));
    }

    $clean = [];
    foreach ($fields as $col => $value) {
        if ($value !== null && trim((string) $value) !== '') {
            $clean[$col] = $value;
        }
    }
    if (empty($clean['corp_legal_name'])) {
        $summary['skipped'] = true;
        return $summary;
    }

    try {
        $pdo = getDB();
        $cols = ['placement_id', 'tenant_id'];
        $placeholders = [':placement_id', ':tenant_id'];
        $params = [
            'placement_id' => $placementId,
            'tenant_id' => $tid,
        ];
        foreach ($clean as $col => $value) {
            $cols[] = "`{$col}`";
            $placeholders[] = ':' . $col;
            $params[$col] = $value;
        }
        $updates = [];
        foreach (array_keys($clean) as $col) {
            $updates[] = "`{$col}` = VALUES(`{$col}`)";
        }
        $pdo->prepare(
            'INSERT INTO placement_corp_details
                (' . implode(', ', $cols) . ')
             VALUES
                (' . implode(', ', $placeholders) . ')
             ON DUPLICATE KEY UPDATE ' . implode(', ', $updates) . ', updated_at = NOW()'
        )->execute($params);
        $summary['written'] = count($clean);
        $summary['row'] = $placementId;
        if (function_exists('placementsAudit')) {
            placementsAudit('placement.corp.projected_from_jobdiva', [
                'placement_id' => $placementId,
                'fields' => array_keys($clean),
                'actor_user_id' => $userId,
            ], $placementId);
        }
    } catch (\Throwable $e) {
        $summary['errors'][] = $e->getMessage();
        error_log('[jobdiva placement corp details] ' . $e->getMessage());
    }

    return $summary;
}

function jobdivaNormaliseCommissionBasis(string $raw, string $fallback = 'net_margin'): string
{
    $allowed = ['net_margin', 'gross_margin', 'bill_rate', 'flat'];
    $fallback = in_array($fallback, $allowed, true) ? $fallback : 'net_margin';
    $s = strtolower(trim($raw));
    if ($s === '') return $fallback;
    $s = str_replace(['-', '/', '\\'], ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s) ?: $s;
    if (str_contains($s, 'gross')) return 'gross_margin';
    if (str_contains($s, 'bill')) return 'bill_rate';
    if (str_contains($s, 'flat') || str_contains($s, 'fixed')) return 'flat';
    if (str_contains($s, 'net') || str_contains($s, 'margin')) return 'net_margin';
    return $fallback;
}

function jobdivaResolvePlacementCommissionUserId(int $tid, ?string $email, ?string $name): ?int
{
    if ($tid <= 0) return null;
    $email = strtolower(trim((string) $email));
    $name = trim((string) $name);
    if ($email === '' && $name === '') return null;
    try {
        $pdo = getDB();
        if ($email !== '') {
            $st = $pdo->prepare(
                'SELECT u.id
                   FROM users u
                   JOIN user_tenants ut ON ut.user_id = u.id
                  WHERE ut.tenant_id = :t
                    AND ut.status = "active"
                    AND u.is_active = 1
                    AND LOWER(u.email) = :email
                  ORDER BY u.id ASC
                  LIMIT 1'
            );
            $st->execute(['t' => $tid, 'email' => $email]);
            $id = (int) $st->fetchColumn();
            if ($id > 0) return $id;
        }
        if ($name !== '') {
            $st = $pdo->prepare(
                'SELECT u.id
                   FROM users u
                   JOIN user_tenants ut ON ut.user_id = u.id
                  WHERE ut.tenant_id = :t
                    AND ut.status = "active"
                    AND u.is_active = 1
                    AND LOWER(u.name) = LOWER(:name)
                  ORDER BY u.id ASC
                  LIMIT 1'
            );
            $st->execute(['t' => $tid, 'name' => $name]);
            $id = (int) $st->fetchColumn();
            if ($id > 0) return $id;
        }
    } catch (\Throwable $e) {
        error_log('[jobdiva placement commissions] user resolution skipped: ' . $e->getMessage());
    }
    return null;
}

function jobdivaCommissionMappedOrDefault(
    int $tid,
    string $targetColumn,
    string $linkedEntity,
    array $jd,
    callable $defaultFn
): mixed {
    if (function_exists('tenantIntegrationFieldMapPluckTarget')) {
        return tenantIntegrationFieldMapPluckTarget(
            $tid,
            'jobdiva',
            'placement',
            'placement_commissions',
            $targetColumn,
            $linkedEntity,
            $jd,
            $defaultFn
        );
    }
    return $defaultFn();
}

function jobdivaSyncUpsertPlacementCommissionRow(
    int $tid,
    int $placementId,
    string $role,
    ?int $userId,
    ?float $splitPct,
    ?float $flatAmount,
    string $basis,
    string $effectiveFrom,
    ?string $effectiveTo,
    string $sourceLabel
): int {
    if ($tid <= 0 || $placementId <= 0 || $role === '' || $effectiveFrom === '') return 0;
    if ($splitPct !== null && ($splitPct <= 0 || $splitPct > 1)) $splitPct = null;
    if ($flatAmount !== null && $flatAmount <= 0) $flatAmount = null;
    if ($splitPct === null && $flatAmount === null) return 0;
    if ($flatAmount !== null && $splitPct === null) $basis = 'flat';

    $pdo = getDB();
    $notes = 'Source: JobDiva commission projection (' . $sourceLabel . ')';
    $existing = $pdo->prepare(
        'SELECT id
           FROM placement_commissions
          WHERE tenant_id = :t
            AND placement_id = :p
            AND role = :role
            AND effective_from = :ef
            AND notes LIKE "Source: JobDiva commission projection%"
          ORDER BY id ASC
          LIMIT 1'
    );
    $existing->execute([
        't' => $tid,
        'p' => $placementId,
        'role' => $role,
        'ef' => $effectiveFrom,
    ]);
    $existingId = (int) $existing->fetchColumn();
    if ($existingId > 0) {
        $pdo->prepare(
            'UPDATE placement_commissions
                SET user_id = :uid,
                    split_pct = :split,
                    basis = :basis,
                    flat_amount = :flat,
                    effective_to = :eto,
                    notes = :notes
              WHERE id = :id AND tenant_id = :t'
        )->execute([
            'uid' => $userId,
            'split' => $splitPct,
            'basis' => $basis,
            'flat' => $flatAmount,
            'eto' => $effectiveTo,
            'notes' => $notes,
            'id' => $existingId,
            't' => $tid,
        ]);
        return $existingId;
    }

    $pdo->prepare(
        'INSERT INTO placement_commissions
            (tenant_id, placement_id, role, user_id, split_pct, basis, flat_amount,
             effective_from, effective_to, notes)
         VALUES
            (:t, :p, :role, :uid, :split, :basis, :flat, :ef, :eto, :notes)'
    )->execute([
        't' => $tid,
        'p' => $placementId,
        'role' => $role,
        'uid' => $userId,
        'split' => $splitPct,
        'basis' => $basis,
        'flat' => $flatAmount,
        'ef' => $effectiveFrom,
        'eto' => $effectiveTo,
        'notes' => $notes,
    ]);
    return (int) $pdo->lastInsertId();
}

function jobdivaSyncUpsertPlacementCommissions(int $tid, int $placementId, string $startDate, array $jd): array
{
    $summary = ['written' => 0, 'rows' => []];
    if ($tid <= 0 || $placementId <= 0) return $summary;

    $defs = [
        [
            'role' => 'recruiter',
            'linked' => 'placement_commission_recruiter',
            'name_keys' => [
                'recruiterName', 'recruiter_name', 'recruiter', 'recruiterFullName',
                'primaryRecruiter', 'primary recruiter',
            ],
            'email_keys' => ['recruiterEmail', 'recruiter_email', 'recruiter email'],
            'split_keys' => [
                'recruiter commission pct', 'recruiter commission %', 'recruiterCommissionPct',
                'recruiterCommissionPercent', 'recruiter split pct', 'recruiter split %',
                'recruiterSplitPct', 'primary recruiter split pct',
            ],
            'flat_keys' => [
                'recruiter commission flat', 'recruiterCommissionFlat',
                'recruiter commission amount', 'recruiterCommissionAmount',
            ],
        ],
        [
            'role' => 'account_manager',
            'linked' => 'placement_commission_account_manager',
            'name_keys' => [
                'accountManager', 'account_manager', 'accountManagerName',
                'salesperson', 'salesPerson', 'sales rep', 'salesRep',
            ],
            'email_keys' => [
                'accountManagerEmail', 'account_manager_email',
                'salesPersonEmail', 'salespersonEmail', 'sales rep email',
            ],
            'split_keys' => [
                'account manager commission pct', 'account manager commission %',
                'accountManagerCommissionPct', 'accountManagerSplitPct',
                'account manager split pct', 'salesperson commission pct',
                'salesPersonCommissionPct', 'sales commission pct',
            ],
            'flat_keys' => [
                'account manager commission flat', 'accountManagerCommissionFlat',
                'salesperson commission flat', 'salesPersonCommissionFlat',
            ],
        ],
        [
            'role' => 'lead',
            'linked' => 'placement_commission_lead',
            'name_keys' => ['lead', 'leadName', 'lead name', 'leadRecruiter'],
            'email_keys' => ['leadEmail', 'lead email'],
            'split_keys' => ['lead commission pct', 'lead commission %', 'lead split pct', 'leadSplitPct'],
            'flat_keys' => ['lead commission flat', 'leadCommissionFlat'],
        ],
        [
            'role' => 'team',
            'linked' => 'placement_commission_team',
            'name_keys' => ['team', 'teamName', 'team name'],
            'email_keys' => ['teamEmail', 'team email'],
            'split_keys' => ['team commission pct', 'team commission %', 'team split pct', 'teamSplitPct'],
            'flat_keys' => ['team commission flat', 'teamCommissionFlat'],
        ],
        [
            'role' => 'other',
            'linked' => 'placement_commission_other',
            'name_keys' => ['commissionOwner', 'commission owner', 'commissionPayee', 'commission payee'],
            'email_keys' => ['commissionOwnerEmail', 'commission owner email', 'commissionPayeeEmail'],
            'split_keys' => ['commission pct', 'commission %', 'commissionPercent', 'commissionSplitPct'],
            'flat_keys' => ['commission flat', 'commission amount', 'flatCommission', 'commissionFlat'],
        ],
    ];

    foreach ($defs as $def) {
        $role = (string) $def['role'];
        $linked = (string) $def['linked'];
        $splitRaw = jobdivaCommissionMappedOrDefault(
            $tid,
            'split_pct',
            $linked,
            $jd,
            static fn() => jobdivaPluckFieldDeep($jd, $def['split_keys'])
        );
        $splitPct = jobdivaParsePercent($splitRaw);
        if ($splitPct !== null && ($splitPct <= 0 || $splitPct > 1)) $splitPct = null;

        $flatRaw = jobdivaCommissionMappedOrDefault(
            $tid,
            'flat_amount',
            $linked,
            $jd,
            static fn() => jobdivaPluckFieldDeep($jd, $def['flat_keys'])
        );
        $flatAmount = jobdivaParseRateAmount($flatRaw);
        $flatAmount = $flatAmount > 0 ? $flatAmount : null;

        if ($splitPct === null && $flatAmount === null) continue;

        $basisRaw = (string) jobdivaCommissionMappedOrDefault(
            $tid,
            'basis',
            $linked,
            $jd,
            static fn() => jobdivaPluckFieldDeep($jd, [
                $role . ' commission basis', $role . 'CommissionBasis',
                'commission basis', 'commissionBasis',
            ])
        );
        $basis = jobdivaNormaliseCommissionBasis($basisRaw, $flatAmount !== null && $splitPct === null ? 'flat' : 'net_margin');

        $effectiveFromRaw = (string) jobdivaCommissionMappedOrDefault(
            $tid,
            'effective_from',
            $linked,
            $jd,
            static fn() => jobdivaPluckFieldDeep($jd, [
                $role . ' commission effective from', $role . 'CommissionEffectiveFrom',
                'commission effective from', 'commission start date',
            ])
        );
        $effectiveFrom = jobdivaNormaliseDate($effectiveFromRaw) ?: ($startDate !== '' ? $startDate : date('Y-m-d'));

        $effectiveToRaw = (string) jobdivaCommissionMappedOrDefault(
            $tid,
            'effective_to',
            $linked,
            $jd,
            static fn() => jobdivaPluckFieldDeep($jd, [
                $role . ' commission effective to', $role . 'CommissionEffectiveTo',
                'commission effective to', 'commission end date',
            ])
        );
        $effectiveTo = jobdivaNormaliseDate($effectiveToRaw);

        $name = jobdivaPluckFieldDeep($jd, $def['name_keys']);
        $email = jobdivaPluckFieldDeep($jd, $def['email_keys']);
        $commissionUserId = jobdivaResolvePlacementCommissionUserId($tid, $email !== '' ? $email : null, $name !== '' ? $name : null);

        $id = jobdivaSyncUpsertPlacementCommissionRow(
            $tid,
            $placementId,
            $role,
            $commissionUserId,
            $splitPct,
            $flatAmount,
            $basis,
            $effectiveFrom,
            $effectiveTo,
            $role
        );
        if ($id > 0) {
            $summary['written']++;
            $summary['rows'][$role] = $id;
        }
    }

    return $summary;
}

function jobdivaNormaliseReferralBasis(string $raw, bool $hasPercent, bool $hasFlat): string
{
    $s = strtolower(trim($raw));
    $s = str_replace([' ', '-'], '_', $s);
    if (str_contains($s, 'margin')) return 'pct_margin';
    if (str_contains($s, 'bill') || str_contains($s, 'percent') || str_contains($s, 'pct')) return 'pct_bill';
    if (str_contains($s, 'hour')) return 'per_hour';
    if (str_contains($s, 'invoice')) return 'per_invoice';
    if (str_contains($s, 'one') || str_contains($s, 'flat')) return 'one_time';
    if ($hasPercent) return 'pct_bill';
    return $hasFlat ? 'one_time' : 'pct_bill';
}

function jobdivaSyncUpsertPlacementReferral(
    int $tid,
    int $placementId,
    string $placementStartDate,
    array $jd,
    ?int $createdByUserId = null
): int {
    if ($tid <= 0 || $placementId <= 0) return 0;

    $feePct = jobdivaParsePercent(jobdivaPluckFieldDeep($jd, [
        'referral fee pct', 'referral fee %', 'referral percentage', 'referral percent',
        'referralFeePct', 'referralFeePercent', 'referral_pct', 'referral_percentage',
    ]));
    if ($feePct !== null && ($feePct <= 0 || $feePct > 1)) $feePct = null;
    $feeFlat = jobdivaParseRateAmount(jobdivaPluckFieldDeep($jd, [
        'referral fee flat', 'referral fee amount', 'referral flat amount',
        'referralFeeFlat', 'referralFeeAmount', 'referral_flat', 'referral_amount',
    ]));
    $feeFlat = $feeFlat > 0 ? $feeFlat : null;
    if ($feePct === null && $feeFlat === null) return 0;

    $name = trim(jobdivaPluckFieldDeep($jd, [
        'referrer name', 'referrerName', 'referrer', 'referred by', 'referredBy',
        'referral source', 'referralSource', 'referral partner', 'referralPartner',
        'referral vendor', 'referralVendor', 'referral agency', 'referralAgency',
    ]));
    $email = trim(jobdivaPluckFieldDeep($jd, [
        'referrer email', 'referrerEmail', 'referral email', 'referralEmail',
        'referral source email', 'referralSourceEmail',
    ]));
    $referrerUserId = jobdivaResolvePlacementCommissionUserId(
        $tid,
        $email !== '' ? $email : null,
        $name !== '' ? $name : null
    );
    if ($referrerUserId === null && $name === '') return 0;

    $basis = jobdivaNormaliseReferralBasis(
        jobdivaPluckFieldDeep($jd, [
            'referral fee basis', 'referralFeeBasis', 'referral basis', 'referralBasis',
        ]),
        $feePct !== null,
        $feeFlat !== null
    );
    $startDate = jobdivaNormaliseDate(jobdivaPluckFieldDeep($jd, [
        'referral start date', 'referralStartDate', 'referral effective from', 'referralEffectiveFrom',
    ])) ?: ($placementStartDate !== '' ? $placementStartDate : date('Y-m-d'));
    $endDate = jobdivaNormaliseDate(jobdivaPluckFieldDeep($jd, [
        'referral end date', 'referralEndDate', 'referral effective to', 'referralEffectiveTo',
    ]));
    $durationRaw = jobdivaParseRateAmount(jobdivaPluckFieldDeep($jd, [
        'referral duration months', 'referralDurationMonths', 'referral months', 'referralMonths',
    ]));
    $durationMonths = $durationRaw > 0 ? (int) round($durationRaw) : null;
    $termsRaw = trim(jobdivaPluckFieldDeep($jd, [
        'referral payment terms', 'referralPaymentTerms', 'referrer payment terms', 'referrerPaymentTerms',
    ]));
    $terms = $termsRaw !== '' ? placementEconomicsNormaliseTerms($termsRaw) : null;
    $pwpRaw = trim(jobdivaPluckFieldDeep($jd, [
        'referral paid when paid', 'referralPaidWhenPaid', 'referrer paid when paid',
        'referrerPaidWhenPaid', 'referral pwp', 'referralPwp',
    ]));
    $pwp = $pwpRaw !== ''
        ? jobdivaBoolishTrue($pwpRaw)
        : ($terms !== null ? placementEconomicsTermsArePwp($terms) : null);

    $companyId = null;
    $type = $referrerUserId !== null ? 'user' : 'vendor';
    if ($type === 'vendor') {
        $companyId = companiesUpsertByName($tid, $name, [
            'created_by_user_id' => $createdByUserId,
        ], ['referrer', 'vendor']);
    }

    $pdo = getDB();
    $existing = $pdo->prepare(
        'SELECT id
           FROM placement_referrals
          WHERE tenant_id = :t AND placement_id = :p
            AND notes LIKE "Source: JobDiva referral projection%"
          ORDER BY id ASC LIMIT 1'
    );
    $existing->execute(['t' => $tid, 'p' => $placementId]);
    $id = (int) $existing->fetchColumn();
    $values = [
        'type' => $type,
        'vendor_name' => $type === 'vendor' ? $name : null,
        'company_id' => $companyId,
        'user_id' => $referrerUserId,
        'fee_pct' => $feePct,
        'fee_flat' => $feeFlat,
        'basis' => $basis,
        'terms' => $terms,
        'pwp' => $pwp === null ? null : ($pwp ? 1 : 0),
        'duration' => $durationMonths,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'notes' => 'Source: JobDiva referral projection',
    ];
    if ($id > 0) {
        $values += ['id' => $id, 't' => $tid];
        $pdo->prepare(
            'UPDATE placement_referrals
                SET referrer_type = :type, referrer_vendor_name = :vendor_name,
                    referrer_company_id = :company_id, referrer_user_id = :user_id,
                    referrer_person_id = NULL, fee_pct = :fee_pct, fee_flat = :fee_flat,
                    fee_basis = :basis, payment_terms_override = COALESCE(:terms, payment_terms_override),
                    pwp_enabled = COALESCE(:pwp, pwp_enabled), duration_months = :duration,
                    start_date = :start_date, end_date = :end_date, notes = :notes
              WHERE id = :id AND tenant_id = :t'
        )->execute($values);
        return $id;
    }

    if ($values['pwp'] === null) $values['pwp'] = 0;
    $values += ['t' => $tid, 'p' => $placementId];
    $pdo->prepare(
        'INSERT INTO placement_referrals
            (tenant_id, placement_id, referrer_type, referrer_vendor_name,
             referrer_company_id, referrer_user_id, fee_pct, fee_flat, fee_basis,
             payment_terms_override, pwp_enabled, duration_months, start_date, end_date, notes)
         VALUES
            (:t, :p, :type, :vendor_name, :company_id, :user_id, :fee_pct, :fee_flat, :basis,
             :terms, :pwp, :duration, :start_date, :end_date, :notes)'
    )->execute($values);
    return (int) $pdo->lastInsertId();
}

function jobdivaSyncPlacementEconomicOptions(array $jd): array
{
    $terms = jobdivaPluckFieldDeep($jd, [
        'vendorPaymentTerms', 'vendor_payment_terms', 'paymentTerms', 'payment_terms',
        'supplierPaymentTerms', 'supplier_payment_terms', 'payeeTerms', 'payee_terms',
        'netTerms', 'net_terms',
    ]);
    $pwpRaw = strtolower(trim(jobdivaPluckFieldDeep($jd, [
        'paidWhenPaid', 'paid_when_paid', 'payWhenPaid', 'pay_when_paid',
        'pwp', 'isPwp', 'is_pwp',
    ])));
    $pwp = $pwpRaw === '' ? null
        : in_array($pwpRaw, ['1','true','yes','y','on','pwp','paid when paid','pay when paid'], true);
    if ($terms !== '' && placementEconomicsTermsArePwp($terms)) $pwp = true;
    $clientTerms = jobdivaPluckFieldDeep($jd, [
        'clientPaymentTerms', 'client_payment_terms', 'customerPaymentTerms', 'customer_payment_terms',
        'invoicePaymentTerms', 'invoice_payment_terms', 'billingPaymentTerms', 'billing_payment_terms',
    ]);
    return [
        'payment_terms' => $terms !== '' ? placementEconomicsNormaliseTerms($terms) : null,
        'pwp_enabled' => $pwp,
        'client_payment_terms' => $clientTerms !== '' ? placementEconomicsNormaliseTerms($clientTerms) : null,
    ];
}

function jobdivaPlacementProjectionAuditSnapshot(int $tenantId, int $placementId): array
{
    if ($tenantId <= 0 || $placementId <= 0) return [];
    try {
        $pdo = getDB();
    } catch (\Throwable $e) {
        return [];
    }

    $pick = static function (array $row, array $columns): array {
        return array_intersect_key($row, array_fill_keys($columns, true));
    };
    $snapshot = [
        'placement' => null,
        'rates' => [],
        'chain' => [],
        'corp' => [],
        'commissions' => [],
        'referrals' => [],
        'economic_parties' => [],
    ];
    try {
        $st = $pdo->prepare('SELECT * FROM placements WHERE tenant_id = :t AND id = :p LIMIT 1');
        $st->execute(['t' => $tenantId, 'p' => $placementId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $snapshot['placement'] = $pick($row, [
                'id', 'external_id', 'person_id', 'end_client_company_id', 'client_id', 'staffing_job_id',
                'title', 'start_date', 'end_date', 'actual_end_date', 'due_date', 'status', 'engagement_type',
                'worksite_state', 'worksite_country', 'remote_policy', 'notes', 'end_client_name',
                'client_approver_name', 'client_approver_email', 'jobdiva_job_id',
                'recruiter_name', 'recruiter_email', 'account_manager_name', 'account_manager_email',
                'client_bill_cycle', 'client_bill_cycle_anchor', 'client_payment_terms_override',
                'vendor_pay_cycle', 'vendor_pay_cycle_anchor', 'vendor_payment_terms_override',
                'vendor_pwp_enabled', 'coreflux_overridden_fields',
            ]);
        }
    } catch (\Throwable $e) {
        error_log('[jobdiva projection audit] placement snapshot failed: ' . $e->getMessage());
    }

    $children = [
        'rates' => ['placement_rates', [
            'id', 'effective_from', 'effective_to', 'bill_rate', 'pay_rate', 'bill_rate_unit',
            'pay_rate_unit', 'currency', 'ot_multiplier', 'dt_multiplier', 'adjusted_bill_rate',
            'net_bill_rate', 'approval_state', 'approved_at',
        ]],
        'chain' => ['placement_client_chain', [
            'id', 'position', 'company_id', 'party_name', 'party_role', 'portal_fee_pct',
            'portal_fee_flat', 'payment_terms_override', 'pwp_enabled', 'is_payable',
            'submittal_id', 'vms_job_id',
        ]],
        'corp' => ['placement_corp_details', [
            'id', 'corp_legal_name', 'vendor_id', 'company_id', 'contact_name',
            'contact_email', 'contact_phone',
        ]],
        'commissions' => ['placement_commissions', [
            'id', 'role', 'user_id', 'split_pct', 'basis', 'flat_amount',
            'effective_from', 'effective_to', 'notes',
        ]],
        'referrals' => ['placement_referrals', [
            'id', 'referrer_type', 'referrer_vendor_name', 'referrer_company_id',
            'referrer_user_id', 'fee_pct', 'fee_flat', 'fee_basis',
            'payment_terms_override', 'pwp_enabled', 'duration_months', 'start_date', 'end_date',
        ]],
        'economic_parties' => ['placement_economic_parties', [
            'id', 'source_ref', 'source_type', 'source_id', 'role', 'display_name',
            'company_id', 'person_id', 'ap_vendor_id', 'money_flow', 'settlement_channel',
            'fee_basis', 'fee_pct', 'fee_flat', 'payment_terms', 'pwp_enabled',
            'operating_cycle_id', 'effective_from', 'effective_to', 'source_system',
            'source_external_id', 'source_managed',
        ]],
    ];
    foreach ($children as $key => [$table, $columns]) {
        try {
            $st = $pdo->prepare(
                "SELECT * FROM {$table}
                  WHERE tenant_id = :t AND placement_id = :p
                  ORDER BY id ASC"
            );
            $st->execute(['t' => $tenantId, 'p' => $placementId]);
            while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
                $snapshot[$key][] = $pick($row, $columns);
            }
        } catch (\Throwable $e) {
            error_log("[jobdiva projection audit] {$table} snapshot failed: " . $e->getMessage());
        }
    }
    return $snapshot;
}

function jobdivaAuditPlacementProjection(
    int $tenantId,
    int $placementId,
    string $externalId,
    array $before,
    array $after,
    ?int $userId = null
): void {
    if ($before === $after) return;
    try {
        jobdivaAudit($tenantId, 'projection_write', [
            'entity_type' => 'placement',
            'direction' => 'pull',
            'ok' => true,
            'items_processed' => 1,
            'actor_user_id' => $userId,
            'detail' => [
                'placement_id' => $placementId,
                'external_id' => $externalId,
                'before' => $before,
                'after' => $after,
            ],
        ]);
    } catch (\Throwable $e) {
        error_log('[jobdiva projection audit] write failed: ' . $e->getMessage());
    }
}

function jobdivaSyncUpsertPlacement(int $tid, int $personId, ?int $endClientCompanyId, array $jd, string $extId, ?int $userId = null): int
{
    require_once __DIR__ . '/../integrations/field_map.php';
    $pdo = getDB();
    $sourceIdentity = jobdivaAssignmentValidate($jd, $extId);
    if (empty($sourceIdentity['valid'])) {
        throw new \RuntimeException(
            'JobDiva placement write refused: ' . (string) ($sourceIdentity['reason'] ?? 'unverified assignment')
        );
    }
    $canonicalExternalId = 'jd:' . $extId;
    $economicOptions = jobdivaSyncPlacementEconomicOptions($jd);
    // Look up by external_id first (placements has a `external_id` column).
    // A 2026-06 field-map regression briefly allowed tenant mappings to
    // overwrite placements.external_id with the raw Start ID. Recover those
    // rows here so the next sync updates the existing placement instead of
    // inserting another copy.
    $forcedExistingId = (int) ($jd['__cf_existing_placement_id'] ?? 0);
    $existingId = 0;
    if ($forcedExistingId > 0) {
        $stmt = $pdo->prepare(
            'SELECT id FROM placements
              WHERE tenant_id = :t
                AND id = :id
                AND (deleted_at IS NULL OR deleted_at = "0000-00-00 00:00:00")
              LIMIT 1'
        );
        $stmt->execute(['t' => $tid, 'id' => $forcedExistingId]);
        $existingId = (int) $stmt->fetchColumn();
        if ($existingId > 0) {
            $pdo->prepare(
                'UPDATE placements
                    SET external_id = :ext
                  WHERE tenant_id = :t AND id = :id'
            )->execute(['ext' => $canonicalExternalId, 't' => $tid, 'id' => $existingId]);
        }
    }
    if ($existingId <= 0) {
        $stmt = $pdo->prepare('SELECT id FROM placements WHERE tenant_id = :t AND external_id = :ext LIMIT 1');
        $stmt->execute(['t' => $tid, 'ext' => $canonicalExternalId]);
        $existingId = (int) $stmt->fetchColumn();
    }
    if ($existingId <= 0) {
        $stmt = $pdo->prepare(
            'SELECT id FROM placements
              WHERE tenant_id = :t
                AND external_id = :raw
                AND (deleted_at IS NULL OR deleted_at = "0000-00-00 00:00:00")
              ORDER BY id ASC
              LIMIT 1'
        );
        $stmt->execute(['t' => $tid, 'raw' => $extId]);
        $existingId = (int) $stmt->fetchColumn();
        if ($existingId > 0) {
            $pdo->prepare(
                'UPDATE placements
                    SET external_id = :ext
                  WHERE tenant_id = :t AND id = :id'
            )->execute(['ext' => $canonicalExternalId, 't' => $tid, 'id' => $existingId]);
        }
    }

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
    if ($title === '') {
        $titleJobId = jobdivaPluckField($jd, ['job id', 'jobId', 'job_id', 'jobID', 'JOBID']);
        if ($titleJobId !== '') {
            $titleJob = staffingJobFindBySource($tid, 'jobdiva', $titleJobId);
            $staffingTitle = trim((string) ($titleJob['title'] ?? ''));
            if ($staffingTitle !== ''
                && !preg_match('/^JobDiva (?:Job|Placement)\s+\S+$/i', $staffingTitle)) {
                $title = $staffingTitle;
            }
        }
    }

    // Last-resort placeholder. Kept distinct from the JobDiva ID so
    // operators can tell which placements had no Job Title available
    // (vs. genuinely synthetic ones). The Connected Sources panel
    // shows the actual JobDiva Start/Job IDs separately.
    if ($title === '') $title = 'JobDiva Placement ' . $extId;
    $titleHasSourceEvidence = !preg_match('/^JobDiva Placement\s+\S+$/i', $title);
    if ($existingId > 0 && preg_match('/^JobDiva Placement\s+\S+$/i', $title)) {
        try {
            $existingTitleStmt = $pdo->prepare(
                'SELECT title FROM placements
                  WHERE tenant_id = :t AND id = :id
                  LIMIT 1'
            );
            $existingTitleStmt->execute(['t' => $tid, 'id' => $existingId]);
            $existingTitle = trim((string) ($existingTitleStmt->fetchColumn() ?: ''));
            if ($existingTitle !== ''
                && !preg_match('/^JobDiva Placement\s+\S+$/i', $existingTitle)) {
                $title = $existingTitle;
            }
        } catch (\Throwable $e) {
            error_log('[jobdiva placement sync] existing title protection failed: ' . $e->getMessage());
        }
    }

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
        static fn() => jobdivaEndClientNameFromPayload($jd)
    );
    $clientId = null;
    $clientBridgeName = trim($endClientName);
    if ($clientBridgeName === '' && $endClientCompanyId !== null && $endClientCompanyId > 0) {
        try {
            $nameStmt = $pdo->prepare(
                'SELECT name FROM companies
                  WHERE tenant_id = :t AND id = :id AND deleted_at IS NULL
                  LIMIT 1'
            );
            $nameStmt->execute(['t' => $tid, 'id' => $endClientCompanyId]);
            $clientBridgeName = trim((string) ($nameStmt->fetchColumn() ?: ''));
        } catch (\Throwable $e) {
            error_log('[jobdiva placement sync] client bridge company-name lookup failed: ' . $e->getMessage());
        }
    }
    if ($clientBridgeName !== '') {
        try {
            $clientRef = staffingClientEnsureForCompany($tid, $endClientCompanyId, $clientBridgeName, [
                'created_by_user_id' => $userId,
            ]);
            $clientId = (int) ($clientRef['client_id'] ?? 0) ?: null;
            if (!empty($clientRef['company_id'])) {
                $endClientCompanyId = (int) $clientRef['company_id'];
            }
            if ($endClientName === '' && !empty($clientRef['name'])) {
                $endClientName = (string) $clientRef['name'];
            }
        } catch (\Throwable $e) {
            error_log('[jobdiva placement sync] staffing client bridge failed: ' . $e->getMessage());
        }
    }
    $statusRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'status', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, ['status', 'startStatus', 'placementStatus'])
    );
    $placementLifecycle = jobdivaAssignmentCanonicalPlacementStatus($statusRaw, $endDateNorm);
    $status = (string) $placementLifecycle['status'];

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
            'worker_type', 'classification', 'employmentType', 'employment type',
            'employmentCategory', 'employment_category', 'EMPLOYMENT_CATEGORY', 'employment category',
            'positionType', 'position_type', 'position type',
            'employeeType', 'employee_type', 'employee type',
            'taxType', 'tax_type', 'tax type',
            'payrollType', 'payroll_type', 'payroll type',
            'contractType', 'contract_type', 'contract type',
            'jobType', 'job_type', 'job type',
            'hireType', 'hire_type', 'hire type',
            'c2c', 'corp to corp', 'corp-to-corp', 'crop to crop',
            'corporation to corporation', 'isC2c', 'is_c2c',
        ])
    );
    $sourceEngagement = jobdivaInferPlacementEngagementTypeFromPayload($jd, '');
    $mappedEngagement = jobdivaNormalisePlacementEngagementType($engagementRaw, '');
    $hasMappedEngagement = function_exists('tenantIntegrationFieldMapHasInternal')
        && tenantIntegrationFieldMapHasInternal($tid, 'jobdiva', 'placement', 'engagement_type');
    // Strong JobDiva assignment evidence (1099/C2C/direct-hire/etc.) beats
    // a generic mapped W2 value. This prevents one broad tenant mapping from
    // flattening every placement to W2 while still honoring explicit non-W2
    // field-map choices.
    if ($sourceEngagement !== '' && ($mappedEngagement === '' || $mappedEngagement === 'w2')) {
        $engagement = $sourceEngagement;
    } elseif ($hasMappedEngagement && $mappedEngagement !== '') {
        $engagement = $mappedEngagement;
    } elseif ($sourceEngagement !== '') {
        $engagement = $sourceEngagement;
    } else {
        $engagement = $mappedEngagement !== '' ? $mappedEngagement : 'w2';
    }
    if ($engagement === 'c2c' && !jobdivaPlacementPayloadHasC2CProof($jd)) {
        $engagement = 'w2';
    }

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
    $contactNameKeys = ['name', 'fullName', 'full_name', 'contactName', 'contact_name', 'firstName', 'lastName'];
    $contactEmailKeys = ['email', 'emailAddress', 'email_address', 'primary email', 'primaryEmail', 'workEmail', 'work_email'];
    $contactNestOrder = ['_jd_contact', 'contact', 'Contact', 'jobdiva_contact'];
    $candidateNestOrder = ['_jd_candidate', 'candidate', 'Candidate', 'person', 'employee', 'worker', 'jobdiva_candidate'];
    $resolvedContactName = jobdivaPluckNestedField($jd, $contactNameKeys, $contactNestOrder);
    if ($resolvedContactName !== '' && !str_contains($resolvedContactName, ' ')) {
        $first = jobdivaPluckNestedField($jd, ['firstName', 'first_name', 'first name'], $contactNestOrder);
        $last = jobdivaPluckNestedField($jd, ['lastName', 'last_name', 'last name'], $contactNestOrder);
        $full = trim($first . ' ' . $last);
        if ($full !== '') $resolvedContactName = $full;
    }
    $resolvedContactEmail = jobdivaPluckNestedField($jd, $contactEmailKeys, $contactNestOrder);
    $candidateEmail = jobdivaPluckField($jd, [
        'candidateEmail', 'candidate_email', 'candidate email',
        'email', 'emailAddress', 'email_address', 'primary email', 'primaryEmail', 'workEmail', 'work_email',
    ]);
    if ($candidateEmail === '') {
        $candidateEmail = jobdivaPluckNestedField($jd, [
            'candidateEmail', 'candidate_email', 'candidate email',
            'email', 'emailAddress', 'email_address', 'primary email', 'primaryEmail', 'workEmail', 'work_email',
        ], $candidateNestOrder);
    }
    $candidateName = jobdivaPluckField($jd, ['candidateName', 'candidate_name', 'candidate name', 'name', 'fullName', 'full_name']);
    if ($candidateName === '') {
        $candidateName = jobdivaPluckNestedField($jd, ['name', 'fullName', 'full_name', 'candidateName', 'candidate_name'], $candidateNestOrder);
    }
    if ($candidateName === '') {
        $first = jobdivaPluckNestedField($jd, ['firstName', 'first_name', 'first name'], $candidateNestOrder);
        $last = jobdivaPluckNestedField($jd, ['lastName', 'last_name', 'last name'], $candidateNestOrder);
        $candidateName = trim($first . ' ' . $last);
    }
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
    // A broad tenant map or generic deep pluck must not copy candidate
    // identity into the client approval-contact fields.
    if ($candidateEmail !== '' && strcasecmp($approverEmail, $candidateEmail) === 0) {
        $approverEmail = $resolvedContactEmail !== '' && strcasecmp($resolvedContactEmail, $candidateEmail) !== 0
            ? $resolvedContactEmail
            : '';
    }
    if ($candidateName !== '' && strcasecmp($approverName, $candidateName) === 0) {
        $approverName = $resolvedContactName !== '' && strcasecmp($resolvedContactName, $candidateName) !== 0
            ? $resolvedContactName
            : '';
    }
    $jobdivaJobId = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'jobdiva_job_id', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, ['jobId', 'job_id', 'jobID', 'JOBID', 'reqId', 'req_id'])
    );
    $staffingJobId = null;
    if ($jobdivaJobId !== '') {
        $jobPayload = [];
        foreach (['_jd_job', 'job'] as $jobKey) {
            if (isset($jd[$jobKey]) && is_array($jd[$jobKey]) && $jd[$jobKey] !== []) {
                $jobPayload = $jd[$jobKey];
                break;
            }
        }
        if ($jobPayload === []) {
            $jobPayload = $jd;
        }
        $bridgedStaffingJobId = jobdivaBridgeStaffingJobFromPayload($tid, $jobdivaJobId, $jobPayload, $userId);
        if ($bridgedStaffingJobId !== null && $bridgedStaffingJobId > 0) {
            $staffingJobId = $bridgedStaffingJobId;
        }
        $staffingJob = staffingJobFindBySource($tid, 'jobdiva', $jobdivaJobId);
        if ($staffingJob) $staffingJobId = (int) ($staffingJob['id'] ?? 0) ?: null;
    }
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
        static fn() => jobdivaPluckFieldDeep($jd, [
            'billCycle', 'bill_cycle', 'clientBillCycle', 'client_bill_cycle',
            'invoiceFrequency', 'billingFrequency', 'billing_cycle',
        ])
    );
    $clientBillCycle = $coerceCycle($clientBillCycleRaw);
    $clientBillCycleAnchorRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'client_bill_cycle_anchor', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'billCycleAnchor', 'bill_cycle_anchor', 'clientBillCycleAnchor',
            'client_bill_cycle_anchor', 'billingAnchorDate', 'invoiceAnchor',
        ])
    );
    $clientBillCycleAnchor = jobdivaNormaliseDate($clientBillCycleAnchorRaw);
    $vendorPayCycleRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'vendor_pay_cycle', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'payCycle', 'pay_cycle', 'vendorPayCycle', 'vendor_pay_cycle',
            'payrollFrequency', 'pay_frequency',
        ])
    );
    $vendorPayCycle = $coerceCycle($vendorPayCycleRaw);
    $vendorPayCycleAnchorRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'vendor_pay_cycle_anchor', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'payCycleAnchor', 'pay_cycle_anchor', 'vendorPayCycleAnchor',
            'vendor_pay_cycle_anchor', 'payrollAnchorDate',
        ])
    );
    $vendorPayCycleAnchor = jobdivaNormaliseDate($vendorPayCycleAnchorRaw);

    if ($existingId > 0) {
        $projectionBefore = jobdivaPlacementProjectionAuditSnapshot($tid, $existingId);
        // Slice 2: respect coreflux_overridden_fields — fields the user edited
        // in CoreFlux must not be reverted on the next JobDiva pull. Strip
        // any overridden field from the SET clause and audit what we skipped.
        $overrides = [];
        $overrideStmt = $pdo->prepare(
            'SELECT coreflux_overridden_fields FROM placements WHERE tenant_id = :t AND id = :id LIMIT 1'
        );
        $overrideStmt->execute(['t' => $tid, 'id' => $existingId]);
        $rawOverride = $overrideStmt->fetchColumn();
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
            'client_id'            => ['cli',   $clientId],
            'client_approver_name' => ['can',   $approverName ?: null],
            'client_approver_email'=> ['cae',   $approverEmail ?: null],
            'title'                => ['ti',    $title],
            'jobdiva_job_id'       => ['jji',   $jobdivaJobId ?: null],
            'staffing_job_id'      => ['sji',   $staffingJobId],
            'recruiter_name'       => ['rn',    $recruiterName ?: null],
            'recruiter_email'      => ['re',    $recruiterEmail ?: null],
            'account_manager_name' => ['amn',   $accountManagerName ?: null],
            'account_manager_email'=> ['ame',   $accountManagerEmail ?: null],
            // Slice 5b broader-mapping additions
            'client_bill_cycle'         => ['cbc',  $clientBillCycle],
            'client_bill_cycle_anchor'  => ['cbca', $clientBillCycleAnchor],
            'client_payment_terms_override' => ['cpto', $economicOptions['client_payment_terms']],
            'vendor_pay_cycle'          => ['vpc',  $vendorPayCycle],
            'vendor_pay_cycle_anchor'   => ['vpca', $vendorPayCycleAnchor],
            'vendor_payment_terms_override' => ['vpto', $economicOptions['payment_terms']],
            'vendor_pwp_enabled'        => ['vpwp', $economicOptions['pwp_enabled'] === null ? null : (!empty($economicOptions['pwp_enabled']) ? 1 : 0)],
        ];
        // JobDiva Start/search responses are not shape-stable. A sparse row
        // must never clear a richer canonical placement merely because a
        // field is absent from this response. Only project a column when the
        // current exact-id payload supplied evidence for that value.
        $fieldEvidence = [
            'start_date' => $startDate !== '',
            'end_date' => $endDateNorm !== null,
            'actual_end_date' => $actualEnd !== null,
            'due_date' => $dueDate !== null,
            'status' => $statusRaw !== ''
                || ($endDateNorm !== null && $endDateNorm < date('Y-m-d')),
            'engagement_type' => $sourceEngagement !== ''
                || ($hasMappedEngagement && $mappedEngagement !== ''),
            'worksite_state' => trim($worksiteState) !== '',
            'worksite_country' => $worksiteCountry !== null,
            'remote_policy' => trim($remoteRaw) !== '' && $remote !== null,
            'notes' => trim($notes) !== '',
            'end_client_name' => trim($endClientName) !== '',
            'end_client_company_id' => $endClientCompanyId !== null && $endClientCompanyId > 0,
            'client_id' => $clientId !== null && $clientId > 0,
            'client_approver_name' => trim($approverName) !== '',
            'client_approver_email' => trim($approverEmail) !== '',
            'title' => $titleHasSourceEvidence,
            'jobdiva_job_id' => trim($jobdivaJobId) !== '',
            'staffing_job_id' => $staffingJobId !== null && $staffingJobId > 0,
            'recruiter_name' => trim($recruiterName) !== '',
            'recruiter_email' => trim($recruiterEmail) !== '',
            'account_manager_name' => trim($accountManagerName) !== '',
            'account_manager_email' => trim($accountManagerEmail) !== '',
            'client_bill_cycle' => $clientBillCycle !== null,
            'client_bill_cycle_anchor' => $clientBillCycleAnchor !== null,
            'client_payment_terms_override' => $economicOptions['client_payment_terms'] !== null,
            'vendor_pay_cycle' => $vendorPayCycle !== null,
            'vendor_pay_cycle_anchor' => $vendorPayCycleAnchor !== null,
            'vendor_payment_terms_override' => $economicOptions['payment_terms'] !== null,
            'vendor_pwp_enabled' => $economicOptions['pwp_enabled'] !== null,
        ];
        $assignments = [];
        $bindings = ['id' => $existingId];
        $skipped = [];
        foreach ($allFields as $col => [$bind, $val]) {
            if (in_array($col, $overrides, true)) {
                $skipped[] = $col;
                continue;
            }
            if (array_key_exists($col, $fieldEvidence) && !$fieldEvidence[$col]) {
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
            if ($val === null && in_array($col, ['client_id', 'staffing_job_id'], true)) {
                $skipped[] = $col;
                continue;
            }
            if ($val === null && in_array($col, ['client_payment_terms_override', 'vendor_payment_terms_override', 'vendor_pwp_enabled'], true)) {
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
        jobdivaSyncUpsertPlacementChain($tid, $existingId, $endClientCompanyId, $endClientName ?: null, $jd, $userId);
        if (!empty($fieldEvidence['engagement_type'])) {
            jobdivaSyncUpsertPlacementCorpDetails($tid, $existingId, $jd, $userId, $engagement);
        }
        jobdivaSyncUpsertPlacementCommissions($tid, $existingId, $startDate, $jd);
        jobdivaSyncUpsertPlacementReferral($tid, $existingId, $startDate, $jd, $userId);
        placementEconomicsReconcile($tid, $existingId, jobdivaSyncPlacementEconomicOptions($jd));
        jobdivaAuditPlacementProjection(
            $tid,
            $existingId,
            $extId,
            $projectionBefore,
            jobdivaPlacementProjectionAuditSnapshot($tid, $existingId),
            $userId
        );
        return $existingId;
    }
    $pdo->prepare(
        'INSERT INTO placements (tenant_id, person_id, external_id, jobdiva_job_id, status, start_date, end_date,
                                  actual_end_date, due_date, engagement_type, worksite_state, worksite_country,
                                  remote_policy, notes, end_client_name, end_client_company_id, client_id, staffing_job_id,
                                  client_approver_name, client_approver_email, title,
                                  recruiter_name, recruiter_email,
                                   account_manager_name, account_manager_email,
                                   client_bill_cycle, client_bill_cycle_anchor,
                                   client_payment_terms_override,
                                   vendor_pay_cycle, vendor_pay_cycle_anchor,
                                  vendor_payment_terms_override, vendor_pwp_enabled)
         VALUES (:t, :p, :ext, :jji, :st, :sd, :ed, :aed, :dd, :eng, :ws, :wc,
                 :rp, :notes, :ecn, :ecc, :cli, :sji, :can, :cae, :ti,
                 :rn, :re, :amn, :ame,
                  :cbc, :cbca, :cpto, :vpc, :vpca, :vpto, :vpwp)'
    )->execute([
        't'     => $tid,
        'p'     => $personId,
        'ext'   => $canonicalExternalId,
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
        'cli'   => $clientId,
        'sji'   => $staffingJobId,
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
        'cpto'  => $economicOptions['client_payment_terms'],
        'vpc'   => $vendorPayCycle ?? 'biweekly',
        'vpca'  => $vendorPayCycleAnchor,
        'vpto'  => $economicOptions['payment_terms'],
        'vpwp'  => !empty($economicOptions['pwp_enabled']) ? 1 : 0,
    ]);
    $placementId = (int) $pdo->lastInsertId();
    jobdivaSyncUpsertPlacementRates($tid, $placementId, $startDate, $jd);
    jobdivaSyncUpsertPlacementChain($tid, $placementId, $endClientCompanyId, $endClientName ?: null, $jd, $userId);
    jobdivaSyncUpsertPlacementCorpDetails($tid, $placementId, $jd, $userId, $engagement);
    jobdivaSyncUpsertPlacementCommissions($tid, $placementId, $startDate, $jd);
    jobdivaSyncUpsertPlacementReferral($tid, $placementId, $startDate, $jd, $userId);
    placementEconomicsReconcile($tid, $placementId, jobdivaSyncPlacementEconomicOptions($jd));
    jobdivaAuditPlacementProjection(
        $tid,
        $placementId,
        $extId,
        [],
        jobdivaPlacementProjectionAuditSnapshot($tid, $placementId),
        $userId
    );
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
function jobdivaParseRateAmount(mixed $raw): float
{
    if (is_int($raw) || is_float($raw)) return (float) $raw;
    $s = trim((string) $raw);
    if ($s === '') return 0.0;
    $s = str_replace([',', '$'], '', $s);
    if (is_numeric($s)) return (float) $s;
    if (preg_match('/-?\d+(?:\.\d+)?/', $s, $m)) {
        return (float) $m[0];
    }
    return 0.0;
}

function jobdivaParsePercent(mixed $raw): ?float
{
    if ($raw === null) return null;
    if (is_int($raw) || is_float($raw)) {
        $n = (float) $raw;
    } else {
        $s = trim((string) $raw);
        if ($s === '') return null;
        $hadPercent = str_contains($s, '%');
        $s = str_replace([',', '%'], '', $s);
        if (!is_numeric($s)) {
            if (!preg_match('/-?\d+(?:\.\d+)?/', $s, $m)) return null;
            $s = $m[0];
        }
        $n = (float) $s;
        if ($hadPercent) $n = $n / 100;
    }
    if (abs($n) > 1) $n = $n / 100;
    return round($n, 6);
}

function jobdivaSyncRemoveUnsourcedAutoDraftRate(int $tid, int $placementId, float $billRate): void
{
    if ($tid <= 0 || $placementId <= 0 || $billRate <= 0) return;
    try {
        $pdo = getDB();
        if (!$pdo) return;
        $pdo->prepare(
            'DELETE FROM placement_rates
              WHERE tenant_id = :t
                AND placement_id = :p
                AND approved_at IS NULL
                AND created_by_user_id IS NULL
                AND effective_to IS NULL
                AND ABS(bill_rate - :br) < 0.0001
                AND ABS(pay_rate - bill_rate) < 0.0001'
        )->execute([
            't'  => $tid,
            'p'  => $placementId,
            'br' => $billRate,
        ]);
    } catch (\Throwable $e) {
        error_log('[jobdiva placement rate cleanup] ' . $e->getMessage());
    }
}

function jobdivaSyncUpsertPlacementRates(int $tid, int $placementId, string $startDate, array $jd): bool
{
    require_once __DIR__ . '/../integrations/field_map.php';
    $pdo = getDB();

    // -- Resolve every rate field via the registry, with JobDiva-native
    //    default-key candidate lists shaped to the V2 BI payload.
    $billRateRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'bill_rate', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'final bill rate', 'finalBillRate', 'final_bill_rate',
            'bill rate', 'billRate', 'bill_rate',
            'billing rate', 'billingRate', 'billing_rate',
            'client bill rate', 'clientBillRate', 'client_bill_rate',
            'customer bill rate', 'customerBillRate', 'customer_bill_rate',
            'invoice rate', 'invoiceRate', 'invoice_rate',
            'charge rate', 'chargeRate', 'charge_rate',
            'sell rate', 'sellRate', 'sell_rate',
            'quoted bill rate', 'quotedBillRate', 'quoted_bill_rate',
            'BILLRATEMAX', 'billRateMax', 'bill_rate_max', 'bill rate max',
            'max bill rate', 'maximum bill rate', 'finalBillRateMax', 'final_bill_rate_max',
        ])
    );
    $billRate = jobdivaParseRateAmount($billRateRaw);
    if ($billRate <= 0) {
        // No rate present — placement is rate-less (direct hire,
        // perm placement, etc). Skip writing a rate row so we don't
        // pollute placement_rates with zero-valued placeholders.
        return false;
    }

    $payRateRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'pay_rate', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'agreed pay rate', 'agreedPayRate', 'agreed_pay_rate', 'AGREEDPAYRATE',
            'pay rate', 'payRate', 'pay_rate', 'PAYRATE',
            'vendor pay rate', 'vendorPayRate', 'vendor_pay_rate',
            'vendor rate', 'vendorRate', 'vendor_rate',
            'supplier pay rate', 'supplierPayRate', 'supplier_pay_rate',
            'supplier rate', 'supplierRate', 'supplier_rate',
            'contractor pay rate', 'contractorPayRate', 'contractor_pay_rate',
            'contractor rate', 'contractorRate', 'contractor_rate',
            'consultant pay rate', 'consultantPayRate', 'consultant_pay_rate',
            'subcontractor pay rate', 'subcontractorPayRate', 'subcontractor_pay_rate',
            'subcontractor rate', 'subcontractorRate', 'subcontractor_rate',
            'cost rate', 'costRate', 'cost_rate',
            'final pay rate', 'finalPayRate', 'final_pay_rate',
            'actual pay rate', 'actualPayRate', 'actual_pay_rate',
            'approved pay rate', 'approvedPayRate', 'approved_pay_rate',
            'candidate pay rate', 'candidatePayRate', 'candidate_pay_rate',
            'employee pay rate', 'employeePayRate', 'employee_pay_rate',
            'regular pay rate', 'regularPayRate', 'regular_pay_rate',
            'standard pay rate', 'standardPayRate', 'standard_pay_rate',
            'base pay rate', 'basePayRate', 'base_pay_rate',
            'pay rate max', 'payRateMax', 'pay_rate_max', 'PAYRATEMAX',
            'pay rate min', 'payRateMin', 'pay_rate_min', 'PAYRATEMIN',
            'max pay rate', 'maximum pay rate', 'payRateMaximum',
            'min pay rate', 'minimum pay rate', 'payRateMinimum',
            'hourly pay rate', 'hourlyPayRate', 'hourly_pay_rate', 'hourlyRate', 'hourly rate',
        ])
    );
    // pay_rate is NOT NULL on the schema, but copying bill_rate into
    // pay_rate creates a fake zero-margin contract. If JobDiva (or the
    // tenant field map) does not provide a real positive pay value, do
    // not create/refresh the rate row.
    $payRate = jobdivaParseRateAmount($payRateRaw);
    if ($payRate <= 0) {
        jobdivaSyncRemoveUnsourcedAutoDraftRate($tid, $placementId, $billRate);
        return false;
    }

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
        static fn() => jobdivaPluckFieldDeep($jd, [
            'final bill rate unit', 'finalBillRateUnit', 'final_bill_rate_unit',
            'bill rate currency/unit', 'billRateCurrencyUnit', 'bill_rate_currency_unit',
            'billRateUnit', 'bill_rate_unit', 'BILLRATEUNIT',
        ])
    ));
    $payRateUnit = $coerceUnit((string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'pay_rate_unit', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'pay rate currency/unit', 'payRateCurrencyUnit', 'pay_rate_currency_unit',
            'payRateUnit', 'pay_rate_unit', 'PAYRATEUNIT', 'hourly unit',
        ])
    ));

    // Currency: extract from "USD/Hour" style strings if needed.
    $currencyRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'currency', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'currency', 'CURRENCY', 'final bill rate currency', 'finalBillRateCurrency',
            'final_bill_rate_currency', 'bill rate currency/unit', 'billRateCurrencyUnit',
            'pay rate currency/unit', 'hourly currency',
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
        static fn() => jobdivaPluckFieldDeep($jd, ['ot_multiplier', 'otMultiplier', 'overtime_multiplier', 'OTMULTIPLIER'])
    );
    $dtRaw = (string) tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'dt_multiplier', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, ['dt_multiplier', 'dtMultiplier', 'doubletime_multiplier', 'DTMULTIPLIER'])
    );
    $otMul = is_numeric($otRaw) ? (float) $otRaw : 1.50;
    $dtMul = is_numeric($dtRaw) ? (float) $dtRaw : 2.00;

    $adderRaw = tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'adder_pct', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'adder_pct', 'adderPct', 'adder %', 'adder percent',
            'markup', 'mark up', 'markup %', 'markupPct', 'markup_pct',
            'markup percent', 'markupPercent', 'markup_percent',
            'burden', 'burden %', 'burden percent', 'burdenPercent',
            'employer burden', 'employer_burden_pct', 'employer burden pct',
            'load', 'load %', 'load percent', 'benefit load', 'payroll burden',
            'payroll_load_pct', 'payroll load pct', 'overhead percent', 'overhead pct',
        ])
    );
    $adderPct = jobdivaParsePercent($adderRaw);

    $backgroundFeeRaw = tenantIntegrationFieldMapPluckInternal(
        $tid, 'jobdiva', 'placement', 'background_fee_total', $jd,
        static fn() => jobdivaPluckFieldDeep($jd, [
            'background_fee_total', 'backgroundFeeTotal',
            'background fee', 'background fee total',
            'background check fee', 'screening fee',
            'onboarding fee', 'compliance fee',
            'other cost', 'other costs', 'additional cost', 'additional costs',
            'credentialing fee', 'drug test fee', 'drug screen fee',
        ])
    );
    $backgroundFeeTotal = jobdivaParseRateAmount($backgroundFeeRaw);
    $backgroundFeeTotal = $backgroundFeeTotal > 0 ? $backgroundFeeTotal : null;

    $mappedRateValue = static function (string $field, array $fallbackKeys = []) use ($tid, $jd): mixed {
        return tenantIntegrationFieldMapPluckInternal(
            $tid,
            'jobdiva',
            'placement',
            $field,
            $jd,
            static fn() => $fallbackKeys ? jobdivaPluckFieldDeep($jd, $fallbackKeys) : null
        );
    };
    $billAdderPct = jobdivaParsePercent($mappedRateValue('bill_adder_pct', [
        'client bill adder percent', 'bill adder percent', 'bill adder %',
    ]));
    $billAdderFlat = jobdivaParseRateAmount($mappedRateValue('bill_adder_flat', [
        'client bill adder', 'bill adder amount',
    ]));
    $billAdderFlat = $billAdderFlat > 0 ? $billAdderFlat : null;
    $billDiscountPct = jobdivaParsePercent($mappedRateValue('bill_discount_pct', [
        'client discount percent', 'bill discount percent', 'discount percent',
    ]));
    $billDiscountFlat = jobdivaParseRateAmount($mappedRateValue('bill_discount_flat', [
        'client discount amount', 'bill discount amount',
    ]));
    $billDiscountFlat = $billDiscountFlat > 0 ? $billDiscountFlat : null;
    $workersCompPct = jobdivaParsePercent($mappedRateValue('workers_comp_pct', [
        'workers comp percent', 'workers compensation percent', 'workers comp %', 'workers_comp_pct',
    ]));
    $benefitsLoadPct = jobdivaParsePercent($mappedRateValue('benefits_load_pct', [
        'benefits load percent', 'benefit load percent', 'benefits %', 'benefits_load_pct',
    ]));
    $otherCostPerHour = jobdivaParseRateAmount($mappedRateValue('other_cost_per_hour', [
        'other cost per hour', 'additional cost per hour', 'other_cost_per_hour',
    ]));
    $otherCostPerHour = $otherCostPerHour > 0 ? $otherCostPerHour : null;
    $otherCostFlat = jobdivaParseRateAmount($mappedRateValue('other_cost_flat', [
        'other fixed cost', 'additional fixed cost', 'other_cost_flat', 'fixed costs',
    ]));
    $otherCostFlat = $otherCostFlat > 0 ? $otherCostFlat : null;

    $effectiveFrom = $startDate !== '' ? $startDate : date('Y-m-d');
    $sourceContract = isset($jd['_jd_contract']) && is_array($jd['_jd_contract'])
        ? $jd['_jd_contract']
        : [];
    $sourceSnapshotJson = $sourceContract !== []
        ? json_encode([
            'source_system' => 'jobdiva',
            'assignment_contract' => $sourceContract,
            'source_overheads' => $sourceContract['overheads'] ?? [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : null;

    // Locate the current open rate row (effective_to IS NULL). Prefer an
    // unapproved draft so re-syncs refresh the approvable row. Approved
    // rows are locked snapshots: if JobDiva now differs, create a new draft
    // correction rather than silently mutating the approved economics.
    $existing = $pdo->prepare(
        'SELECT id, effective_from, approved_at, bill_rate, bill_rate_unit,
                pay_rate, pay_rate_unit, currency, ot_multiplier, dt_multiplier,
                adder_pct, background_fee_total,
                bill_adder_pct, bill_adder_flat, bill_discount_pct, bill_discount_flat,
                workers_comp_pct, benefits_load_pct, other_cost_per_hour, other_cost_flat,
                economics_snapshot_json, created_by_user_id
           FROM placement_rates
          WHERE tenant_id = :t AND placement_id = :p AND effective_to IS NULL
          ORDER BY (approved_at IS NULL) DESC, effective_from DESC, id DESC
          LIMIT 1'
    );
    $existing->execute(['t' => $tid, 'p' => $placementId]);
    $currentRate = $existing->fetch(\PDO::FETCH_ASSOC) ?: null;
    $rateId = (int) ($currentRate['id'] ?? 0);

    if ($rateId > 0 && empty($currentRate['approved_at'])) {
        // tenant-leak-allow: id was just fetched under tenant scope above
        $pdo->prepare(
            'UPDATE placement_rates
                SET effective_from = :ef,
                    bill_rate = :br, bill_rate_unit = :bru,
                    pay_rate  = :pr, pay_rate_unit  = :pru,
                    currency  = :cur,
                    ot_multiplier = :ot, dt_multiplier = :dt,
                    adder_pct = :adder, background_fee_total = :bg,
                    bill_adder_pct = :bill_adder_pct, bill_adder_flat = :bill_adder_flat,
                    bill_discount_pct = :bill_discount_pct, bill_discount_flat = :bill_discount_flat,
                    workers_comp_pct = :workers_comp_pct, benefits_load_pct = :benefits_load_pct,
                    other_cost_per_hour = :other_cost_per_hour, other_cost_flat = :other_cost_flat,
                    economics_snapshot_json = COALESCE(:economics_snapshot_json, economics_snapshot_json)
              WHERE id = :id'
        )->execute([
            'ef'  => $effectiveFrom,
            'br'  => $billRate, 'bru' => $billRateUnit,
            'pr'  => $payRate,  'pru' => $payRateUnit,
            'cur' => $currency,
            'ot'  => $otMul, 'dt' => $dtMul,
            'adder' => $adderPct,
            'bg'    => $backgroundFeeTotal,
            'bill_adder_pct' => $billAdderPct,
            'bill_adder_flat' => $billAdderFlat,
            'bill_discount_pct' => $billDiscountPct,
            'bill_discount_flat' => $billDiscountFlat,
            'workers_comp_pct' => $workersCompPct,
            'benefits_load_pct' => $benefitsLoadPct,
            'other_cost_per_hour' => $otherCostPerHour,
            'other_cost_flat' => $otherCostFlat,
            'economics_snapshot_json' => $sourceSnapshotJson,
            'id'  => $rateId,
        ]);
        return true;
    }

    $draftEffectiveTo = null;
    if ($rateId > 0 && !empty($currentRate['approved_at'])) {
        $sameEconomics =
            round((float) ($currentRate['bill_rate'] ?? 0), 4) === round($billRate, 4)
            && round((float) ($currentRate['pay_rate'] ?? 0), 4) === round($payRate, 4)
            && strtolower((string) ($currentRate['bill_rate_unit'] ?? '')) === strtolower($billRateUnit)
            && strtolower((string) ($currentRate['pay_rate_unit'] ?? '')) === strtolower($payRateUnit)
            && strtoupper((string) ($currentRate['currency'] ?? '')) === $currency
            && round((float) ($currentRate['ot_multiplier'] ?? 0), 4) === round($otMul, 4)
            && round((float) ($currentRate['dt_multiplier'] ?? 0), 4) === round($dtMul, 4)
            && round((float) ($currentRate['adder_pct'] ?? 0), 6) === round((float) ($adderPct ?? 0), 6)
            && round((float) ($currentRate['background_fee_total'] ?? 0), 2) === round((float) ($backgroundFeeTotal ?? 0), 2)
            && round((float) ($currentRate['bill_adder_pct'] ?? 0), 6) === round((float) ($billAdderPct ?? 0), 6)
            && round((float) ($currentRate['bill_adder_flat'] ?? 0), 4) === round((float) ($billAdderFlat ?? 0), 4)
            && round((float) ($currentRate['bill_discount_pct'] ?? 0), 6) === round((float) ($billDiscountPct ?? 0), 6)
            && round((float) ($currentRate['bill_discount_flat'] ?? 0), 4) === round((float) ($billDiscountFlat ?? 0), 4)
            && round((float) ($currentRate['workers_comp_pct'] ?? 0), 6) === round((float) ($workersCompPct ?? 0), 6)
            && round((float) ($currentRate['benefits_load_pct'] ?? 0), 6) === round((float) ($benefitsLoadPct ?? 0), 6)
            && round((float) ($currentRate['other_cost_per_hour'] ?? 0), 4) === round((float) ($otherCostPerHour ?? 0), 4)
            && round((float) ($currentRate['other_cost_flat'] ?? 0), 2) === round((float) ($otherCostFlat ?? 0), 2);
        $coversPlacementStart = (string) ($currentRate['effective_from'] ?? '') <= $effectiveFrom;
        if ($sameEconomics && $coversPlacementStart) {
            return true;
        }
        $currentEffectiveFrom = (string) ($currentRate['effective_from'] ?? '');
        if ($currentEffectiveFrom !== '' && $currentEffectiveFrom > $effectiveFrom) {
            $toTs = strtotime($currentEffectiveFrom . ' -1 day');
            if ($toTs !== false) {
                $draftEffectiveTo = date('Y-m-d', $toTs);
            }
        }
        // Fall through to INSERT a draft correction. The placement approval
        // helper will approve it under the normal margin/audit path.
    }

    $pdo->prepare(
        'INSERT INTO placement_rates
            (tenant_id, placement_id, effective_from, effective_to, bill_rate, bill_rate_unit,
             pay_rate, pay_rate_unit, currency, ot_multiplier, dt_multiplier,
             adder_pct, background_fee_total,
             bill_adder_pct, bill_adder_flat, bill_discount_pct, bill_discount_flat,
             workers_comp_pct, benefits_load_pct, other_cost_per_hour, other_cost_flat,
             economics_snapshot_json)
         VALUES (:t, :p, :ef, :et, :br, :bru, :pr, :pru, :cur, :ot, :dt, :adder, :bg,
                 :bill_adder_pct, :bill_adder_flat, :bill_discount_pct, :bill_discount_flat,
                 :workers_comp_pct, :benefits_load_pct, :other_cost_per_hour, :other_cost_flat,
                 :economics_snapshot_json)'
    )->execute([
        't'   => $tid, 'p'   => $placementId,
        'ef'  => $effectiveFrom,
        'et'  => $draftEffectiveTo,
        'br'  => $billRate, 'bru' => $billRateUnit,
        'pr'  => $payRate,  'pru' => $payRateUnit,
        'cur' => $currency,
        'ot'  => $otMul, 'dt' => $dtMul,
        'adder' => $adderPct,
        'bg'    => $backgroundFeeTotal,
        'bill_adder_pct' => $billAdderPct,
        'bill_adder_flat' => $billAdderFlat,
        'bill_discount_pct' => $billDiscountPct,
        'bill_discount_flat' => $billDiscountFlat,
        'workers_comp_pct' => $workersCompPct,
        'benefits_load_pct' => $benefitsLoadPct,
        'other_cost_per_hour' => $otherCostPerHour,
        'other_cost_flat' => $otherCostFlat,
        'economics_snapshot_json' => $sourceSnapshotJson,
    ]);
    return true;
}

/**
 * Generic JobDiva V2 BI "mirror" sync — pulls every record from one of
 * JobDiva's `/apiv2/bi/NewUpdated*Records` endpoints and stores each
 * row's FULL payload in `external_entity_mappings` (under
 * source_system='jobdiva', internal_entity_type=$entityType).
 *
 * Unlike `jobdivaSyncCompanies()` / `…Contacts()`, this helper does
 * NOT upsert into a paired CoreFlux table — the goal is purely to
 * mirror JobDiva's data so the Field Mapping Studio's source-side
 * picker has every record's every field available to map FROM.
 *
 * Why we need this:
 *   - JobDiva's per-record `/searchJob`, `/searchCandidate`, etc.
 *     endpoints return EMPTY for many tenants (account-scope auth).
 *   - But JobDiva's bulk BI endpoints (`NewUpdatedJobRecords`,
 *     `NewUpdatedCandidateRecords`) return full records reliably.
 *   - Operators want every JobDiva field mappable — not just the
 *     subset that survives the placement-level enrichment dance.
 *
 * The payload also feeds `payload_field_index`. Native mirror buckets
 * remain as evidence, but the Field Mapping Studio rolls them into the
 * canonical placement/person/company/contact roots for mapping.
 *
 * Internal_entity_id is a sentinel (cast of $extId or crc32 hash)
 * because there is no paired CoreFlux row — the table's `uk_internal`
 * unique key still gets a unique value per JobDiva record. Operator
 * ask: "MIRROR JOB DIVA INTO TENANT DATABASE."
 */
function jobdivaSyncMirrorEntity(
    int $tid,
    string $entityType,
    string $endpoint,
    array $idCandidates,
    ?int $userId,
    array $opts = []
): array {
    require_once __DIR__ . '/../integrations/payload_field_index.php';

    // First-ever mirror sync for this entity_type widens the window to
    // 365 days so the operator backfills the full reachable history.
    if (!isset($opts['items_override']) && !isset($opts['modified_since'])
        && jobdivaSyncIsFirstSync($tid, $entityType)) {
        $opts['default_window_days'] = 365;
        jobdivaAudit($tid, 'sync_first_backfill', [
            'ok'     => true,
            'detail' => ['entity' => $entityType, 'window_days' => 365],
            'actor_user_id' => $userId,
        ]);
    }

    $items = isset($opts['items_override']) && is_array($opts['items_override'])
        ? $opts['items_override']
        : jobdivaSyncFetchWithRetry($tid, $endpoint, $opts);

    $pdo = getDB();
    $processed = 0; $skipped = 0; $failed = 0; $errors = [];
    $itemsFetched = count($items);
    $sampleKeys = [];

    foreach ($items as $idx => $jd) {
        if ($idx < 3 && is_array($jd)) $sampleKeys[$idx] = array_keys($jd);
        try {
            $extId = (string) jobdivaPluckField($jd, $idCandidates);
            if ($extId === '') { $skipped++; continue; }

            // 1) Mirror the full payload — feeds the "🔬 Raw payload"
            // diagnostic so operators can see every field JobDiva sent.
            if ($pdo !== null) {
                $internalSentinel = ctype_digit($extId) ? (int) $extId : abs(crc32($extId));
                if ($internalSentinel <= 0) $internalSentinel = 1;
                mappingUpsert($tid, 'jobdiva', $entityType, $extId, $internalSentinel, $jd, 'pull', $userId);
            }

            // 2) Index every field — feeds the Field Mapping Studio's
            // source-side picker and entity-tab counts.
            foreach (jobdivaCanonicalFieldIndexEntityTypes($entityType) as $indexEntityType) {
                $payloadForIndex = jobdivaCanonicalPayloadForEntity($entityType, $indexEntityType, $jd);
                integrationPayloadFieldIndexRecord($tid, 'jobdiva', $indexEntityType, $payloadForIndex);
            }

            if ($entityType === 'jobdiva_job' && $pdo !== null) {
                jobdivaBridgeStaffingJobFromPayload($tid, $extId, $jd, $userId);
            }

            $processed++;
        } catch (\Throwable $e) {
            $failed++;
            $errors[] = ['entity' => $entityType, 'external_id' => $extId ?? '?', 'error' => substr($e->getMessage(), 0, 240)];
            error_log("[jobdiva $entityType mirror sync] " . $e->getMessage());
            if (count($errors) >= 50) break;
        }
    }

    jobdivaAudit($tid, 'sync', [
        'entity_type'     => $entityType,
        'direction'       => 'pull',
        'ok'              => $failed === 0,
        'items_processed' => $processed,
        'items_skipped'   => $skipped,
        'items_failed'    => $failed,
        'actor_user_id'   => $userId,
        'detail'          => [
            'endpoint'       => $endpoint,
            'items_fetched'  => $itemsFetched,
            'empty_response' => $itemsFetched === 0,
            'skip_reasons'   => $skipped > 0 ? ['missing_external_id' => $skipped] : [],
            'sample_keys'    => $sampleKeys,
            'errors'         => array_slice($errors, 0, 5),
        ],
    ]);
    return [
        'processed'      => $processed,
        'skipped'        => $skipped,
        'failed'         => $failed,
        'errors'         => $errors,
        'endpoint'       => $endpoint,
        'items_fetched'  => $itemsFetched,
        'empty_response' => $itemsFetched === 0,
        'skip_reasons'   => $skipped > 0 ? ['missing_external_id' => $skipped] : [],
    ];
}

/**
 * Mirror every JobDiva Job record into `external_entity_mappings`
 * under `internal_entity_type='jobdiva_job'`. JobDiva's `/apiv2/bi/NewUpdatedJobRecords`
 * endpoint returns the full Job record (title, status, location, rate
 * range, custom fields, etc.) — every field becomes mappable in the
 * Studio without per-record enrichment.
 */
function jobdivaSyncJobs(int $tid, ?int $userId, array $opts = []): array
{
    return jobdivaSyncMirrorEntity(
        $tid, 'jobdiva_job', '/apiv2/bi/NewUpdatedJobRecords',
        ['id', 'jobId', 'job_id', 'jobID', 'JOBID', 'job id'],
        $userId, $opts
    );
}

/**
 * Mirror every JobDiva Candidate record into `external_entity_mappings`
 * under `internal_entity_type='jobdiva_candidate'`. Canonical mapping
 * surfaces this data under `person`. JobDiva's
 * `/apiv2/bi/NewUpdatedCandidateRecords` returns the full Candidate
 * record (every contact field, work-auth, skills, custom fields, etc.).
 */
function jobdivaSyncCandidates(int $tid, ?int $userId, array $opts = []): array
{
    return jobdivaSyncMirrorEntity(
        $tid, 'jobdiva_candidate', '/apiv2/bi/NewUpdatedCandidateRecords',
        ['id', 'candidateId', 'candidate_id', 'candidateID', 'CANDIDATEID', 'candidate id', 'employeeId'],
        $userId, $opts
    );
}

/**
 * Bulk fetch JobDiva entity records by ID list via the V2 BI `*Detail`
 * endpoints. Used for the "mirror by placements" sync: we already
 * know every `job id` / `candidate id` / `customer id` from the
 * placement payloads, so we fetch full detail records by ID instead
 * of date-range crawling (which JobDiva auth doesn't grant for many
 * tenants' Job/Candidate scopes).
 *
 * Spring V2 controllers accept `@RequestParam List<>` as either
 * repeated params OR comma-joined. PHP's `http_build_query` produces
 * `jobIds[]=...` which Spring rejects, so we explicitly comma-join.
 *
 * Batches into chunks of `$batchSize` IDs to keep URL length under
 * the proxy limit (most CDNs cap at ~8K). Failures within a single
 * batch are logged and continue — operator's "GET EVERY SINGLE BIT
 * OF DATA" demand has us absorb partial errors rather than abort.
 */
function jobdivaCallBulkIds(
    int $tid,
    string $path,
    string $idParamName,
    array $ids,
    array $extraQuery = [],
    int $batchSize = 50
): array {
    if (empty($ids)) return [];

    $unique = array_values(array_unique(array_filter(array_map('strval', $ids), static fn($v) => $v !== '')));
    if (empty($unique)) return [];

    $all = [];
    foreach (array_chunk($unique, max(1, $batchSize)) as $batch) {
        $query = $extraQuery + [
            $idParamName     => implode(',', $batch),
            'userFieldsName' => '',  // dodge the Spring NPE on optional array params
        ];
        try {
            $resp = jobdivaCall($tid, 'GET', $path, null, $query);
            if (is_array($resp)) {
                if (isset($resp['data']) && is_array($resp['data'])) {
                    $all = array_merge($all, $resp['data']);
                } elseif (isset($resp['items']) && is_array($resp['items'])) {
                    $all = array_merge($all, $resp['items']);
                } elseif (!empty($resp) && array_keys($resp) === range(0, count($resp) - 1)) {
                    $all = array_merge($all, $resp);
                }
            }
        } catch (\Throwable $e) {
            error_log("[jobdiva bulk-ids $path batch=" . count($batch) . " err] " . $e->getMessage());
        }
    }
    return $all;
}

/**
 * Store + index a batch of raw JobDiva records under one entity_type.
 * Extracted from jobdivaSyncMirrorEntity so the new ID-based mirror
 * can reuse it.
 */
function jobdivaMirrorStoreAndIndex(
    int $tid,
    string $entityType,
    array $items,
    array $idKeys,
    ?int $userId = null
): array {
    require_once __DIR__ . '/../integrations/payload_field_index.php';
    $pdo = getDB();
    $processed = 0; $skipped = 0; $failed = 0;
    foreach ($items as $jd) {
        try {
            $extId = (string) jobdivaPluckField($jd, $idKeys);
            if ($extId === '') { $skipped++; continue; }
            if ($pdo !== null) {
                $internalSentinel = ctype_digit($extId) ? (int) $extId : abs(crc32($extId));
                if ($internalSentinel <= 0) $internalSentinel = 1;
                mappingUpsert($tid, 'jobdiva', $entityType, $extId, $internalSentinel, $jd, 'pull', $userId);
            }
            foreach (jobdivaCanonicalFieldIndexEntityTypes($entityType) as $indexEntityType) {
                $payloadForIndex = jobdivaCanonicalPayloadForEntity($entityType, $indexEntityType, $jd);
                integrationPayloadFieldIndexRecord($tid, 'jobdiva', $indexEntityType, $payloadForIndex);
            }
            if ($entityType === 'jobdiva_job' && $pdo !== null) {
                jobdivaBridgeStaffingJobFromPayload($tid, $extId, $jd, $userId);
            }
            $processed++;
        } catch (\Throwable $e) {
            $failed++;
            error_log("[jobdiva mirror-store $entityType] " . $e->getMessage());
        }
    }
    return ['processed' => $processed, 'skipped' => $skipped, 'failed' => $failed];
}

/**
 * Operator-demanded "MIRROR JOB DIVA INTO TENANT DATABASE" sync, take 2.
 *
 * Strategy (built on the official V2 Swagger spec, not guesswork):
 *  1. Read every stored placement's payload_snapshot.
 *  2. Extract unique `job id` / `candidate id` / `customer id` from each.
 *  3. Batch-call:
 *       /apiv2/bi/JobsDetail?jobIds=…       → full Job records
 *       /apiv2/bi/CandidatesDetail?…        → full Candidate records
 *       /apiv2/bi/CompaniesDetail?…         → full Company records
 *  4. Store each payload + index every field for the Field Mapping
 *     Studio's source-side picker.
 *
 * Why this is better than NewUpdated*Records:
 *  - No date-range guesswork. We use IDs we already have.
 *  - Per-tenant API auth tends to grant *Detail-by-id access even when
 *    the date-range delta endpoints are scoped out.
 *  - Single round-trip per ID batch (up to 50 records each), so a
 *    full mirror of 100 jobs + 100 candidates + 50 customers is
 *    typically <10 HTTP calls total.
 */
function jobdivaSyncMirrorByPlacements(int $tid, ?int $userId, array $opts = []): array
{
    $stats = [
        'placements_scanned'      => 0,
        'unique_job_ids'          => 0,
        'unique_candidate_ids'    => 0,
        'unique_customer_ids'     => 0,
        'unique_start_ids'        => 0,
        'jobs_returned'           => 0,
        'candidates_returned'     => 0,
        'customers_returned'      => 0,
        'assignments_returned'    => 0,
        'jobs_processed'          => 0,
        'candidates_processed'    => 0,
        'customers_processed'     => 0,
        'assignments_processed'   => 0,
        'assignment_channel'      => 'none',
        'assignment_search_start_attempts'     => 0,
        'assignment_search_start_errors'       => [],
        'assignment_identity_rejections'       => 0,
        'assignment_snapshot_rows'             => 0,
        'assignment_supported_lookup_attempts' => 0,
    ];
    $pdo = getDB();
    if (!$pdo) {
        $stats['error'] = 'no_database_connection';
        return $stats;
    }

    // 1. Scan every jobdiva placement payload and collect IDs.
    $st = $pdo->prepare(
        "SELECT payload_snapshot
           FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = 'jobdiva'
            AND internal_entity_type = 'placement'
            AND sync_status = 'ok'
            AND payload_snapshot IS NOT NULL"
    );
    $st->execute(['t' => $tid]);

    $jobIds = $candIds = $custIds = $startIds = [];
    $assignmentSeeds = $assignmentHints = [];
    while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
        $stats['placements_scanned']++;
        $payload = json_decode((string) $row['payload_snapshot'], true);
        if (!is_array($payload)) continue;
        $rootPayload = jobdivaAssignmentStripDerivedFacets($payload);
        $j = jobdivaPluckField($rootPayload, ['job id', 'jobId', 'job_id', 'jobID', 'JOBID']);
        $c = jobdivaPluckField($rootPayload, ['candidate id', 'candidateId', 'candidate_id', 'candidateID', 'CANDIDATEID', 'employeeId']);
        $u = jobdivaPluckField($rootPayload, ['customer id', 'customerId', 'customer_id', 'customerID', 'CUSTOMERID']);
        // JobDiva calls placement records "Starts" — the `id` field on
        // exact assignment identity captured by searchStart discovery.
        $s = jobdivaPluckField($rootPayload, ['id', 'startId', 'start_id', 'startID', 'STARTID', 'placementId']);
        if ($j !== null) $jobIds[]   = (string) $j;
        if ($c !== null) $candIds[]  = (string) $c;
        if ($u !== null) $custIds[]  = (string) $u;
        if ($s !== null && trim((string) $s) !== '') {
            $sid = jobdivaAssignmentIdentityNormaliseId((string) $s);
            $startIds[] = $sid;
            $assignmentHints[$sid] = $rootPayload;
            $seed = jobdivaAssignmentMarkVerified(
                $rootPayload,
                $sid,
                'searchStart:placement_snapshot'
            );
            $seedIdentity = jobdivaAssignmentValidate(
                $seed,
                $sid,
                'searchStart:placement_snapshot'
            );
            if (!empty($seedIdentity['valid'])) {
                $assignmentSeeds[$sid] = $seed;
            } else {
                $stats['assignment_identity_rejections']++;
            }
        }
    }

    $jobIds   = array_values(array_unique(array_filter($jobIds,   static fn($v) => $v !== '' && $v !== '0')));
    $candIds  = array_values(array_unique(array_filter($candIds,  static fn($v) => $v !== '' && $v !== '0')));
    $custIds  = array_values(array_unique(array_filter($custIds,  static fn($v) => $v !== '' && $v !== '0')));
    $startIds = array_values(array_unique(array_filter($startIds, static fn($v) => $v !== '' && $v !== '0')));

    $stats['unique_job_ids']       = count($jobIds);
    $stats['unique_candidate_ids'] = count($candIds);
    $stats['unique_customer_ids']  = count($custIds);
    $stats['unique_start_ids']     = count($startIds);

    // 2. Bulk-fetch full records via the V2 BI endpoints.
    //
    // JOBS — two-phase fetch:
    //   (a) `OpenJobsList` (no params, no auth-scope, returns ALL open
    //       jobs — diagnostic confirmed 1,715 records / 1.45 MB for
    //       the operator's tenant).
    //   (b) `JobsDetail?jobIds=…` to fill in any placement-referenced
    //       job that's no longer in the open list (closed/archived).
    // The two are merged and deduped by external_id so the open-list
    // bulk doesn't drown out long-tail historic jobs.
    $jobs = [];
    try {
        $openResp = jobdivaCall($tid, 'GET', '/apiv2/bi/OpenJobsList', null, []);
        if (is_array($openResp)) {
            if (isset($openResp['data']) && is_array($openResp['data'])) {
                $jobs = $openResp['data'];
            } elseif (isset($openResp['items']) && is_array($openResp['items'])) {
                $jobs = $openResp['items'];
            } elseif (!empty($openResp) && array_keys($openResp) === range(0, count($openResp) - 1)) {
                $jobs = $openResp;
            }
        }
    } catch (\Throwable $e) {
        error_log('[jobdiva OpenJobsList err] ' . $e->getMessage());
    }
    if ($jobIds) {
        $byId = jobdivaCallBulkIds($tid, '/apiv2/bi/JobsDetail', 'jobIds', $jobIds);
        // Dedupe: prefer the more-recent record. Both responses share
        // the same `id` key in JobDiva BI, so keep whichever lands first
        // and skip duplicates by extId.
        $seen = [];
        foreach ($jobs as $j) {
            $eid = (string) jobdivaPluckField($j, ['id', 'jobId', 'job_id', 'jobID', 'JOBID', 'job id']);
            if ($eid !== '') $seen[$eid] = true;
        }
        foreach ($byId as $j) {
            $eid = (string) jobdivaPluckField($j, ['id', 'jobId', 'job_id', 'jobID', 'JOBID', 'job id']);
            if ($eid !== '' && empty($seen[$eid])) {
                $jobs[] = $j;
                $seen[$eid] = true;
            }
        }
    }
    if ($jobs) {
        $stats['jobs_returned'] = count($jobs);
        $stored = jobdivaMirrorStoreAndIndex($tid, 'jobdiva_job', $jobs,
            ['id', 'jobId', 'job_id', 'jobID', 'JOBID', 'job id'], $userId);
        $stats['jobs_processed'] = $stored['processed'];
    }

    if ($candIds) {
        $cands = jobdivaCallBulkIds($tid, '/apiv2/bi/CandidatesDetail', 'candidateIds', $candIds);
        $stats['candidates_returned'] = count($cands);
        $stored = jobdivaMirrorStoreAndIndex($tid, 'jobdiva_candidate', $cands,
            ['id', 'candidateId', 'candidate_id', 'candidateID', 'CANDIDATEID', 'candidate id', 'employeeId']);
        $stats['candidates_processed'] = $stored['processed'];
    }

    // The `customer id` field in placement payloads is actually a CONTACT
    // ID (the end-client contact, e.g. Patricia Moore), NOT a company
    // ID — operator's diagnostic confirmed CompaniesDetail?companyIds=
    // returns 0 items for these IDs, while ContactsDetail?contactIds=
    // is the correct endpoint per the official Swagger spec.
    if ($custIds) {
        $contacts = jobdivaCallBulkIds($tid, '/apiv2/bi/ContactsDetail', 'contactIds', $custIds);
        $stats['customers_returned'] = count($contacts);
        $stored = jobdivaMirrorStoreAndIndex($tid, 'jobdiva_contact', $contacts,
            ['id', 'contactId', 'contact_id', 'contactID', 'CONTACTID', 'contact id', 'customerId']);
        $stats['customers_processed'] = $stored['processed'];
    }

    // ASSIGNMENTS — JobDiva calls placement records "Starts" in their
    // model. The placement mapping is already the exact searchStart row
    // selected during discovery, so it is the authoritative assignment
    // mirror. Older invalid snapshots may be retried only with JobDiva's
    // supported jobId/candidateid criteria and exact response validation.
    if ($startIds) {
        $assignmentRecords = [];
        $assignmentRecordIds = [];
        $cap = (int) ($opts['assignment_cap'] ?? 500);
        $batch = array_slice($startIds, 0, $cap);
        $appendAssignmentRecord = static function (
            mixed $row,
            string $startId,
            string $channel
        ) use (&$assignmentRecords, &$assignmentRecordIds, &$stats): void {
            if (!is_array($row)) return;
            $rowId = jobdivaAssignmentRowId($row);
            if ($rowId === '' || $rowId !== jobdivaAssignmentIdentityNormaliseId($startId)) {
                $stats['assignment_identity_rejections']++;
                return;
            }
            $row = jobdivaAssignmentMarkVerified($row, $rowId, $channel);
            $identity = jobdivaAssignmentValidate($row, $rowId, $channel);
            if (empty($identity['valid'])) {
                $stats['assignment_identity_rejections']++;
                return;
            }
            $assignmentRecords[] = $row;
            $assignmentRecordIds[$rowId] = true;
        };

        // Exact source snapshots are the primary assignment channel.
        foreach ($batch as $sid) {
            $sid = jobdivaAssignmentIdentityNormaliseId($sid);
            if (!isset($assignmentSeeds[$sid])) continue;
            $appendAssignmentRecord(
                $assignmentSeeds[$sid],
                $sid,
                'searchStart:placement_snapshot'
            );
        }
        $stats['assignment_snapshot_rows'] = count($assignmentRecordIds);

        // Legacy snapshots without valid Start evidence are retried with
        // supported searchStart criteria, never an ignored startId body.
        $missingStartIds = array_values(array_filter(
            $batch,
            static fn($sid): bool => !isset(
                $assignmentRecordIds[jobdivaAssignmentIdentityNormaliseId($sid)]
            )
        ));
        if ($missingStartIds) {
            $ch2Errors = [];
            foreach ($missingStartIds as $sid) {
                $stats['assignment_search_start_attempts']++;
                $stats['assignment_supported_lookup_attempts']++;
                try {
                    $normalisedSid = jobdivaAssignmentIdentityNormaliseId($sid);
                    $exact = jobdivaFetchExactAssignmentById(
                        $tid,
                        $normalisedSid,
                        $assignmentHints[$normalisedSid] ?? []
                    );
                    if (($exact['status'] ?? '') === 'verified' && is_array($exact['row'] ?? null)) {
                        $appendAssignmentRecord(
                            $exact['row'],
                            $normalisedSid,
                            'searchStart:criteria_exact'
                        );
                        continue;
                    }
                    $ch2Errors[] = [
                        'startId' => $normalisedSid,
                        'status' => (string) ($exact['status'] ?? 'error'),
                        'error' => substr((string) ($exact['error'] ?? 'lookup failed'), 0, 200),
                    ];
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    $ch2Errors[] = [
                        'startId' => (string) $sid,
                        'status' => 'error',
                        'error' => substr($msg, 0, 200),
                    ];
                    error_log("[jobdiva searchStart criteria lookup startId=$sid err] " . $msg);
                }
            }
            $stats['assignment_search_start_errors']   = $ch2Errors;
        }

        if (count($assignmentRecordIds) === 0) {
            $stats['assignment_channel'] = 'none';
        } elseif ($stats['assignment_snapshot_rows'] === count($assignmentRecordIds)) {
            $stats['assignment_channel'] = 'placement_snapshots';
        } elseif ($stats['assignment_snapshot_rows'] > 0) {
            $stats['assignment_channel'] = 'placement_snapshots+search_start_criteria';
        } else {
            $stats['assignment_channel'] = 'search_start_criteria';
        }

        $stats['assignments_returned'] = count($assignmentRecords);
        $stored = jobdivaMirrorStoreAndIndex($tid, 'jobdiva_assignment', $assignmentRecords,
            ['id', 'startId', 'start_id', 'startID', 'STARTID', 'placementId', 'employeeId']);
        $stats['assignments_processed'] = $stored['processed'];
    }

    $projection = jobdivaReprojectStoredAssignmentGraphs(
        $tid,
        $userId,
        (int) ($opts['projection_limit'] ?? 1000)
    );
    $stats['projection'] = $projection;
    $stats['placements_projected'] = (int) ($projection['placements_projected'] ?? 0);

    $processedTotal =
        (int) $stats['jobs_processed']
        + (int) $stats['candidates_processed']
        + (int) $stats['customers_processed']
        + (int) $stats['assignments_processed'];
    $errors = [];
    if ($stats['unique_start_ids'] > 0 && $stats['assignments_returned'] === 0) {
        foreach ($stats['assignment_search_start_errors'] as $err) $errors[] = $err;
    }
    $failedTotal = count($errors);

    $stats['processed'] = $processedTotal;
    $stats['skipped']   = 0;
    $stats['failed']    = $failedTotal;
    $stats['errors']    = $errors;

    jobdivaAudit($tid, 'sync_mirror_by_placements', [
        'entity_type'     => 'jobdiva_mirror_by_placements',
        'direction'       => 'pull',
        'ok'              => $failedTotal === 0,
        'items_processed' => $processedTotal,
        'items_skipped'   => 0,
        'items_failed'    => $failedTotal,
        'detail'          => $stats,
        'actor_user_id'   => $userId,
    ]);

    return $stats;
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
        $contactOpts = $opts['contacts'] ?? $opts;
        if (!array_key_exists('backfill_companies_on_contact_pull', $contactOpts)) {
            $contactOpts['backfill_companies_on_contact_pull'] = true;
        }
        $contacts   = $safeRun('contact',   static fn() => jobdivaSyncContacts($tid, $userId, $contactOpts));
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

    // Placement-referenced mirror: use the IDs we just pulled from JobDiva
    // placements to fetch full Job/Candidate/Contact/Assignment payloads.
    // This is the reliable graph-alignment path for tenants whose
    // NewUpdatedJobRecords/NewUpdatedCandidateRecords delta endpoints
    // return empty even though placements reference those records.
    if ($shouldPull($config, 'placement') && empty($opts['skip_mirror_by_placements'])) {
        $placementMirror = $safeRun(
            'jobdiva_mirror_by_placements',
            static fn() => jobdivaSyncMirrorByPlacements($tid, $userId, $opts['mirror_by_placements'] ?? $opts)
        );
    } else {
        $placementMirror = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'skipped_by_config' => true];
        $skipped[] = 'jobdiva_mirror_by_placements';
    }

    // 2026-05 — Mirror sync of JobDiva Jobs + Candidates so the Field
    // Mapping Studio sees every Job and Candidate field directly,
    // without depending on the per-record /searchJob and /searchCandidate
    // endpoints (which return empty for many tenants' API scope).
    // Operator ask: "GET EVERY SINGLE BIT OF DATA FROM JOBDIVA. MIRROR
    // JOB DIVA INTO TENANT DATABASE IF THAT'S WHAT YOU NEED TO DO."
    //
    // Mirror sync runs whenever placements OR companies are configured
    // to pull (since job/candidate are dependencies of placement
    // mapping) AND respects an explicit `jobdiva_job`/`jobdiva_candidate`
    // config entry if the operator wants finer control.
    $shouldMirror = static function (array $cfg, string $entity) use ($shouldPull, $config): bool {
        // explicit config row for jobdiva_job / jobdiva_candidate wins…
        if (isset($cfg[$entity])) return $shouldPull($cfg, $entity);
        // …otherwise default to ON when placements sync is on (jobs
        // and candidates are the natural source-of-truth dependencies).
        return $shouldPull($config, 'placement');
    };
    if ($shouldMirror($config, 'jobdiva_job')) {
        $jobs = $safeRun('jobdiva_job', static fn() => jobdivaSyncJobs($tid, $userId, $opts['jobs'] ?? $opts));
    } else {
        $jobs = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'skipped_by_config' => true];
        $skipped[] = 'jobdiva_job';
    }
    if ($shouldMirror($config, 'jobdiva_candidate')) {
        $candidates = $safeRun('jobdiva_candidate', static fn() => jobdivaSyncCandidates($tid, $userId, $opts['candidates'] ?? $opts));
    } else {
        $candidates = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'skipped_by_config' => true];
        $skipped[] = 'jobdiva_candidate';
    }

    // Final canonical replay: placements may have been projected before the
    // mirror-by-placement and job/candidate mirror passes finished collecting
    // related evidence. Re-run projection once after all reachable JobDiva
    // mirrors are stored so placements, rates, clients, vendor/corp details,
    // and mapping overrides all read from the same enriched payload.
    if ($shouldPull($config, 'placement') && empty($opts['skip_final_projection'])) {
        $finalProjection = $safeRun(
            'jobdiva_final_projection',
            static fn() => jobdivaReprojectStoredAssignmentGraphs(
                $tid,
                $userId,
                (int) ($opts['final_projection_limit'] ?? $opts['projection_limit'] ?? 5000)
            )
        );
    } else {
        $finalProjection = ['placements_seen' => 0, 'placements_projected' => 0, 'errors' => [], 'skipped_by_config' => true];
        $skipped[] = 'jobdiva_final_projection';
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
        'company'           => $companies['processed'],
        'contact'           => $contacts['processed'],
        'placement'         => $placements['processed'],
        'jobdiva_mirror_by_placements' => $placementMirror['processed'] ?? 0,
        'jobdiva_job'       => $jobs['processed'],
        'jobdiva_candidate' => $candidates['processed'],
        'jobdiva_final_projection' => $finalProjection['placements_projected'] ?? 0,
        'time'              => $timeResult['processed'],
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
            'company'           => $companies,
            'contact'           => $contacts,
            'placement'         => $placements,
            'jobdiva_mirror_by_placements' => $placementMirror,
            'jobdiva_job'       => $jobs,
            'jobdiva_candidate' => $candidates,
            'jobdiva_final_projection' => $finalProjection,
            'time'              => $timeResult,
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
     || str_starts_with($path, '/apiv2/bi/NewUpdatedContactRecords')
     || str_starts_with($path, '/apiv2/bi/NewUpdatedJobRecords')
     || str_starts_with($path, '/apiv2/bi/NewUpdatedCandidateRecords')) {
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
