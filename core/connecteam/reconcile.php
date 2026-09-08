<?php
/** Connecteam read-only People and Placement reconciliation preview. */
declare(strict_types=1);

require_once __DIR__ . '/client.php';

function connecteamNormalizeEmail(?string $value): string
{
    return strtolower(trim((string) $value));
}

function connecteamNormalizePhone(?string $value): string
{
    return preg_replace('/\D+/', '', (string) $value) ?: '';
}

function connecteamUserId(array $row): string
{
    return (string) ($row['userId'] ?? $row['id'] ?? '');
}

function connecteamUserName(array $row): string
{
    return trim((string) ($row['fullName'] ?? (($row['firstName'] ?? '') . ' ' . ($row['lastName'] ?? ''))));
}

function connecteamUserEmail(array $row): string
{
    return connecteamNormalizeEmail((string) ($row['email'] ?? $row['emailAddress'] ?? ''));
}

function connecteamUserPersonId(array $row): ?int
{
    $value = connecteamCustomFieldValue($row, ['CoreFlux Person ID', 'CoreFlux person', 'person_id']);
    if ($value === null) return null;
    if (preg_match('/^(?:CF[-_ ]?)?P[-_ ]?(\d+)$/i', trim($value), $match)) return (int) $match[1];
    return ctype_digit($value) ? (int) $value : null;
}

function connecteamCustomFieldValue(array $row, array $wantedNames): ?string
{
    $wanted = array_map(static fn(string $name): string => strtolower($name), $wantedNames);
    foreach (($row['customFields'] ?? []) as $field) {
        if (!is_array($field)) continue;
        $name = strtolower(trim((string) ($field['name'] ?? $field['title'] ?? '')));
        if (!in_array($name, $wanted, true)) continue;
        $value = $field['value'] ?? $field['text'] ?? null;
        if (is_scalar($value) && trim((string) $value) !== '') return trim((string) $value);
    }
    return null;
}

function connecteamBuildPeoplePreview(array $sourceUsers, array $people, array $storedLinks = []): array
{
    $byId = [];
    $byExternal = [];
    $byEmail = [];
    $byPhone = [];
    foreach ($people as $person) {
        $byId[(int) $person['id']] = $person;
        $external = strtolower(trim((string) ($person['external_id'] ?? '')));
        if (str_starts_with($external, 'connecteam:')) $byExternal[$external][] = $person;
        $email = connecteamNormalizeEmail((string) ($person['email_primary'] ?? ''));
        if ($email !== '') $byEmail[$email][] = $person;
        $phone = connecteamNormalizePhone((string) ($person['phone_primary'] ?? ''));
        if ($phone !== '') $byPhone[$phone][] = $person;
    }

    $counts = ['exact' => 0, 'suggested' => 0, 'ambiguous' => 0, 'unmatched' => 0];
    $rows = [];
    foreach ($sourceUsers as $source) {
        if (!is_array($source)) continue;
        $sourceId = connecteamUserId($source);
        $email = connecteamUserEmail($source);
        $phone = connecteamNormalizePhone((string) ($source['phoneNumber'] ?? $source['phone'] ?? ''));
        $matches = [];
        $state = 'unmatched';
        $method = 'No stable CoreFlux identity found';

        $linkedId = isset($storedLinks[$sourceId]) ? (int) $storedLinks[$sourceId] : 0;
        $customPersonId = connecteamUserPersonId($source);
        if ($linkedId > 0 && isset($byId[$linkedId])) {
            $matches = [$byId[$linkedId]];
            $state = 'exact';
            $method = 'Stored Connecteam identity link';
        } elseif ($customPersonId && isset($byId[$customPersonId])) {
            $matches = [$byId[$customPersonId]];
            $state = 'exact';
            $method = 'Connecteam custom field contains person ID';
        } elseif ($sourceId !== '' && count($byExternal['connecteam:' . strtolower($sourceId)] ?? []) === 1) {
            $matches = $byExternal['connecteam:' . strtolower($sourceId)];
            $state = 'exact';
            $method = 'Stored Connecteam user ID';
        } else {
            $candidates = [];
            foreach ($byEmail[$email] ?? [] as $person) $candidates[(string) $person['id']] = $person;
            foreach ($byPhone[$phone] ?? [] as $person) $candidates[(string) $person['id']] = $person;
            $matches = array_values($candidates);
            if (count($matches) === 1) {
                $state = 'suggested';
                $method = $email !== '' && isset($byEmail[$email]) ? 'Unique email' : 'Unique phone';
            } elseif (count($matches) > 1) {
                $state = 'ambiguous';
                $method = 'Email/phone point to different CoreFlux people';
            }
        }
        $counts[$state]++;
        if ($state !== 'exact' || count($rows) < 10) {
            $rows[] = [
                'source_id' => $sourceId,
                'source_name' => connecteamUserName($source),
                'source_email' => $email,
                'state' => $state,
                'method' => $method,
                'coreflux_id' => count($matches) === 1 ? (int) $matches[0]['id'] : null,
                'coreflux_name' => count($matches) === 1
                    ? trim((string) (($matches[0]['first_name'] ?? '') . ' ' . ($matches[0]['last_name'] ?? '')))
                    : null,
            ];
        }
    }
    return [
        'source_count' => count($sourceUsers),
        'coreflux_count' => count($people),
        'counts' => $counts,
        'rows' => array_slice($rows, 0, 100),
    ];
}

