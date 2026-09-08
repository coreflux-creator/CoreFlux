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

function connecteamNormalizeName(?string $value): string
{
    $value = strtolower(trim((string) $value));
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
}

function connecteamFirstScalar(array $row, array $paths): string
{
    foreach ($paths as $path) {
        $value = $row;
        foreach (explode('.', (string) $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                $value = null;
                break;
            }
            $value = $value[$part];
        }
        if (is_scalar($value) && trim((string) $value) !== '') return trim((string) $value);
    }
    return '';
}

function connecteamUserId(array $row): string
{
    return (string) ($row['userId'] ?? $row['id'] ?? '');
}

function connecteamUserName(array $row): string
{
    $full = connecteamFirstScalar($row, ['fullName', 'displayName', 'userName', 'contactDetails.fullName']);
    return $full !== '' ? $full : trim(connecteamUserFirstName($row) . ' ' . connecteamUserLastName($row));
}

function connecteamUserFirstName(array $row): string
{
    return connecteamFirstScalar($row, ['firstName', 'contactDetails.firstName', 'userDetails.firstName']);
}

function connecteamUserLastName(array $row): string
{
    return connecteamFirstScalar($row, ['lastName', 'contactDetails.lastName', 'userDetails.lastName']);
}

function connecteamUserEmail(array $row): string
{
    return connecteamNormalizeEmail(connecteamFirstScalar($row, [
        'email', 'emailAddress', 'contactDetails.email', 'contact.email', 'userDetails.email',
    ]));
}

function connecteamUserPhone(array $row): string
{
    return connecteamNormalizePhone(connecteamFirstScalar($row, [
        'phoneNumber', 'phone', 'mobilePhone', 'contactDetails.phoneNumber',
        'contactDetails.phone', 'contact.phone', 'userDetails.phoneNumber',
    ]));
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
    $byName = [];
    foreach ($people as $person) {
        $byId[(int) $person['id']] = $person;
        $external = strtolower(trim((string) ($person['external_id'] ?? '')));
        if (str_starts_with($external, 'connecteam:')) $byExternal[$external][] = $person;
        foreach (['email_primary', 'email_secondary'] as $field) {
            $email = connecteamNormalizeEmail((string) ($person[$field] ?? ''));
            if ($email !== '') $byEmail[$email][] = $person;
        }
        foreach (['phone_primary', 'phone_secondary'] as $field) {
            $phone = connecteamNormalizePhone((string) ($person[$field] ?? ''));
            if ($phone !== '') $byPhone[$phone][] = $person;
        }
        $name = connecteamNormalizeName(trim((string) (($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''))));
        if ($name !== '') $byName[$name][] = $person;
    }

    $counts = ['exact' => 0, 'suggested' => 0, 'ambiguous' => 0, 'unmatched' => 0];
    $rows = [];
    foreach ($sourceUsers as $source) {
        if (!is_array($source)) continue;
        $sourceId = connecteamUserId($source);
        $email = connecteamUserEmail($source);
        $phone = connecteamUserPhone($source);
        $name = connecteamNormalizeName(connecteamUserName($source));
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
            if (!$candidates) {
                foreach ($byName[$name] ?? [] as $person) $candidates[(string) $person['id']] = $person;
            }
            $matches = array_values($candidates);
            if (count($matches) === 1) {
                $state = 'suggested';
                $method = $email !== '' && isset($byEmail[$email])
                    ? 'Unique email'
                    : ($phone !== '' && isset($byPhone[$phone]) ? 'Unique phone' : 'Unique name');
            } elseif (count($matches) > 1) {
                $state = 'ambiguous';
                $method = 'Multiple CoreFlux people share the available identity signals';
            }
        }
        $counts[$state]++;
        if ($state !== 'exact' || count($rows) < 10) {
            $rows[] = [
                'source_id' => $sourceId,
                'source_name' => connecteamUserName($source),
                'source_first_name' => connecteamUserFirstName($source),
                'source_last_name' => connecteamUserLastName($source),
                'source_email' => $email,
                'source_phone' => $phone,
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

function connecteamFindSourceUser(int $tenantId, string $sourceUserId): ?array
{
    $sourceUserId = trim($sourceUserId);
    if ($sourceUserId === '') return null;
    $fetch = connecteamFetchCollection(
        $tenantId, '/users/v1/users', ['userStatus' => 'all'], ['users'], 500
    );
    foreach ($fetch['items'] as $user) {
        if (is_array($user) && hash_equals($sourceUserId, connecteamUserId($user))) return $user;
    }
    return null;
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

function connecteamDryRunPreview(
    int $tenantId,
    ?int $userId,
    ?int $peopleTenantId = null,
    ?int $placementsTenantId = null
): array
{
    $peopleTenantId = $peopleTenantId ?? $tenantId;
    $placementsTenantId = $placementsTenantId ?? $tenantId;
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
        'SELECT id, external_id, first_name, last_name,
                email_primary, email_secondary, phone_primary, phone_secondary, status
           FROM people
          WHERE tenant_id = :t AND deleted_at IS NULL'
    );
    $peopleStmt->execute(['t' => $peopleTenantId]);
    $people = $peopleStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $placementsStmt = $pdo->prepare(
        'SELECT id, external_id, title, person_id, status, start_date, end_date
           FROM placements
          WHERE tenant_id = :t AND deleted_at IS NULL'
    );
    $placementsStmt->execute(['t' => $placementsTenantId]);
    $placements = $placementsStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $preview = [
        'read_only' => true,
        'generated_at' => gmdate('c'),
        'people' => connecteamBuildPeoplePreview($users, $people, connecteamStoredLinks($peopleTenantId, 'person')),
        'jobs' => connecteamBuildJobsPreview($jobs, $placements, connecteamStoredLinks($placementsTenantId, 'placement')),
        'scope' => [
            'connection_tenant_id' => $tenantId,
            'people_tenant_id' => $peopleTenantId,
            'placements_tenant_id' => $placementsTenantId,
        ],
        'source_pages' => ['users' => $userFetch['pages'], 'jobs' => $jobFetch['pages']],
        'truncated' => ['users' => $userFetch['truncated'], 'jobs' => $jobFetch['truncated']],
        'rules' => [
            'No CoreFlux records were created or changed.',
            'Email, phone, and title matches are suggestions only.',
            'Only stored Connecteam IDs or explicit placement codes/custom fields count as exact.',
            'A CoreFlux person can retain separate JobDiva and Connecteam identities.',
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
