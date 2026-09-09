<?php
/** CoreFlux-owned routing for Connecteam work categories and time. */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../integrations/entity_mappings.php';

const CONNECTEAM_ROUTE_TIME_CATEGORIES = [
    'regular_billable', 'regular_nonbillable', 'OT_billable', 'OT_nonbillable',
    'holiday', 'vacation', 'sick', 'bereavement', 'unpaid_leave', 'custom',
];

const CONNECTEAM_OVERHEAD_TIME_CATEGORIES = [
    'regular_nonbillable', 'holiday', 'vacation', 'sick', 'bereavement',
    'unpaid_leave', 'custom',
];

function connecteamWorkDate(?string $value, string $field): string
{
    $value = trim((string) $value);
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new \InvalidArgumentException("{$field} must be YYYY-MM-DD");
    }
    return $value;
}

function connecteamWorkSourceId(array $row): string
{
    return trim((string) ($row['jobId'] ?? $row['id'] ?? ''));
}

function connecteamWorkSourceName(array $row): string
{
    return trim((string) ($row['title'] ?? $row['name'] ?? $row['jobName'] ?? connecteamWorkSourceId($row)));
}

/** @return array<string,array<string,mixed>> */
function connecteamWorkJobsById(array $jobs): array
{
    $result = [];
    foreach (connecteamFlattenJobs($jobs) as $job) {
        if (!is_array($job)) continue;
        $id = connecteamWorkSourceId($job);
        if ($id === '') continue;
        $result[$id] = [
            'id' => $id,
            'name' => connecteamWorkSourceName($job),
            'code' => trim((string) ($job['code'] ?? '')),
        ];
    }
    return $result;
}

/** Convert Connecteam timesheet records into deterministic source rows. */
function connecteamNormalizeTimesheetRows(
    string $timeClockId,
    array $body,
    array $usersById = [],
    array $jobsById = []
): array {
    $data = connecteamPayloadData($body);
    $rows = [];
    foreach (($data['users'] ?? []) as $user) {
        if (!is_array($user)) continue;
        $userId = trim((string) ($user['userId'] ?? $user['id'] ?? ''));
        if ($userId === '') continue;
        foreach (($user['dailyRecords'] ?? []) as $day) {
            if (!is_array($day)) continue;
            $date = trim((string) ($day['date'] ?? ''));
            if ($date === '') continue;
            $records = array_values(array_filter($day['records'] ?? [], 'is_array'));
            $durations = [];
            $durationTotal = 0.0;
            foreach ($records as $idx => $record) {
                $start = (int) ($record['start']['timestamp'] ?? 0);
                $end = (int) ($record['end']['timestamp'] ?? 0);
                $duration = ($start > 0 && $end > $start) ? (($end - $start) / 3600) : 0.0;
                $durations[$idx] = $duration;
                $durationTotal += $duration;
            }
            $dayHours = max(0.0, (float) ($day['dailyTotalWorkHours'] ?? $day['dailyTotalHours'] ?? $durationTotal));
            foreach ($records as $idx => $record) {
                $resource = is_array($record['resources'][0] ?? null) ? $record['resources'][0] : [];
                $jobId = trim((string) ($resource['resourceId'] ?? $record['jobId'] ?? ''));
                $subJobId = trim((string) ($resource['subResourceId'] ?? $record['subJobId'] ?? ''));
                $activityId = trim((string) ($record['timeActivityId'] ?? $record['id'] ?? ''));
                if ($activityId === '') {
                    $activityId = hash('sha256', implode('|', [
                        $timeClockId, $userId, $date, $jobId, $subJobId,
                        (string) ($record['start']['timestamp'] ?? ''),
                        (string) ($record['end']['timestamp'] ?? ''),
                    ]));
                }
                $hours = $durationTotal > 0
                    ? $dayHours * (($durations[$idx] ?? 0.0) / $durationTotal)
                    : ($records ? $dayHours / count($records) : 0.0);
                $rows[] = [
                    'time_clock_id' => $timeClockId,
                    'source_activity_id' => $activityId,
                    'source_user_id' => $userId,
                    'source_user_name' => (string) ($usersById[$userId]['name'] ?? $userId),
                    'source_job_id' => $jobId,
                    'source_sub_job_id' => $subJobId,
                    'source_job_name' => (string) ($jobsById[$jobId]['name'] ?? ($jobId !== '' ? $jobId : 'No Connecteam job')),
                    'work_date' => $date,
                    'hours' => round($hours, 4),
                    'source_approved' => !empty($day['isApproved']),
                    'source_submitted' => !empty($day['isSubmitted']),
                    'payload' => ['day' => $day, 'record' => $record],
                ];
            }
        }
    }
    return $rows;
}