function connecteamJobPlacementId(array $job): ?int
{
    $custom = connecteamCustomFieldValue($job, ['CoreFlux Placement ID', 'CoreFlux placement', 'placement_id']);
    $value = $custom ?: (string) ($job['code'] ?? '');
    if (preg_match('/^(?:CF[-_ ]?)?PL[-_ ]?(\d+)$/i', trim($value), $m)) return (int) $m[1];
    if ($custom !== null && ctype_digit($custom)) return (int) $custom;
    return null;
}

function connecteamBuildJobsPreview(array $sourceJobs, array $placements, array $storedLinks = []): array
{
    $byId = [];
    $byExternal = [];
    $byTitle = [];
    foreach ($placements as $placement) {
        $byId[(int) $placement['id']] = $placement;
        $external = strtolower(trim((string) ($placement['external_id'] ?? '')));
        if ($external !== '') $byExternal[$external][] = $placement;
        $title = strtolower(trim((string) ($placement['title'] ?? '')));
        if ($title !== '') $byTitle[$title][] = $placement;
    }

    $counts = ['exact' => 0, 'suggested' => 0, 'ambiguous' => 0, 'unmatched' => 0];
    $rows = [];
    foreach ($sourceJobs as $source) {
        if (!is_array($source)) continue;
        $matches = [];
        $state = 'unmatched';
        $method = 'No explicit CoreFlux placement ID';
        $sourceId = (string) ($source['jobId'] ?? $source['id'] ?? '');
        $linkedId = isset($storedLinks[$sourceId]) ? (int) $storedLinks[$sourceId] : 0;
        $placementId = connecteamJobPlacementId($source);
        $code = strtolower(trim((string) ($source['code'] ?? '')));
        $title = strtolower(trim((string) ($source['title'] ?? $source['name'] ?? '')));
        if ($linkedId > 0 && isset($byId[$linkedId])) {
            $matches = [$byId[$linkedId]];
            $state = 'exact';
            $method = 'Stored Connecteam placement link';
        } elseif ($placementId && isset($byId[$placementId])) {
            $matches = [$byId[$placementId]];
            $state = 'exact';
            $method = 'Connecteam job code/custom field contains placement ID';
        } elseif ($code !== '' && count($byExternal[$code] ?? []) === 1) {
            $matches = $byExternal[$code];
            $state = 'exact';
            $method = 'Job code equals CoreFlux placement external ID';
        } elseif (count($byTitle[$title] ?? []) === 1) {
            $matches = $byTitle[$title];
            $state = 'suggested';
            $method = 'Unique title only; review before linking';
        } elseif (count($byTitle[$title] ?? []) > 1) {
            $matches = $byTitle[$title];
            $state = 'ambiguous';
            $method = 'Title matches multiple placements';
        }
        $counts[$state]++;
        if ($state !== 'exact' || count($rows) < 10) {
            $rows[] = [
                'source_id' => $sourceId,
                'source_name' => (string) ($source['title'] ?? $source['name'] ?? ''),
                'source_code' => (string) ($source['code'] ?? ''),
                'state' => $state,
                'method' => $method,
                'coreflux_id' => count($matches) === 1 ? (int) $matches[0]['id'] : null,
                'coreflux_name' => count($matches) === 1 ? (string) $matches[0]['title'] : null,
            ];
        }
    }
    return [
        'source_count' => count($sourceJobs),
        'coreflux_count' => count($placements),
        'counts' => $counts,
        'rows' => array_slice($rows, 0, 100),
    ];
}

