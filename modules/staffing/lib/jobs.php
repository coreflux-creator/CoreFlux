<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/tenant_scope.php';
require_once __DIR__ . '/clients.php';

function staffingJobPluck(array $payload, array $keys): string
{
    $norm = [];
    foreach ($payload as $key => $value) {
        if (!is_string($key)) continue;
        $nk = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
        if ($nk === '' || array_key_exists($nk, $norm)) continue;
        $norm[$nk] = $value;
    }
    foreach ($keys as $key) {
        $nk = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
        if (!array_key_exists($nk, $norm)) continue;
        $value = $norm[$nk];
        if (!is_scalar($value) || $value === null) continue;
        $out = trim((string) $value);
        if ($out !== '') return $out;
    }
    return '';
}

function staffingJobNormalizeDate(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '0' || strtolower($raw) === 'null') return null;
    if (ctype_digit($raw)) {
        $n = (int) $raw;
        if ($n >= 1_000_000_000_000) $n = (int) ($n / 1000);
        if ($n >= 100_000_000) return gmdate('Y-m-d', $n);
    }
    $ts = strtotime($raw);
    if ($ts !== false) return gmdate('Y-m-d', $ts);
    return preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) ? substr($raw, 0, 10) : null;
}

function staffingJobNormalizeStatus(string $raw): string
{
    $key = strtolower(trim($raw));
    $key = str_replace([' ', '-'], '_', $key);
    return match ($key) {
        'active' => 'active',
        'hold', 'on_hold', 'paused' => 'on_hold',
        'filled', 'placed', 'fulfilled' => 'filled',
        'closed', 'inactive', 'expired' => 'closed',
        'cancelled', 'canceled' => 'cancelled',
        default => 'open',
    };
}

function staffingJobNormalizeRemotePolicy(string $raw): ?string
{
    $key = strtolower(trim($raw));
    $key = str_replace([' ', '-'], '_', $key);
    return match ($key) {
        'onsite', 'on_site' => 'onsite',
        'hybrid' => 'hybrid',
        'remote', 'wfh', 'work_from_home' => 'remote',
        default => null,
    };
}