/** Pure route resolver; the first item is the most specific candidate. */
function connecteamResolveWorkRoute(array $row, array $routes): array
{
    $matches = [];
    foreach ($routes as $route) {
        if (empty($route['active'])) continue;
        if ((string) ($route['source_job_id'] ?? '') !== (string) ($row['source_job_id'] ?? '')) continue;
        $routeSubJob = (string) ($route['source_sub_job_id'] ?? '');
        if ($routeSubJob !== '' && $routeSubJob !== (string) ($row['source_sub_job_id'] ?? '')) continue;
        $routeUser = (string) ($route['source_user_id'] ?? '');
        if ($routeUser !== '' && $routeUser !== (string) ($row['source_user_id'] ?? '')) continue;
        $date = (string) ($row['work_date'] ?? '');
        if ($date < (string) ($route['effective_from'] ?? '0000-00-00')) continue;
        if (!empty($route['effective_to']) && $date > (string) $route['effective_to']) continue;
        $specificity = ($routeUser !== '' ? 2 : 0) + ($routeSubJob !== '' ? 1 : 0);
        $route['_specificity'] = $specificity;
        $matches[] = $route;
    }
    usort($matches, static function (array $a, array $b): int {
        return [(int) ($b['_specificity'] ?? 0), (int) ($b['priority'] ?? 0), (string) ($b['effective_from'] ?? '')]
            <=> [(int) ($a['_specificity'] ?? 0), (int) ($a['priority'] ?? 0), (string) ($a['effective_from'] ?? '')];
    });
    if (!$matches) return ['status' => 'unmapped_work', 'route' => null];
    $top = $matches[0];
    $topKey = implode('|', [(int) $top['_specificity'], (int) ($top['priority'] ?? 0), (string) $top['effective_from']]);
    $ties = array_filter($matches, static fn(array $candidate): bool => implode('|', [
        (int) ($candidate['_specificity'] ?? 0),
        (int) ($candidate['priority'] ?? 0),
        (string) ($candidate['effective_from'] ?? ''),
    ]) === $topKey);
    if (count($ties) > 1) return ['status' => 'ambiguous_route', 'route' => null];
    unset($top['_specificity']);
    return ['status' => 'ready', 'route' => $top];
}

