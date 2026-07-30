<?php
/**
 * Controlled JobDiva placement reconciliation.
 *
 * This is deliberately separate from the generic placement CSV importer.
 * Identity is exact JobDiva Start/Assignment ID only. There is no fallback to
 * candidate, title, date, job, or person composites, and this workflow never
 * deletes or archives a CoreFlux record.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/jobdiva/assignment_identity.php';

function jobdivaReconciliationSchema(): array
{
    return [
        'fields' => [
            'start_id'             => ['label' => 'Start ID', 'required' => true],
            'candidate_id'         => ['label' => 'Candidate ID'],
            'person_email'         => ['label' => 'Candidate Email'],
            'person_name'          => ['label' => 'Candidate Name'],
            'job_id'               => ['label' => 'Job ID'],
            'title'                => ['label' => 'Title'],
            'status'               => ['label' => 'Status'],
            'engagement_type'      => ['label' => 'Worker Classification'],
            'start_date'           => ['label' => 'Start Date'],
            'end_date'             => ['label' => 'End Date'],
            'end_client_name'      => ['label' => 'End Client'],
            'worksite_state'       => ['label' => 'Worksite State'],
            'worksite_country'     => ['label' => 'Worksite Country'],
            'remote_policy'        => ['label' => 'Remote Policy'],
            'recruiter_name'       => ['label' => 'Recruiter Name'],
            'recruiter_email'      => ['label' => 'Recruiter Email'],
            'account_manager_name' => ['label' => 'Account Manager Name'],
            'account_manager_email'=> ['label' => 'Account Manager Email'],
            'bill_rate'            => ['label' => 'Bill Rate'],
            'pay_rate'             => ['label' => 'Pay Rate'],
            'bill_rate_unit'       => ['label' => 'Bill Rate Unit'],
            'pay_rate_unit'        => ['label' => 'Pay Rate Unit'],
            'currency'             => ['label' => 'Currency'],
            'client_bill_cycle'    => ['label' => 'Client Billing Frequency'],
            'client_payment_terms_override' => ['label' => 'Client Payment Terms'],
            'vendor_pay_cycle'     => ['label' => 'Vendor Payment Frequency'],
            'vendor_payment_terms_override' => ['label' => 'Vendor Payment Terms'],
            'vendor_pwp_enabled'   => ['label' => 'Vendor Paid When Paid'],
            'notes'                => ['label' => 'Notes'],
        ],
        'unique_within_batch' => ['start_id'],
    ];
}

function jobdivaReconciliationHeaderAliases(): array
{
    return [
        'start_id' => [
            'start id', 'startid', 'start id #', 'jobdiva start id',
            'assignment id', 'assignmentid', 'placement id',
        ],
        'candidate_id' => ['candidate id', 'candidateid', 'employee id', 'employeeid'],
        'person_email' => ['candidate email', 'candidateemail', 'employee email', 'email'],
        'person_name' => ['candidate name', 'candidatename', 'employee name', 'worker name'],
        'job_id' => ['job id', 'jobid', 'job #', 'job number', 'job ref no', 'jobrefno', 'req id'],
        'title' => ['title', 'job title', 'position title', 'position'],
        'status' => ['status', 'start status', 'startstatus', 'assignment status', 'placement status'],
        'engagement_type' => [
            'worker classification', 'employment category', 'employment type',
            'position type', 'engagement type', 'worker type',
        ],
        'start_date' => ['start date', 'startdate', 'hire date'],
        'end_date' => ['end date', 'enddate', 'assignment end date'],
        'end_client_name' => [
            'end client', 'end client name', 'client name', 'customer name',
            'company name', 'companyname',
        ],
        'worksite_state' => ['worksite state', 'state', 'job state'],
        'worksite_country' => ['worksite country', 'country', 'job country'],
        'remote_policy' => ['remote policy', 'remote', 'work arrangement'],
        'recruiter_name' => ['recruiter name', 'recruiter'],
        'recruiter_email' => ['recruiter email'],
        'account_manager_name' => ['account manager name', 'account manager', 'am name'],
        'account_manager_email' => ['account manager email', 'am email'],
        'bill_rate' => ['final bill rate', 'bill rate', 'billrate', 'agreed bill rate'],
        'pay_rate' => ['agreed pay rate', 'pay rate', 'payrate', 'final pay rate'],
        'bill_rate_unit' => ['final bill rate unit', 'bill rate unit', 'bill unit'],
        'pay_rate_unit' => ['pay rate unit', 'pay unit'],
        'currency' => ['currency', 'bill rate currency', 'rate currency'],
        'client_bill_cycle' => ['client billing frequency', 'billing frequency', 'bill cycle'],
        'client_payment_terms_override' => ['client payment terms', 'billing terms', 'ar terms'],
        'vendor_pay_cycle' => ['vendor payment frequency', 'vendor pay frequency', 'ap cycle'],
        'vendor_payment_terms_override' => ['vendor payment terms', 'vendor terms', 'ap terms'],
        'vendor_pwp_enabled' => ['vendor paid when paid', 'paid when paid', 'pwp'],
        'notes' => ['notes', 'internal notes', 'comments'],
    ];
}

function jobdivaReconciliationNormaliseHeader(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', trim($value)) ?? trim($value);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    return trim($value);
}

function jobdivaReconciliationInspect(string $csv): array
{
    $schema = \Core\CsvImportService::inspect('jobdiva_placement_reconciliation', $csv);
    $aliases = [];
    foreach (jobdivaReconciliationHeaderAliases() as $field => $values) {
        foreach ($values as $alias) {
            $aliases[jobdivaReconciliationNormaliseHeader($alias)] = $field;
        }
    }
    foreach ($schema['headers'] as $i => $header) {
        if (!empty($schema['auto_map'][$i])) continue;
        $key = jobdivaReconciliationNormaliseHeader((string) $header);
        if (isset($aliases[$key])) $schema['auto_map'][$i] = $aliases[$key];
    }
    $schema['identity_rule'] = 'Exact JobDiva Start ID only';
    $schema['write_policy'] = 'Preview first; selected rows only; no delete or archive';
    return $schema;
}

function jobdivaReconciliationColumnMap(array $headers, ?array $columnMap): array
{
    if (!$columnMap) return [];
    $resolved = [];
    $headerIndexes = [];
    foreach ($headers as $i => $header) {
        $headerIndexes[jobdivaReconciliationNormaliseHeader((string) $header)] = (int) $i;
    }
    foreach ($columnMap as $key => $field) {
        if ($field === null || $field === '') continue;
        if (is_int($key) || ctype_digit((string) $key)) {
            $resolved[(int) $key] = (string) $field;
            continue;
        }
        $normalised = jobdivaReconciliationNormaliseHeader((string) $key);
        if (isset($headerIndexes[$normalised])) {
            $resolved[$headerIndexes[$normalised]] = (string) $field;
        }
    }
    return $resolved;
}

function jobdivaReconciliationMappedHeaders(array $headers, array $columnMap): array
{
    $out = [];
    foreach ($columnMap as $i => $field) {
        if ($field === null || $field === '') continue;
        $out[(string) $field] = (string) ($headers[(int) $i] ?? '');
    }
    return $out;
}

function jobdivaReconciliationNormaliseDate(mixed $value): ?string
{
    $raw = trim((string) $value);
    if ($raw === '') return null;
    if (preg_match('/^\d{10,16}$/', $raw)) {
        $numeric = (int) $raw;
        if ($numeric > 9999999999) $numeric = (int) floor($numeric / 1000);
        if ($numeric > 0) return gmdate('Y-m-d', $numeric);
    }
    try {
        return (new \DateTimeImmutable($raw))->format('Y-m-d');
    } catch (\Throwable $_) {
        return null;
    }
}

function jobdivaReconciliationNormaliseNumber(mixed $value): ?float
{
    $raw = trim((string) $value);
    if ($raw === '') return null;
    $raw = preg_replace('/[^0-9.\-]/', '', $raw) ?? '';
    if ($raw === '' || !is_numeric($raw)) return null;
    return round((float) $raw, 4);
}

function jobdivaReconciliationTruthy(mixed $value): ?bool
{
    $raw = strtolower(trim((string) $value));
    if ($raw === '') return null;
    if (in_array($raw, ['1','true','yes','y','on','enabled'], true)) return true;
    if (in_array($raw, ['0','false','no','n','off','disabled'], true)) return false;
    return null;
}

function jobdivaReconciliationNormaliseEngagement(mixed $value, string $sourceHeader = ''): ?string
{
    $raw = strtolower(trim((string) $value));
    if ($raw === '') return null;
    $header = jobdivaReconciliationNormaliseHeader($sourceHeader);
    $truthy = jobdivaReconciliationTruthy($raw);
    if ($truthy === true && preg_match('/\b(c2c|corp|crop)\b/', $header)) return 'c2c';
    if ($truthy === true && preg_match('/\bw ?2\b/', $header)) return 'w2';
    $flat = preg_replace('/[^a-z0-9]+/', ' ', $raw) ?? $raw;
    if (preg_match('/\b(c2c|corp to corp|corporation)\b/', $flat)) return 'c2c';
    if (preg_match('/\b(w2|w 2|employee)\b/', $flat)) return 'w2';
    if (preg_match('/\b(1099|independent contractor|contractor)\b/', $flat)) return '1099';
    if (str_contains($flat, 'temp to perm') || str_contains($flat, 'contract to hire')) return 'temp_to_perm';
    if (str_contains($flat, 'direct hire') || $flat === 'permanent') return 'direct_hire';
    return null;
}

function jobdivaReconciliationNormaliseStatus(mixed $value): ?string
{
    $raw = strtolower(trim((string) $value));
    if ($raw === '') return null;
    $flat = preg_replace('/[^a-z0-9]+/', ' ', $raw) ?? $raw;
    if (in_array($flat, ['draft'], true)) return 'draft';
    if (str_contains($flat, 'pending') || str_contains($flat, 'scheduled') || str_contains($flat, 'offer accepted')) return 'pending_start';
    if (str_contains($flat, 'hold')) return 'on_hold';
    if (str_contains($flat, 'cancel') || str_contains($flat, 'rescinded') || str_contains($flat, 'did not start')) return 'cancelled';
    if (str_contains($flat, 'end') || str_contains($flat, 'complete') || str_contains($flat, 'terminate')) return 'ended';
    if (str_contains($flat, 'active') || str_contains($flat, 'started') || str_contains($flat, 'working') || str_contains($flat, 'placed')) return 'active';
    return null;
}

function jobdivaReconciliationNormaliseRateUnit(mixed $value): ?string
{
    $raw = strtolower(trim((string) $value));
    if ($raw === '') return null;
    if (str_starts_with($raw, 'h')) return 'hour';
    if (str_starts_with($raw, 'd')) return 'day';
    if (str_starts_with($raw, 'w')) return 'week';
    if (str_starts_with($raw, 'm')) return 'month';
    if (str_starts_with($raw, 'p') || str_contains($raw, 'flat')) return 'project';
    return null;
}

function jobdivaReconciliationNormaliseCycle(mixed $value): ?string
{
    $raw = strtolower(trim((string) $value));
    if ($raw === '') return null;
    $flat = preg_replace('/[^a-z0-9]+/', '', $raw) ?? $raw;
    $map = [
        'weekly' => 'weekly', 'week' => 'weekly',
        'biweekly' => 'biweekly', 'every2weeks' => 'biweekly',
        'semimonthly' => 'semimonthly', 'twicemonthly' => 'semimonthly',
        'monthly' => 'monthly', 'month' => 'monthly',
        'adhoc' => 'adhoc', 'asneeded' => 'adhoc',
    ];
    return $map[$flat] ?? null;
}

function jobdivaReconciliationNormaliseTerms(mixed $value): ?string
{
    $raw = strtoupper(trim((string) $value));
    if ($raw === '') return null;
    $flat = str_replace([' ', '-', '_'], '', $raw);
    if (in_array($flat, ['DUEONRECEIPT', 'RECEIPT', 'IMMEDIATE', 'NET0'], true)) {
        return 'DUE_ON_RECEIPT';
    }
    if (preg_match('/^NET(\d{1,3})$/', $flat, $matches)) {
        return 'NET' . (int) $matches[1];
    }
    if (in_array($flat, ['PWP', 'PAIDWHENPAID', 'PAYWHENPAID'], true)) {
        return 'PWP';
    }
    if (preg_match('/^(?:PWP|PAIDWHENPAID|PAYWHENPAID)NET(\d{1,3})$/', $flat, $matches)) {
        return 'PWP_NET' . (int) $matches[1];
    }
    return null;
}

function jobdivaReconciliationNormaliseRow(array $row, array $mappedHeaders, array &$errors): array
{
    $out = [];
    foreach ($row as $field => $value) {
        $raw = trim((string) $value);
        if ($raw === '') continue;
        $out[$field] = $raw;
    }
    if (isset($out['start_id'])) {
        $out['start_id'] = jobdivaAssignmentIdentityNormaliseId($out['start_id']);
        if ($out['start_id'] === '') {
            $errors[] = 'start_id: required';
        } elseif (!preg_match('/^\d+$/', $out['start_id'])) {
            $errors[] = "start_id: expected the exact digits exported by JobDiva; scientific notation and formatted values are not safe identities";
        }
    }
    foreach (['start_date','end_date'] as $field) {
        if (!array_key_exists($field, $out)) continue;
        $date = jobdivaReconciliationNormaliseDate($out[$field]);
        if ($date === null) $errors[] = "{$field}: could not parse '{$out[$field]}'";
        else $out[$field] = $date;
    }
    if (isset($out['engagement_type'])) {
        $value = jobdivaReconciliationNormaliseEngagement(
            $out['engagement_type'],
            $mappedHeaders['engagement_type'] ?? ''
        );
        if ($value === null) $errors[] = "engagement_type: unrecognised '{$out['engagement_type']}'";
        else $out['engagement_type'] = $value;
    }
    if (isset($out['status'])) {
        $value = jobdivaReconciliationNormaliseStatus($out['status']);
        if ($value === null) $errors[] = "status: unrecognised '{$out['status']}'";
        else $out['status'] = $value;
    }
    foreach (['bill_rate','pay_rate'] as $field) {
        if (!array_key_exists($field, $out)) continue;
        $value = jobdivaReconciliationNormaliseNumber($out[$field]);
        if ($value === null || $value < 0) $errors[] = "{$field}: invalid amount '{$out[$field]}'";
        else $out[$field] = $value;
    }
    foreach (['bill_rate_unit','pay_rate_unit'] as $field) {
        if (!array_key_exists($field, $out)) continue;
        $value = jobdivaReconciliationNormaliseRateUnit($out[$field]);
        if ($value === null) $errors[] = "{$field}: unrecognised unit '{$out[$field]}'";
        else $out[$field] = $value;
    }
    foreach (['client_bill_cycle','vendor_pay_cycle'] as $field) {
        if (!array_key_exists($field, $out)) continue;
        $value = jobdivaReconciliationNormaliseCycle($out[$field]);
        if ($value === null) $errors[] = "{$field}: unrecognised frequency '{$out[$field]}'";
        else $out[$field] = $value;
    }
    foreach (['client_payment_terms_override','vendor_payment_terms_override'] as $field) {
        if (!array_key_exists($field, $out)) continue;
        $value = jobdivaReconciliationNormaliseTerms($out[$field]);
        if ($value === null) $errors[] = "{$field}: unrecognised payment terms '{$out[$field]}'";
        else $out[$field] = $value;
    }
    if (isset($out['vendor_pwp_enabled'])) {
        $value = jobdivaReconciliationTruthy($out['vendor_pwp_enabled']);
        if ($value === null) $errors[] = "vendor_pwp_enabled: unrecognised boolean '{$out['vendor_pwp_enabled']}'";
        else $out['vendor_pwp_enabled'] = $value ? 1 : 0;
    }
    if (isset($out['remote_policy'])) {
        $remote = strtolower(trim((string) $out['remote_policy']));
        $remote = in_array($remote, ['onsite','hybrid','remote'], true) ? $remote : null;
        if ($remote === null) $errors[] = "remote_policy: unrecognised '{$out['remote_policy']}'";
        else $out['remote_policy'] = $remote;
    }
    if (isset($out['worksite_country'])) {
        $countryRaw = strtoupper(trim((string) $out['worksite_country']));
        $countryAliases = [
            'USA' => 'US', 'UNITED STATES' => 'US', 'UNITED STATES OF AMERICA' => 'US',
            'CANADA' => 'CA',
        ];
        $country = $countryAliases[$countryRaw] ?? $countryRaw;
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            $errors[] = "worksite_country: expected a 2-letter code, got '{$out['worksite_country']}'";
        } else {
            $out['worksite_country'] = $country;
        }
    }
    foreach (['person_email','recruiter_email','account_manager_email'] as $field) {
        if (!isset($out[$field])) continue;
        $email = strtolower(trim((string) $out[$field]));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "{$field}: invalid email '{$out[$field]}'";
        } else {
            $out[$field] = $email;
        }
    }
    if (isset($out['currency'])) {
        $currency = strtoupper(trim((string) $out['currency']));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) $errors[] = "currency: expected ISO code, got '{$out['currency']}'";
        else $out['currency'] = $currency;
    }
    return $out;
}

function jobdivaReconciliationParse(string $csv, ?array $columnMap): array
{
    $inspect = jobdivaReconciliationInspect($csv);
    $resolvedMap = jobdivaReconciliationColumnMap($inspect['headers'], $columnMap ?: $inspect['auto_map']);
    $mappedHeaders = jobdivaReconciliationMappedHeaders($inspect['headers'], $resolvedMap);
    $targetCounts = array_count_values(array_values($resolvedMap));
    $mappingErrors = [];
    foreach ($targetCounts as $field => $count) {
        if ($count > 1) $mappingErrors[] = "{$field}: mapped from more than one CSV column";
    }
    if (!in_array('start_id', $resolvedMap, true)) {
        $mappingErrors[] = 'Map exactly one CSV column to Start ID before previewing.';
    }

    $dry = \Core\CsvImportService::dryRun(
        'jobdiva_placement_reconciliation',
        $csv,
        $resolvedMap
    );
    $rows = [];
    $seenStartIds = [];
    foreach ($dry['rows'] as $rowNumber => $row) {
        $errors = $dry['errors'][$rowNumber] ?? [];
        $normalised = jobdivaReconciliationNormaliseRow($row, $mappedHeaders, $errors);
        $startId = (string) ($normalised['start_id'] ?? '');
        if ($startId !== '') {
            if (isset($seenStartIds[$startId])) {
                $firstRow = $seenStartIds[$startId];
                $message = "start_id: duplicate exact Start ID '{$startId}' after canonicalization";
                $dry['errors'][$firstRow][] = $message;
                $errors[] = $message;
            } else {
                $seenStartIds[$startId] = (int) $rowNumber;
            }
        }
        $rows[(int) $rowNumber] = $normalised;
        if ($errors) $dry['errors'][$rowNumber] = array_values(array_unique($errors));
    }
    foreach ($dry['errors'] as $rowNumber => $rowErrors) {
        $dry['errors'][$rowNumber] = array_values(array_unique($rowErrors));
    }
    $dry['rows'] = $rows;
    $dry['error_count'] = count($dry['errors']);
    $dry['headers'] = $inspect['headers'];
    $dry['column_map'] = $resolvedMap;
    $dry['mapped_headers'] = $mappedHeaders;
    $dry['mapping_errors'] = $mappingErrors;
    return $dry;
}

function jobdivaReconciliationFetchExactPlacement(\PDO $pdo, int $tenantId, string $startId): array
{
    $canonical = 'jd:' . $startId;
    $ids = [];
    $mappingIds = [];
    $st = $pdo->prepare(
        'SELECT id, internal_entity_id
           FROM external_entity_mappings
          WHERE tenant_id = :t AND source_system = "jobdiva"
            AND internal_entity_type = "placement"
            AND external_id IN (:raw_id, :canonical_id)'
    );
    $st->execute(['t' => $tenantId, 'raw_id' => $startId, 'canonical_id' => $canonical]);
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $mapping) {
        $ids[(int) $mapping['internal_entity_id']] = true;
        $mappingIds[] = (int) $mapping['id'];
    }
    $st = $pdo->prepare(
        'SELECT id FROM placements
          WHERE tenant_id = :t AND external_id IN (:raw_id, :canonical_id)'
    );
    $st->execute(['t' => $tenantId, 'raw_id' => $startId, 'canonical_id' => $canonical]);
    foreach ($st->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) $ids[(int) $id] = true;

    $ids = array_values(array_map('intval', array_keys($ids)));
    if (count($ids) > 1) {
        return ['status' => 'ambiguous', 'ids' => $ids, 'mapping_ids' => $mappingIds, 'row' => null];
    }
    if (!$ids) return ['status' => 'missing', 'ids' => [], 'mapping_ids' => [], 'row' => null];

    $internalMapping = $pdo->prepare(
        'SELECT id, external_id
           FROM external_entity_mappings
          WHERE tenant_id = :t AND source_system = "jobdiva"
            AND internal_entity_type = "placement"
            AND internal_entity_id = :id
          LIMIT 1'
    );
    $internalMapping->execute(['t' => $tenantId, 'id' => $ids[0]]);
    $bound = $internalMapping->fetch(\PDO::FETCH_ASSOC) ?: null;
    if ($bound
        && jobdivaAssignmentIdentityNormaliseId((string) $bound['external_id']) !== $startId) {
        return [
            'status' => 'internal_conflict',
            'ids' => $ids,
            'mapping_ids' => [(int) $bound['id']],
            'conflicting_external_id' => (string) $bound['external_id'],
            'row' => null,
        ];
    }

    $st = $pdo->prepare(
        'SELECT p.*, pe.first_name, pe.last_name, pe.email_primary,
                ec.name AS end_client_company_name
           FROM placements p
      LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = p.tenant_id
      LEFT JOIN companies ec ON ec.id = p.end_client_company_id AND ec.tenant_id = p.tenant_id
          WHERE p.tenant_id = :t AND p.id = :id LIMIT 1'
    );
    $st->execute(['t' => $tenantId, 'id' => $ids[0]]);
    $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    if (!$row) return ['status' => 'broken_mapping', 'ids' => $ids, 'mapping_ids' => $mappingIds, 'row' => null];
    if (!empty($row['deleted_at'])) {
        return ['status' => 'archived', 'ids' => $ids, 'mapping_ids' => $mappingIds, 'row' => $row];
    }
    return ['status' => 'matched', 'ids' => $ids, 'mapping_ids' => $mappingIds, 'row' => $row];
}

function jobdivaReconciliationResolvePerson(
    \PDO $pdo,
    int $mappingTenantId,
    int $peopleTenantId,
    array $source,
    ?array $placement
): array {
    $candidateId = jobdivaAssignmentIdentityNormaliseId((string) ($source['candidate_id'] ?? ''));
    $unresolvedSourceIdentity = [];
    if ($candidateId !== '') {
        $st = $pdo->prepare(
            'SELECT DISTINCT m.internal_entity_id, pe.first_name, pe.last_name, pe.email_primary
               FROM external_entity_mappings m
               JOIN people pe ON pe.id = m.internal_entity_id AND pe.tenant_id = :people_tenant
              WHERE m.tenant_id IN (:mapping_tenant, :people_mapping_tenant)
                AND m.source_system = "jobdiva"
                AND m.internal_entity_type = "person"
                AND m.external_id IN (:raw_id, :canonical_id)
                AND pe.deleted_at IS NULL'
        );
        $st->execute([
            'people_tenant' => $peopleTenantId,
            'mapping_tenant' => $mappingTenantId,
            'people_mapping_tenant' => $peopleTenantId,
            'raw_id' => $candidateId,
            'canonical_id' => 'jd:' . $candidateId,
        ]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if (count($rows) === 1) {
            return [
                'id' => (int) $rows[0]['internal_entity_id'],
                'label' => trim((string) ($rows[0]['first_name'] . ' ' . $rows[0]['last_name'])),
                'email' => $rows[0]['email_primary'] ?? null,
                'matched_by' => 'candidate_id',
                'error' => null,
            ];
        }
        if (count($rows) > 1) {
            return ['id' => null, 'label' => null, 'email' => null, 'matched_by' => null, 'error' => 'Candidate ID maps to multiple People rows.'];
        }
        $unresolvedSourceIdentity[] = "Candidate ID {$candidateId} is not mapped to People";
    }
    $email = strtolower(trim((string) ($source['person_email'] ?? '')));
    if ($email !== '') {
        $st = $pdo->prepare(
            'SELECT id, first_name, last_name, email_primary
               FROM people
              WHERE tenant_id = :t AND LOWER(TRIM(email_primary)) = :email
                AND deleted_at IS NULL'
        );
        $st->execute(['t' => $peopleTenantId, 'email' => $email]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if (count($rows) === 1) {
            return [
                'id' => (int) $rows[0]['id'],
                'label' => trim((string) ($rows[0]['first_name'] . ' ' . $rows[0]['last_name'])),
                'email' => $rows[0]['email_primary'] ?? null,
                'matched_by' => 'email',
                'error' => null,
            ];
        }
        if (count($rows) > 1) {
            return ['id' => null, 'label' => null, 'email' => $email, 'matched_by' => null, 'error' => 'Candidate email matches multiple People rows.'];
        }
        $unresolvedSourceIdentity[] = "candidate email {$email} was not found in People";
    }
    if ($placement && !empty($placement['person_id'])) {
        return [
            'id' => (int) $placement['person_id'],
            'label' => trim((string) (($placement['first_name'] ?? '') . ' ' . ($placement['last_name'] ?? ''))),
            'email' => $placement['email_primary'] ?? null,
            'matched_by' => 'existing_placement',
            'error' => $unresolvedSourceIdentity
                ? implode('; ', $unresolvedSourceIdentity)
                : null,
        ];
    }
    return [
        'id' => null,
        'label' => trim((string) ($source['person_name'] ?? '')) ?: null,
        'email' => $email ?: null,
        'matched_by' => null,
        'error' => 'No unique People row resolved from Candidate ID or candidate email.',
    ];
}

function jobdivaReconciliationFetchRate(\PDO $pdo, int $tenantId, int $placementId): ?array
{
    $st = $pdo->prepare(
        'SELECT * FROM placement_rates
          WHERE tenant_id = :t AND placement_id = :p
          ORDER BY (approved_at IS NULL) DESC, effective_from DESC, id DESC
          LIMIT 1'
    );
    $st->execute(['t' => $tenantId, 'p' => $placementId]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
}

function jobdivaReconciliationValueEqual(mixed $left, mixed $right): bool
{
    if ($left === null && $right === null) return true;
    if (is_numeric($left) && is_numeric($right)) {
        return abs((float) $left - (float) $right) < 0.00001;
    }
    return trim((string) $left) === trim((string) $right);
}

function jobdivaReconciliationChange(string $group, string $field, string $label, mixed $from, mixed $to): array
{
    return [
        'group' => $group,
        'field' => $field,
        'label' => $label,
        'from' => $from,
        'to' => $to,
    ];
}

function jobdivaReconciliationBuildRowPlan(
    \PDO $pdo,
    int $tenantId,
    int $peopleTenantId,
    int $rowNumber,
    array $source,
    array $parseErrors
): array {
    $startId = jobdivaAssignmentIdentityNormaliseId((string) ($source['start_id'] ?? ''));
    $identity = $startId !== ''
        ? jobdivaReconciliationFetchExactPlacement($pdo, $tenantId, $startId)
        : ['status' => 'missing', 'ids' => [], 'mapping_ids' => [], 'row' => null];
    $placement = $identity['row'] ?? null;
    $errors = array_values($parseErrors);
    $warnings = [];

    if ($startId === '') $errors[] = 'A non-empty Start ID is required.';
    if ($identity['status'] === 'ambiguous') {
        $errors[] = 'Start ID resolves to multiple CoreFlux placements: ' . implode(', ', $identity['ids']) . '.';
    } elseif ($identity['status'] === 'archived') {
        $errors[] = 'Start ID is bound to an archived placement. This importer will not restore or replace it automatically.';
    } elseif ($identity['status'] === 'broken_mapping') {
        $errors[] = 'Start ID has a broken external mapping whose CoreFlux placement no longer exists.';
    } elseif ($identity['status'] === 'internal_conflict') {
        $errors[] = 'The matching CoreFlux placement is already bound to a different JobDiva Start ID ('
            . ($identity['conflicting_external_id'] ?? 'unknown')
            . '). Rebinding is blocked.';
    }

    $person = jobdivaReconciliationResolvePerson(
        $pdo,
        $tenantId,
        $peopleTenantId,
        $source,
        $placement
    );
    if ($person['error'] && !$placement) $errors[] = $person['error'];
    elseif ($person['error']) $warnings[] = $person['error'] . ' Existing placement person will be retained.';

    $isCreate = $identity['status'] === 'missing';
    if ($isCreate) {
        foreach ([
            'title' => 'Title',
            'start_date' => 'Start Date',
            'engagement_type' => 'Worker Classification',
        ] as $field => $label) {
            if (empty($source[$field])) $errors[] = "{$label} is required to create a missing placement.";
        }
        if (empty($person['id'])) $errors[] = 'A unique existing People row is required to create a missing placement.';
    }

    $overrides = [];
    if ($placement && !empty($placement['coreflux_overridden_fields'])) {
        $decoded = json_decode((string) $placement['coreflux_overridden_fields'], true);
        if (is_array($decoded)) $overrides = array_values(array_map('strval', $decoded));
    }

    $placementPatch = [];
    $changes = [];
    $protected = [];
    $fieldMap = [
        'title' => 'Title',
        'status' => 'Status',
        'engagement_type' => 'Worker Classification',
        'start_date' => 'Start Date',
        'end_date' => 'End Date',
        'end_client_name' => 'End Client',
        'worksite_state' => 'Worksite State',
        'worksite_country' => 'Worksite Country',
        'remote_policy' => 'Remote Policy',
        'recruiter_name' => 'Recruiter Name',
        'recruiter_email' => 'Recruiter Email',
        'account_manager_name' => 'Account Manager Name',
        'account_manager_email' => 'Account Manager Email',
        'client_bill_cycle' => 'Client Billing Frequency',
        'client_payment_terms_override' => 'Client Payment Terms',
        'vendor_pay_cycle' => 'Vendor Payment Frequency',
        'vendor_payment_terms_override' => 'Vendor Payment Terms',
        'vendor_pwp_enabled' => 'Vendor Paid When Paid',
        'notes' => 'Notes',
    ];
    foreach ($fieldMap as $field => $label) {
        if (!array_key_exists($field, $source)) continue;
        $from = $placement[$field] ?? null;
        $to = $source[$field];
        if (!$isCreate && in_array($field, $overrides, true)) {
            if (!jobdivaReconciliationValueEqual($from, $to)) {
                $protected[] = jobdivaReconciliationChange('placement', $field, $label, $from, $to);
            }
            continue;
        }
        if ($isCreate || !jobdivaReconciliationValueEqual($from, $to)) {
            $placementPatch[$field] = $to;
            $changes[] = jobdivaReconciliationChange('placement', $field, $label, $from, $to);
        }
    }
    if (!empty($source['job_id'])) {
        $from = $placement['jobdiva_job_id'] ?? null;
        if ($isCreate || !jobdivaReconciliationValueEqual($from, $source['job_id'])) {
            $placementPatch['jobdiva_job_id'] = $source['job_id'];
            $changes[] = jobdivaReconciliationChange('placement', 'jobdiva_job_id', 'JobDiva Job ID', $from, $source['job_id']);
        }
    }
    if (!empty($person['id'])) {
        $from = $placement['person_id'] ?? null;
        if ($isCreate || (int) $from !== (int) $person['id']) {
            if (!$isCreate && in_array('person_id', $overrides, true)) {
                $protected[] = jobdivaReconciliationChange('placement', 'person_id', 'Person', $from, $person['id']);
            } else {
                $placementPatch['person_id'] = (int) $person['id'];
                $changes[] = jobdivaReconciliationChange('placement', 'person_id', 'Person', $from, $person['id']);
            }
        }
    }
    $canonicalExternalId = $startId !== '' ? 'jd:' . $startId : null;
    if ($canonicalExternalId !== null && ($isCreate || (string) ($placement['external_id'] ?? '') !== $canonicalExternalId)) {
        $placementPatch['external_id'] = $canonicalExternalId;
        $changes[] = jobdivaReconciliationChange(
            'identity',
            'external_id',
            'Canonical Start binding',
            $placement['external_id'] ?? null,
            $canonicalExternalId
        );
    }
    $mappingPresent = !empty($identity['mapping_ids']);
    if (!$mappingPresent && $placement) {
        $changes[] = jobdivaReconciliationChange('identity', 'mapping', 'External mapping', null, "JobDiva Start {$startId}");
    }
    if (!empty($source['end_client_name']) && $placement && empty($placement['end_client_company_id'])) {
        $changes[] = jobdivaReconciliationChange('graph', 'end_client_company_link', 'Canonical company link', null, $source['end_client_name']);
    }

    $ratePlan = null;
    $hasBill = array_key_exists('bill_rate', $source);
    $hasPay = array_key_exists('pay_rate', $source);
    if ($hasBill xor $hasPay) {
        $errors[] = 'Bill Rate and Pay Rate must both be mapped for a rate write; partial economics are blocked.';
    } elseif ($hasBill && $hasPay) {
        $currentRate = $placement ? jobdivaReconciliationFetchRate($pdo, $tenantId, (int) $placement['id']) : null;
        $desiredRate = [
            'effective_from' => $source['start_date'] ?? ($currentRate['effective_from'] ?? ($placement['start_date'] ?? date('Y-m-d'))),
            'effective_to' => array_key_exists('end_date', $source)
                ? $source['end_date']
                : ($currentRate['effective_to'] ?? null),
            'bill_rate' => (float) $source['bill_rate'],
            'pay_rate' => (float) $source['pay_rate'],
            'bill_rate_unit' => $source['bill_rate_unit'] ?? ($currentRate['bill_rate_unit'] ?? 'hour'),
            'pay_rate_unit' => $source['pay_rate_unit'] ?? ($currentRate['pay_rate_unit'] ?? 'hour'),
            'currency' => $source['currency'] ?? ($currentRate['currency'] ?? 'USD'),
        ];
        $rateChanged = $currentRate === null;
        foreach ($desiredRate as $field => $to) {
            $from = $currentRate[$field] ?? null;
            if ($currentRate === null || !jobdivaReconciliationValueEqual($from, $to)) {
                $rateChanged = true;
                $changes[] = jobdivaReconciliationChange('rate', $field, ucwords(str_replace('_', ' ', $field)), $from, $to);
            }
        }
        if ($rateChanged) {
            $ratePlan = [
                'action' => $currentRate && empty($currentRate['approved_at']) ? 'update_draft' : 'create_draft',
                'rate_id' => $currentRate && empty($currentRate['approved_at']) ? (int) $currentRate['id'] : null,
                'approved_snapshot_id' => $currentRate && !empty($currentRate['approved_at']) ? (int) $currentRate['id'] : null,
                'payload' => $desiredRate,
            ];
            if ($ratePlan['action'] === 'create_draft' && $currentRate && !empty($currentRate['approved_at'])) {
                $warnings[] = 'Approved rate snapshot is locked. A new correction draft will be created.';
            }
        }
    }

    if ($isCreate) {
        if (!isset($placementPatch['status'])) {
            $placementPatch['status'] = 'draft';
            $changes[] = jobdivaReconciliationChange('placement', 'status', 'Status', null, 'draft');
        }
    }

    $errors = array_values(array_unique($errors));
    $warnings = array_values(array_unique($warnings));
    $outcome = 'unchanged';
    if ($errors) $outcome = 'blocked';
    elseif ($isCreate) $outcome = 'create';
    elseif ($changes || $ratePlan) $outcome = 'update';

    return [
        'row_number' => $rowNumber,
        'start_id' => $startId,
        'outcome' => $outcome,
        'selectable' => in_array($outcome, ['create','update'], true),
        'placement_id' => $placement ? (int) $placement['id'] : null,
        'placement_title' => $placement['title'] ?? ($source['title'] ?? null),
        'person' => $person,
        'changes' => $changes,
        'protected_changes' => $protected,
        'warnings' => $warnings,
        'errors' => $errors,
        'placement_patch' => $placementPatch,
        'rate_plan' => $ratePlan,
        'source' => $source,
        'mapping_present' => $mappingPresent,
        'is_create' => $isCreate,
    ];
}

function jobdivaReconciliationCanonicalise(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    $out = [];
    foreach ($value as $key => $item) $out[$key] = jobdivaReconciliationCanonicalise($item);
    $isList = array_keys($out) === range(0, count($out) - 1);
    if (!$isList) ksort($out);
    return $out;
}

function jobdivaReconciliationFingerprint(int $tenantId, string $csv, array $columnMap, array $rows): string
{
    $material = [
        'tenant_id' => $tenantId,
        'csv_sha256' => hash('sha256', $csv),
        'column_map' => $columnMap,
        'rows' => $rows,
    ];
    return hash('sha256', json_encode(
        jobdivaReconciliationCanonicalise($material),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ));
}

function jobdivaReconciliationPublicRow(array $row): array
{
    unset($row['placement_patch'], $row['rate_plan'], $row['source']);
    return $row;
}

function jobdivaReconciliationBuildPlan(
    \PDO $pdo,
    int $tenantId,
    int $peopleTenantId,
    string $csv,
    ?array $columnMap
): array {
    $parsed = jobdivaReconciliationParse($csv, $columnMap);
    $rows = [];
    foreach ($parsed['rows'] as $rowNumber => $source) {
        $rows[] = jobdivaReconciliationBuildRowPlan(
            $pdo,
            $tenantId,
            $peopleTenantId,
            (int) $rowNumber,
            $source,
            $parsed['errors'][$rowNumber] ?? []
        );
    }
    if ($parsed['mapping_errors']) {
        foreach ($rows as &$row) {
            $row['errors'] = array_values(array_unique(array_merge($row['errors'], $parsed['mapping_errors'])));
            $row['outcome'] = 'blocked';
            $row['selectable'] = false;
        }
        unset($row);
    }
    $summary = ['rows' => count($rows), 'create' => 0, 'update' => 0, 'unchanged' => 0, 'blocked' => 0];
    foreach ($rows as $row) $summary[$row['outcome']]++;
    $token = jobdivaReconciliationFingerprint($tenantId, $csv, $parsed['column_map'], $rows);
    return [
        'summary' => $summary,
        'rows' => $rows,
        'public_rows' => array_map('jobdivaReconciliationPublicRow', $rows),
        'headers' => $parsed['headers'],
        'column_map' => $parsed['column_map'],
        'mapping_errors' => $parsed['mapping_errors'],
        'dry_run_token' => $token,
        'safety' => [
            'identity' => 'exact_start_id',
            'writes' => 'selected_rows_only',
            'deletes' => false,
            'archives' => false,
            'approved_rates_mutated' => false,
        ],
    ];
}
