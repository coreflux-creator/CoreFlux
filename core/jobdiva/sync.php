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

function jobdivaSyncCompanies(int $tid, ?int $userId, array $opts = []): array
{
    $items = jobdivaSyncFetchItems($tid, '/api/jobdiva/companies', $opts);
    $processed = 0; $skipped = 0; $failed = 0; $errors = [];

    foreach ($items as $jd) {
        try {
            $extId = (string) ($jd['id'] ?? $jd['companyId'] ?? $jd['company_id'] ?? '');
            $name  = trim((string) ($jd['name'] ?? $jd['companyName'] ?? $jd['company_name'] ?? ''));
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
    $items = jobdivaSyncFetchItems($tid, '/api/jobdiva/contacts', $opts);
    $processed = 0; $skipped = 0; $failed = 0; $errors = [];

    foreach ($items as $jd) {
        try {
            $extId        = (string) ($jd['id'] ?? $jd['contactId'] ?? $jd['contact_id'] ?? '');
            $companyExtId = (string) ($jd['companyId'] ?? $jd['company_id'] ?? '');
            $name         = trim((string) ($jd['name'] ?? trim(($jd['firstName'] ?? '') . ' ' . ($jd['lastName'] ?? ''))));
            if ($extId === '' || $name === '' || $companyExtId === '') { $skipped++; continue; }

            // Resolve internal company via mapping created by jobdivaSyncCompanies().
            $companyMapping = mappingFindInternal($tid, 'jobdiva', 'company', $companyExtId);
            if (!$companyMapping) { $skipped++; continue; }
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

    jobdivaAudit($tid, 'sync', [
        'entity_type'     => 'contact',
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

function jobdivaSyncUpsertContact(int $tid, int $companyId, array $jd, string $name): int
{
    $email = trim((string) ($jd['email'] ?? ''));
    $phone = trim((string) ($jd['phone'] ?? ''));
    $title = trim((string) ($jd['title'] ?? $jd['jobTitle'] ?? ''));

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
    $items = jobdivaSyncFetchItems($tid, '/api/jobdiva/placements', $opts);
    $processed = 0; $skipped = 0; $failed = 0; $errors = [];

    foreach ($items as $jd) {
        try {
            $extId        = (string) ($jd['id'] ?? $jd['placementId'] ?? $jd['placement_id'] ?? '');
            $personExtId  = (string) ($jd['employeeId'] ?? $jd['candidateId'] ?? $jd['person_id'] ?? '');
            $companyExtId = (string) ($jd['companyId']  ?? $jd['company_id']  ?? '');
            $startDate    = (string) ($jd['startDate']  ?? $jd['start_date']  ?? '');
            if ($extId === '' || $personExtId === '' || $startDate === '') { $skipped++; continue; }

            // Person mapping must already exist — JobDiva employee↔CoreFlux person
            // is established when employees are pulled (out of A3 scope; we DO NOT
            // sync candidates/applicants per user requirement). If no mapping,
            // skip the placement gracefully.
            $personMapping = mappingFindInternal($tid, 'jobdiva', 'person', $personExtId);
            if (!$personMapping) { $skipped++; continue; }
            $personId = (int) $personMapping['internal_entity_id'];

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
        'detail'          => ['errors' => array_slice($errors, 0, 5)],
    ]);
    return ['processed' => $processed, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
}

function jobdivaSyncUpsertPlacement(int $tid, int $personId, ?int $endClientCompanyId, array $jd, string $extId): int
{
    $pdo = getDB();
    // Look up by external_id first (placements has a `external_id` column).
    $stmt = $pdo->prepare('SELECT id FROM placements WHERE tenant_id = :t AND external_id = :ext LIMIT 1');
    $stmt->execute(['t' => $tid, 'ext' => 'jd:' . $extId]);
    $existingId = (int) $stmt->fetchColumn();

    $startDate = (string) ($jd['startDate'] ?? $jd['start_date'] ?? '');
    $endDate   = (string) ($jd['endDate']   ?? $jd['end_date']   ?? '');
    $endClientName = trim((string) ($jd['endClientName'] ?? $jd['clientName'] ?? ''));
    $statusJd  = strtolower((string) ($jd['status'] ?? 'active'));
    $statusMap = ['active' => 'active', 'pending' => 'pending_start', 'ended' => 'ended', 'cancelled' => 'cancelled'];
    $status    = $statusMap[$statusJd] ?? 'active';

    if ($existingId > 0) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare(
            'UPDATE placements SET start_date = :sd, end_date = :ed,
                    status = :st, end_client_name = :ecn, end_client_company_id = :ecc
              WHERE id = :id'
        )->execute([
            'sd' => $startDate, 'ed' => $endDate ?: null, 'st' => $status,
            'ecn' => $endClientName ?: null, 'ecc' => $endClientCompanyId, 'id' => $existingId,
        ]);
        return $existingId;
    }
    $pdo->prepare(
        'INSERT INTO placements (tenant_id, person_id, external_id, status, start_date, end_date,
                                  engagement_type, end_client_name, end_client_company_id)
         VALUES (:t, :p, :ext, :st, :sd, :ed, "w2", :ecn, :ecc)'
    )->execute([
        't' => $tid, 'p' => $personId, 'ext' => 'jd:' . $extId, 'st' => $status,
        'sd' => $startDate, 'ed' => $endDate ?: null,
        'ecn' => $endClientName ?: null, 'ecc' => $endClientCompanyId,
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
    if ($shouldPull($config, 'company')) {
        $companies  = jobdivaSyncCompanies($tid, $userId, $opts['companies']  ?? $opts);
    } else {
        $companies  = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'skipped_by_config' => true];
        $skipped[]  = 'company';
    }
    if ($shouldPull($config, 'contact')) {
        $contacts   = jobdivaSyncContacts($tid, $userId, $opts['contacts']   ?? $opts);
    } else {
        $contacts   = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'skipped_by_config' => true];
        $skipped[]  = 'contact';
    }
    if ($shouldPull($config, 'placement')) {
        $placements = jobdivaSyncPlacements($tid, $userId, $opts['placements'] ?? $opts);
    } else {
        $placements = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'skipped_by_config' => true];
        $skipped[]  = 'placement';
    }

    // Time direction wiring (Slice A4 follow-on). Pull, push, two_way honored.
    $timeResult = ['processed' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'skipped_by_config' => true];
    if ($shouldPull($config, 'time') || $shouldPush($config, 'time')) {
        require_once __DIR__ . '/sync_time.php';
        $pull = $shouldPull($config, 'time') ? jobdivaSyncTimePull($tid, $userId, $opts['time'] ?? $opts) : null;
        $push = $shouldPush($config, 'time') ? jobdivaSyncTimePush($tid, $userId, $opts['time'] ?? $opts) : null;
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
 * Accepts both list-style and paginated `{data: [...]}` responses.
 */
function jobdivaSyncFetchItems(int $tid, string $path, array $opts): array
{
    if (isset($opts['items_override']) && is_array($opts['items_override'])) {
        return $opts['items_override'];
    }
    $query = [];
    if (!empty($opts['modified_since'])) $query['modifiedSince'] = $opts['modified_since'];
    $resp  = jobdivaCall($tid, 'GET', $path, null, $query ?: null);
    if (is_array($resp)) {
        if (isset($resp['data']) && is_array($resp['data']))   return $resp['data'];
        if (isset($resp['items']) && is_array($resp['items'])) return $resp['items'];
        // Plain list response.
        if (array_keys($resp) === range(0, count($resp) - 1))  return $resp;
    }
    return [];
}