function connecteamWorkRouteRows(int $tenantId): array
{
    $stmt = getDB()->prepare(
        'SELECT r.*, o.code AS overhead_code, o.name AS overhead_name
           FROM connecteam_work_routes r
      LEFT JOIN connecteam_overhead_categories o
             ON o.tenant_id = r.tenant_id AND o.id = r.overhead_category_id
          WHERE r.tenant_id = :t AND r.active = 1
       ORDER BY r.source_job_name, r.source_user_name, r.effective_from DESC, r.id DESC'
    );
    $stmt->execute(['t' => $tenantId]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function connecteamOverheadRows(int $tenantId): array
{
    $stmt = getDB()->prepare(
        'SELECT id, code, name, department, accounting_account_id, time_category, active
           FROM connecteam_overhead_categories
          WHERE tenant_id = :t AND active = 1
       ORDER BY name, id'
    );
    $stmt->execute(['t' => $tenantId]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function connecteamLinkedPeople(int $peopleTenantId): array
{
    $stmt = getDB()->prepare(
        'SELECT m.external_id AS source_user_id, m.internal_entity_id AS person_id,
                p.first_name, p.last_name, p.email_primary
           FROM external_entity_mappings m
           JOIN people p ON p.tenant_id = m.tenant_id
                        AND p.id = m.internal_entity_id
                        AND p.deleted_at IS NULL
          WHERE m.tenant_id = :t
            AND m.source_system = "connecteam"
            AND m.internal_entity_type = "person"
            AND m.sync_status <> "deleted_in_source"
       ORDER BY p.last_name, p.first_name, p.id'
    );
    $stmt->execute(['t' => $peopleTenantId]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function connecteamRoutingPlacements(int $placementsTenantId, int $peopleTenantId): array
{
    $stmt = getDB()->prepare(
        'SELECT p.id, p.person_id, p.title, p.end_client_name, p.status, p.start_date, p.end_date,
                pe.first_name, pe.last_name
           FROM placements p
      LEFT JOIN people pe ON pe.tenant_id = :people_t AND pe.id = p.person_id AND pe.deleted_at IS NULL
          WHERE p.tenant_id = :placements_t
            AND p.deleted_at IS NULL
            AND p.status IN ("draft","pending_start","active","on_hold")
       ORDER BY pe.last_name, pe.first_name, p.start_date DESC, p.id DESC
          LIMIT 1000'
    );
    $stmt->execute(['people_t' => $peopleTenantId, 'placements_t' => $placementsTenantId]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

function connecteamRoutingSourceCatalog(int $tenantId, int $peopleTenantId): array
{
    $usersFetch = connecteamFetchCollection($tenantId, '/users/v1/users', ['userStatus' => 'all'], ['users'], 500);
    $jobsFetch = connecteamFetchCollection($tenantId, '/jobs/v1/jobs', ['includeDeleted' => 'false'], ['jobs'], 500);
    $links = [];
    foreach (connecteamLinkedPeople($peopleTenantId) as $link) {
        $links[(string) $link['source_user_id']] = $link;
    }
    $users = [];
    foreach ($usersFetch['items'] as $user) {
        if (!is_array($user)) continue;
        $id = connecteamUserId($user);
        if ($id === '') continue;
        $link = $links[$id] ?? null;
        $users[] = [
            'id' => $id,
            'name' => connecteamUserName($user),
            'email' => connecteamUserEmail($user),
            'person_id' => $link ? (int) $link['person_id'] : null,
            'person_name' => $link ? trim((string) $link['first_name'] . ' ' . (string) $link['last_name']) : null,
        ];
    }
    return [
        'users' => $users,
        'jobs' => array_values(connecteamWorkJobsById($jobsFetch['items'])),
    ];
}

function connecteamWorkRoutingSnapshot(
    int $tenantId,
    int $peopleTenantId,
    int $placementsTenantId
): array {
    $source = connecteamRoutingSourceCatalog($tenantId, $peopleTenantId);
    return [
        'source' => $source,
        'routes' => connecteamWorkRouteRows($tenantId),
        'overheads' => connecteamOverheadRows($tenantId),
        'placements' => connecteamRoutingPlacements($placementsTenantId, $peopleTenantId),
    ];
}

function connecteamTimePreview(
    int $tenantId,
    int $peopleTenantId,
    string $startDate,
    string $endDate
): array {
    $startDate = connecteamWorkDate($startDate, 'start_date');
    $endDate = connecteamWorkDate($endDate, 'end_date');
    if ($endDate < $startDate) throw new \InvalidArgumentException('end_date must be on or after start_date');
    if ((new \DateTimeImmutable($startDate))->diff(new \DateTimeImmutable($endDate))->days > 44) {
        throw new \InvalidArgumentException('Connecteam timesheet previews are limited to 45 days');
    }

    $source = connecteamRoutingSourceCatalog($tenantId, $peopleTenantId);
    $usersById = [];
    foreach ($source['users'] as $user) $usersById[(string) $user['id']] = $user;
    $jobsById = [];
    foreach ($source['jobs'] as $job) $jobsById[(string) $job['id']] = $job;
    $clockBody = connecteamCall($tenantId, 'GET', '/time-clock/v1/time-clocks');
    $clocks = connecteamCollection($clockBody, ['timeClocks']);
    $rows = [];
    foreach ($clocks as $clock) {
        if (!is_array($clock) || !empty($clock['isArchived'])) continue;
        $clockId = trim((string) ($clock['id'] ?? $clock['timeClockId'] ?? ''));
        if ($clockId === '') continue;
        $body = connecteamCall($tenantId, 'GET', '/time-clock/v1/time-clocks/' . rawurlencode($clockId)
            . '/timesheet?' . http_build_query(['startDate' => $startDate, 'endDate' => $endDate]));
        foreach (connecteamNormalizeTimesheetRows($clockId, $body, $usersById, $jobsById) as $row) $rows[] = $row;
    }

    $routes = connecteamWorkRouteRows($tenantId);
    $counts = ['ready' => 0, 'unlinked_person' => 0, 'unmapped_work' => 0, 'ambiguous_route' => 0, 'invalid_route' => 0, 'unapproved' => 0];
    foreach ($rows as &$row) {
        $user = $usersById[(string) $row['source_user_id']] ?? null;
        $row['person_id'] = !empty($user['person_id']) ? (int) $user['person_id'] : null;
        $row['person_name'] = $user['person_name'] ?? null;
        if (!$row['person_id']) {
            $row['routing_status'] = 'unlinked_person';
            $row['route'] = null;
        } elseif (!$row['source_approved']) {
            $row['routing_status'] = 'unapproved';
            $row['route'] = null;
        } else {
            $resolution = connecteamResolveWorkRoute($row, $routes);
            $row['routing_status'] = $resolution['status'];
            $row['route'] = $resolution['route'];
            if ($row['routing_status'] === 'ready') {
                $routePersonId = (int) ($row['route']['person_id'] ?? 0);
                if ($routePersonId > 0 && $routePersonId !== (int) $row['person_id']) {
                    $row['routing_status'] = 'invalid_route';
                }
            }
        }
        $counts[$row['routing_status']] = ($counts[$row['routing_status']] ?? 0) + 1;
        unset($row['payload']);
    }
    unset($row);
    return [
        'read_only' => true,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'time_clocks' => count($clocks),
        'source_count' => count($rows),
        'counts' => $counts,
        'rows' => array_slice($rows, 0, 500),
        'rules' => [
            'Only Connecteam-approved days can become ready.',
            'Every ready row requires a linked P-ID and one effective CoreFlux route.',
            'No placement, time entry, payroll, invoice, bill, or journal row is created by this preview.',
        ],
    ];
}