function connecteamFlattenJobs(array $jobs): array
{
    $flat = [];
    foreach ($jobs as $job) {
        if (!is_array($job)) continue;
        $flat[] = $job;
        if (is_array($job['subJobs'] ?? null)) {
            foreach (connecteamFlattenJobs($job['subJobs']) as $subJob) $flat[] = $subJob;
        }
    }
    return $flat;
}

/**
 * Fetch a complete collection within a hard page ceiling. The result reports
 * truncation rather than presenting a partial count as complete.
 */
function connecteamFetchCollection(
    int $tenantId,
    string $path,
    array $query,
    array $collectionKeys,
    int $pageSize,
    int $maxPages = 20
): array {
    $items = [];
    $offset = 0;
    $pages = 0;
    $truncated = false;
    while ($pages < $maxPages) {
        $requestQuery = array_merge($query, ['limit' => $pageSize, 'offset' => $offset]);
        $body = connecteamCall($tenantId, 'GET', $path . '?' . http_build_query($requestQuery));
        $batch = connecteamCollection($body, $collectionKeys);
        foreach ($batch as $item) if (is_array($item)) $items[] = $item;
        $pages++;
        $paging = is_array($body['paging'] ?? null)
            ? $body['paging']
            : (is_array(($body['data']['paging'] ?? null)) ? $body['data']['paging'] : []);
        $next = isset($paging['offset']) && is_numeric($paging['offset']) ? (int) $paging['offset'] : null;
        if ($next === null || $next <= $offset || count($batch) === 0) break;
        $offset = $next;
    }
    if ($pages >= $maxPages && isset($next) && $next !== null) $truncated = true;
    return ['items' => $items, 'pages' => $pages, 'truncated' => $truncated];
}

function connecteamStoredLinks(int $tenantId, string $entityType): array
{
    $stmt = getDB()->prepare(
        'SELECT external_id, internal_entity_id
           FROM external_entity_mappings
          WHERE tenant_id = :t
            AND source_system = "connecteam"
            AND internal_entity_type = :entity_type
            AND sync_status <> "deleted_in_source"'
    );
    $stmt->execute(['t' => $tenantId, 'entity_type' => $entityType]);
    $links = [];
    foreach (($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $row) {
        $links[(string) $row['external_id']] = (int) $row['internal_entity_id'];
    }
    return $links;
}

function connecteamDryRunPreview(int $tenantId, ?int $userId): array
{
    $userFetch = connecteamFetchCollection(
        $tenantId, '/users/v1/users', ['userStatus' => 'all'], ['users'], 500
    );
    $jobFetch = connecteamFetchCollection(
        $tenantId, '/jobs/v1/jobs', ['includeDeleted' => 'false'], ['jobs'], 500
    );
    $users = $userFetch['items'];
    $jobs = connecteamFlattenJobs($jobFetch['items']);

    $pdo = getDB();
    $peopleStmt = $pdo->prepare(
        'SELECT id, external_id, first_name, last_name, email_primary, phone_primary, status
           FROM people
          WHERE tenant_id = :t AND deleted_at IS NULL'
    );
    $peopleStmt->execute(['t' => $tenantId]);
    $people = $peopleStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $placementsStmt = $pdo->prepare(
        'SELECT id, external_id, title, person_id, status, start_date, end_date
           FROM placements
          WHERE tenant_id = :t AND deleted_at IS NULL'
    );
    $placementsStmt->execute(['t' => $tenantId]);
    $placements = $placementsStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $preview = [
        'read_only' => true,
        'generated_at' => gmdate('c'),
        'people' => connecteamBuildPeoplePreview($users, $people, connecteamStoredLinks($tenantId, 'person')),
        'jobs' => connecteamBuildJobsPreview($jobs, $placements, connecteamStoredLinks($tenantId, 'placement')),
        'source_pages' => ['users' => $userFetch['pages'], 'jobs' => $jobFetch['pages']],
        'truncated' => ['users' => $userFetch['truncated'], 'jobs' => $jobFetch['truncated']],
        'rules' => [
            'No CoreFlux records were created or changed.',
            'Email, phone, and title matches are suggestions only.',
            'Only stored Connecteam IDs or explicit placement codes/custom fields count as exact.',
            'A Connecteam job can never create a CoreFlux placement.',
        ],
    ];
    connecteamAudit($tenantId, 'reconciliation_preview', [
        'actor_user_id' => $userId,
        'items_inspected' => count($users) + count($jobs),
        'detail' => [
            'people' => $preview['people']['counts'],
            'jobs' => $preview['jobs']['counts'],
        ],
    ]);
    return $preview;
}
