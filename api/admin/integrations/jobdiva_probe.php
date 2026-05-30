<?php
/**
 * /api/admin/integrations/jobdiva_probe.php
 *
 * Read-only diagnostic that hits a battery of JobDiva V2 BI endpoints
 * with sample params and returns the RAW HTTP response from each:
 * status, response size, item count, latency, and first 800 chars of
 * body. Lets operators see exactly which endpoints their JobDiva auth
 * scope actually grants — without us having to guess.
 *
 * The default battery probes:
 *   1. NewUpdatedCompanyRecords  (known-working baseline)
 *   2. NewUpdatedContactRecords  (known-working baseline)
 *   3. NewUpdatedJobRecords      (may 403/empty for restricted tenants)
 *   4. NewUpdatedCandidateRecords (may 403/empty)
 *   5. OpenJobsList              (no params, simple sanity check)
 *   6. JobsDetail?jobIds=<real>  (uses a real job id from stored placements)
 *   7. CandidatesDetail?…        (real candidate id)
 *   8. CompaniesDetail?…         (real customer id)
 *
 * RBAC: tenant_admin.integrations. Operator clicks "🔎 Diagnose
 * JobDiva" in the Field Mapping Studio.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/jobdiva/client.php';
require_once __DIR__ . '/../../../core/jobdiva/sync.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
if (api_method() !== 'POST') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'tenant_admin.integrations');

$now  = new \DateTimeImmutable('now');
$from = $now->modify('-30 days')->format('m/d/Y H:i:s');
$to   = $now->format('m/d/Y H:i:s');

$probes = [
    [
        'name'  => 'NewUpdatedCompanyRecords (baseline)',
        'path'  => '/apiv2/bi/NewUpdatedCompanyRecords',
        'query' => ['fromDate' => $from, 'toDate' => $to, 'userFieldsName' => ''],
        'note'  => 'Companies sync is known to work. Use as a control.',
    ],
    [
        'name'  => 'NewUpdatedContactRecords (baseline)',
        'path'  => '/apiv2/bi/NewUpdatedContactRecords',
        'query' => ['fromDate' => $from, 'toDate' => $to, 'userFieldsName' => ''],
        'note'  => 'Contacts sync is known to work. Use as a control.',
    ],
    [
        'name'  => 'NewUpdatedJobRecords',
        'path'  => '/apiv2/bi/NewUpdatedJobRecords',
        'query' => ['fromDate' => $from, 'toDate' => $to, 'userFieldsName' => ''],
        'note'  => 'If 0 items here but Companies returns N, the API user lacks Job-scope BI access.',
    ],
    [
        'name'  => 'NewUpdatedCandidateRecords',
        'path'  => '/apiv2/bi/NewUpdatedCandidateRecords',
        'query' => ['fromDate' => $from, 'toDate' => $to, 'userFieldsName' => ''],
        'note'  => 'If 0 items here, the API user lacks Candidate-scope BI access.',
    ],
    [
        'name'  => 'OpenJobsList (no params)',
        'path'  => '/apiv2/bi/OpenJobsList',
        'query' => [],
        'note'  => 'Pure no-param endpoint — lowest-friction test. 200+items proves Jobs access is live.',
    ],
];

// Add by-ID probes using real IDs from existing placement payloads if we
// have any. Demonstrates whether the *Detail endpoints are reachable.
$pdo = getDB();
if ($pdo) {
    try {
        $st = $pdo->prepare(
            "SELECT payload_snapshot
               FROM external_entity_mappings
              WHERE tenant_id = :t AND source_system = 'jobdiva'
                AND internal_entity_type = 'placement'
                AND payload_snapshot IS NOT NULL
              LIMIT 1"
        );
        $st->execute(['t' => $tid]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $payload = json_decode((string) $row['payload_snapshot'], true);
            if (is_array($payload)) {
                $jId = jobdivaPluckField($payload, ['job id', 'jobId', 'job_id', 'jobID', 'JOBID']);
                $cId = jobdivaPluckField($payload, ['candidate id', 'candidateId', 'candidate_id', 'candidateID', 'CANDIDATEID', 'employeeId']);
                $uId = jobdivaPluckField($payload, ['customer id', 'customerId', 'customer_id', 'customerID', 'CUSTOMERID']);
                if ($jId !== null && $jId !== '') {
                    $probes[] = [
                        'name'  => "JobsDetail?jobIds=$jId",
                        'path'  => '/apiv2/bi/JobsDetail',
                        'query' => ['jobIds' => (string) $jId, 'userFieldsName' => ''],
                        'note'  => 'Uses a real placement\'s job_id. If 200+items, Mirror-by-Placements will work for Jobs.',
                    ];
                }
                if ($cId !== null && $cId !== '') {
                    $probes[] = [
                        'name'  => "CandidatesDetail?candidateIds=$cId",
                        'path'  => '/apiv2/bi/CandidatesDetail',
                        'query' => ['candidateIds' => (string) $cId, 'userFieldsName' => ''],
                        'note'  => 'Uses a real placement\'s candidate_id. If 200+items, Mirror-by-Placements will work for Candidates.',
                    ];
                }
                if ($uId !== null && $uId !== '') {
                    $probes[] = [
                        'name'  => "CompaniesDetail?companyIds=$uId",
                        'path'  => '/apiv2/bi/CompaniesDetail',
                        'query' => ['companyIds' => (string) $uId, 'userFieldsName' => ''],
                        'note'  => 'Uses a real placement\'s customer_id. If 200+items, Mirror-by-Placements will work for Companies.',
                    ];
                }
            }
        }
    } catch (\Throwable $e) {
        // Soft-fail — base probes still run.
        error_log('[jobdiva_probe] failed to read sample placement: ' . $e->getMessage());
    }
}

// Run each probe and capture the raw HTTP response.
$results = [];
$tokenErr = null;
try {
    $token = jobdivaSessionToken($tid);
} catch (\Throwable $e) {
    $tokenErr = 'Failed to obtain JobDiva session token: ' . $e->getMessage();
    $token = null;
}

foreach ($probes as $ep) {
    $startMs = microtime(true);
    $entry = [
        'name'  => $ep['name'],
        'path'  => $ep['path'],
        'query' => $ep['query'] ?? [],
        'note'  => $ep['note'] ?? '',
    ];
    if ($tokenErr !== null) {
        $entry['error'] = $tokenErr;
        $entry['latency_ms'] = 0;
        $results[] = $entry;
        continue;
    }
    try {
        $resp = jobdivaRawRequest('GET', $ep['path'], null, $ep['query'] ?? [], true, $token);
        $body = $resp['body'] ?? null;
        $isJsonArr = is_array($body);
        $itemCount = 0;
        if ($isJsonArr) {
            if (isset($body['data']) && is_array($body['data'])) {
                $itemCount = count($body['data']);
            } elseif (isset($body['items']) && is_array($body['items'])) {
                $itemCount = count($body['items']);
            } elseif (!empty($body) && array_keys($body) === range(0, count($body) - 1)) {
                $itemCount = count($body);
            }
        }
        $bodyStr  = $isJsonArr ? (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $body;
        $entry['status']        = (int) ($resp['status'] ?? 0);
        $entry['item_count']    = $itemCount;
        $entry['body_size']     = strlen($bodyStr);
        $entry['body_preview']  = substr($bodyStr, 0, 800);
        $entry['li_uuid']       = (string) ($resp['headers']['x-li-uuid'] ?? '');
    } catch (\Throwable $e) {
        $entry['error'] = substr($e->getMessage(), 0, 1200);
    }
    $entry['latency_ms'] = (int) round((microtime(true) - $startMs) * 1000);
    $results[] = $entry;
}

api_ok([
    'tenant_id' => $tid,
    'from_date' => $from,
    'to_date'   => $to,
    'probes'    => $results,
]);