function staffingJobFindBySource(int $tenantId, string $sourceSystem, string $externalId): ?array
{
    if (trim($externalId) === '') return null;
    try {
        $st = getDB()->prepare(
            'SELECT * FROM staffing_jobs
              WHERE tenant_id = :t AND source_system = :s AND external_id = :e
              LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 's' => $sourceSystem, 'e' => $externalId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $_) {
        return null;
    }
}

function staffingJobEnsureFromJobDivaPayload(int $tenantId, string $externalId, array $payload, ?int $actorUserId = null): array
{
    $externalId = trim($externalId);
    if ($externalId === '') throw new \InvalidArgumentException('job external id required');

    $title = staffingJobPluck($payload, [
        'title', 'jobTitle', 'job_title', 'job title', 'positionTitle', 'position_title',
        'role', 'roleName', 'reqTitle', 'requisitionTitle',
    ]);
    if ($title === '') $title = 'JobDiva Job ' . $externalId;

    $companyExtId = staffingJobPluck($payload, [
        'companyId', 'company_id', 'company id', 'customerId', 'customer_id', 'clientId', 'client_id',
    ]);
    $companyId = null;
    if ($companyExtId !== '' && function_exists('mappingFindInternal')) {
        $mapped = mappingFindInternal($tenantId, 'jobdiva', 'company', $companyExtId);
        if ($mapped) $companyId = (int) $mapped['internal_entity_id'];
    }

    $clientId = null;
    $clientName = staffingJobPluck($payload, [
        'clientName', 'client_name', 'client name', 'companyName', 'company_name',
        'customerName', 'customer_name', 'customer name', 'endClientName', 'end_client_name',
    ]);
    if ($clientName !== '') {
        try {
            $clientRef = staffingClientEnsureForCompany($tenantId, $companyId, $clientName, [
                'created_by_user_id' => $actorUserId,
            ]);
            $clientId = (int) ($clientRef['client_id'] ?? 0) ?: null;
            $companyId = !empty($clientRef['company_id']) ? (int) $clientRef['company_id'] : $companyId;
        } catch (\Throwable $e) {
            error_log('[staffing jobs] client bridge failed: ' . $e->getMessage());
        }
    }

    $country = strtoupper(staffingJobPluck($payload, ['country', 'jobCountry', 'job_country', 'worksiteCountry']));
    if ($country === '') $country = null;
    if (is_string($country) && strlen($country) > 2) $country = substr($country, 0, 2);

    $row = [
        'tenant_id'          => $tenantId,
        'client_id'          => $clientId,
        'company_id'         => $companyId,
        'title'              => $title,
        'status'             => staffingJobNormalizeStatus(staffingJobPluck($payload, ['status', 'jobStatus', 'job_status'])),
        'external_id'        => $externalId,
        'source_system'      => 'jobdiva',
        'description'        => staffingJobPluck($payload, ['description', 'jobDescription', 'job_description']),
        'department'         => staffingJobPluck($payload, ['department', 'dept', 'division']),
        'location_city'      => staffingJobPluck($payload, ['city', 'jobCity', 'job_city', 'worksiteCity']),
        'location_state'     => staffingJobPluck($payload, ['state', 'jobState', 'job_state', 'worksiteState']),
        'location_country'   => $country,
        'remote_policy'      => staffingJobNormalizeRemotePolicy(staffingJobPluck($payload, ['remotePolicy', 'remote_policy', 'workLocation', 'locationType'])),
        'opened_at'          => staffingJobNormalizeDate(staffingJobPluck($payload, ['openDate', 'openedAt', 'dateOpened', 'startDate'])),
        'closed_at'          => staffingJobNormalizeDate(staffingJobPluck($payload, ['closedDate', 'closedAt', 'dateClosed', 'endDate'])),
        'created_by_user_id' => $actorUserId,
    ];

    $existing = staffingJobFindBySource($tenantId, 'jobdiva', $externalId);
    $pdo = getDB();
    if ($existing) {
        $patch = [];
        foreach ($row as $key => $value) {
            if (in_array($key, ['tenant_id', 'external_id', 'source_system', 'created_by_user_id'], true)) continue;
            if ($value === null || $value === '') continue;
            if (!array_key_exists($key, $existing) || (string) ($existing[$key] ?? '') !== (string) $value) {
                $patch[$key] = $value;
            }
        }
        if ($patch) {
            $sets = [];
            $params = ['t' => $tenantId, 'id' => (int) $existing['id']];
            foreach ($patch as $key => $value) {
                $sets[] = "`{$key}` = :{$key}";
                $params[$key] = $value;
            }
            $sets[] = 'updated_at = NOW()';
            $pdo->prepare('UPDATE staffing_jobs SET ' . implode(', ', $sets) . ' WHERE tenant_id = :t AND id = :id')
                ->execute($params);
        }
        return staffingJobFindBySource($tenantId, 'jobdiva', $externalId) ?: $existing;
    }

    $insert = array_filter($row, static fn($value) => $value !== null && $value !== '');
    $cols = array_keys($insert);
    $placeholders = array_map(static fn($col) => ":{$col}", $cols);
    $pdo->prepare(
        'INSERT INTO staffing_jobs (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', $placeholders) . ')'
    )->execute($insert);
    return staffingJobFindBySource($tenantId, 'jobdiva', $externalId) ?: ['id' => (int) $pdo->lastInsertId()] + $insert;
}

function staffingJobLinkPlacementsByJobDivaId(int $tenantId, string $jobdivaJobId, int $staffingJobId): int
{
    if (trim($jobdivaJobId) === '' || $staffingJobId <= 0) return 0;
    try {
        $st = getDB()->prepare(
            'UPDATE placements
                SET staffing_job_id = :sj
              WHERE tenant_id = :t
                AND jobdiva_job_id = :jd
                AND (staffing_job_id IS NULL OR staffing_job_id != :sj2)'
        );
        $st->execute(['sj' => $staffingJobId, 't' => $tenantId, 'jd' => $jobdivaJobId, 'sj2' => $staffingJobId]);
        return $st->rowCount();
    } catch (\Throwable $e) {
        error_log('[staffing jobs] placement link failed: ' . $e->getMessage());
        return 0;
    }
}
