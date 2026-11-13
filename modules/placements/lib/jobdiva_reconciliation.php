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
require_once __DIR__ . '/../../../core/integrations/source_values.php';
require_once __DIR__ . '/../../../core/jobdiva/classification.php';

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
            'engagement_type'      => ['label' => 'Engagement Type'],
            'start_date'           => ['label' => 'Start Date'],
            'end_date'             => ['label' => 'End Date'],
            'end_client_name'      => ['label' => 'End Client'],
            'end_client_source_id' => ['label' => 'JobDiva End Client Company ID'],
            'worksite_city'        => ['label' => 'Worksite City'],
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
        'end_client_source_id' => ['jobdiva end client company id', 'end client company id', 'endclientcompanyid'],
        'worksite_city' => ['worksite city', 'working city', 'job city'],
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
    $header = jobdivaClassificationKey($sourceHeader);
    $flags = ['c2c'=>'c2c','corptocorp'=>'c2c','croptocrop'=>'c2c','tcsc2c'=>'c2c','isc2c'=>'c2c',
        'w2'=>'w2','tcsw2'=>'w2','isw2'=>'w2'];
    if (isset($flags[$header])) {
        return jobdivaReconciliationTruthy($value) === true ? $flags[$header] : null;
    }
    return jobdivaClassificationValue($value, in_array($header, ['employmentcategory','employeecategory'], true));

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
    return integrationSourceRateUnit($value);
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
    foreach (['candidate_id','job_id','end_client_source_id'] as $field) {
        if (!isset($out[$field])) continue;
        $out[$field] = jobdivaAssignmentIdentityNormaliseId($out[$field]);
        if (!preg_match('/^\d+$/D', $out[$field])) $errors[] = $field . ': provide the exact digits exported by JobDiva.';
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
    $rawRateBasis = $out + ['bill_rate_raw' => $out['bill_rate'] ?? null, 'pay_rate_raw' => $out['pay_rate'] ?? null];
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
    if (isset($out['worksite_city'])) {
        try { $out['worksite_city'] = integrationSourceWorksiteCity($out['worksite_city']); }
        catch (InvalidArgumentException $e) { $errors[] = $e->getMessage(); }
    }
    if (isset($out['worksite_country'])) {
        $country = integrationSourceCountry($out['worksite_country']);
        if ($country === null) {
            $errors[] = "worksite_country: unrecognized country '{$out['worksite_country']}'";
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
        $currency = integrationSourceCurrency($out['currency']);
        if ($currency === null) $errors[] = "currency: unrecognized code '{$out['currency']}'";
        else $out['currency'] = $currency;
    }
    if (isset($out['bill_rate'], $out['pay_rate'])) {
        $basis = integrationSourceRateBasis($rawRateBasis);
        foreach ($basis['errors'] as $basisError) $errors[] = $basisError;
        if ($basis['errors'] === []) {
            foreach (['bill_rate_unit', 'pay_rate_unit', 'currency'] as $field) $out[$field] = $basis[$field];
        }
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

require_once __DIR__.'/jobdiva_csv_context.php';

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
    array $context,
    int $rowNumber,
    array $source,
    array $parseErrors
): array {
    if(!JobdivaStoredReviewTransaction::active())throw new LogicException('CSV reconciliation requires a protected review.');
    $placementOwner=$context['placement_owner'];
    $startId = jobdivaAssignmentIdentityNormaliseId((string) ($source['start_id'] ?? ''));
    $identity = $startId !== ''
        ? jobdivaReconciliationFetchExactPlacement($pdo, $context, $startId)
        : ['status' => 'missing', 'ids' => [], 'mapping_ids' => [], 'row' => null];
    $placement = $identity['row'] ?? null;
    $errors = array_values($parseErrors);
    $warnings = [];

    if ($startId === '') $errors[] = 'A non-empty Start ID is required.';
    if($identity['status']==='conflict')$errors[]=$identity['error'];
    if($identity['status']==='archived')$errors[]='This exact placement is archived. Use the stored assignment lifecycle review before restoring it.';
    $currentGraph=$placement?jobdivaPlacementProjectionAuditSnapshot($placementOwner,(int)$placement['id'],true):[];
    $overrides=$identity['owned_fields']??[];
    if($placement)$overrides=jobdivaContractOwnedFields($currentGraph,(string)($source['engagement_type']??$placement['engagement_type']));
    $person=jobdivaReconciliationResolvePerson($pdo,$context,$source,$placement,$overrides);
    if($person['error'])$errors[]=$person['error'];
    $company=jobdivaReconciliationCompanyProposal($pdo,$context,$source,$placement,$overrides);
    if($company['error'])$errors[]=$company['error'];

    $isCreate = $identity['status'] === 'missing';
    if ($isCreate) {
        foreach ([
            'title' => 'Title',
            'start_date' => 'Start Date',
            'engagement_type' => 'Engagement Type',
        ] as $field => $label) {
            if (empty($source[$field])) $errors[] = "{$label} is required to create a missing placement.";
        }
        if (empty($person['id'])) $errors[] = 'A unique existing People row is required to create a missing placement.';
    }

    $placementPatch = [];
    $changes = [];
    $protected = [];
    if(isset($source['status'])&&$source['status']!=='draft'){
        $source['status']=jobdivaAssignmentPlacementStatusForWrite($source['status'],
            (string)($source['start_date']??$placement['start_date']??''),
            $source['end_date']??$placement['end_date']??null,$placement['status']??null)['status'];
    }
    if($isCreate){
        $q=$pdo->prepare('SELECT id,code,active FROM accounting_entities WHERE tenant_id=? AND active=1 ORDER BY id LIMIT 1 FOR UPDATE');$q->execute([$context['accounting_owner']]);$entity=$q->fetch(PDO::FETCH_ASSOC);
        if(!$entity)$errors[]='Set up an active legal entity in this workspace before importing an assignment.';
        else $placementPatch['accounting_entity_id']=(int)$entity['id'];
    }
    $fieldMap = [
        'title' => 'Title',
        'status' => 'Status',
        'engagement_type' => 'Engagement Type',
        'start_date' => 'Start Date',
        'end_date' => 'End Date',
        'end_client_name' => 'End Client',
        'worksite_city' => 'Worksite City',
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
        if($from!==null&&$from!==''&&(string)$from!==$source['job_id'])$errors[]='Review the existing assignment job link before changing its JobDiva job ID.';
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
    if ($canonicalExternalId !== null && $isCreate) {
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
    if($company['requested']&&!$company['held']&&!$company['error']){
        unset($placementPatch['end_client_name']);
        $changes=array_values(array_filter($changes,static fn($c)=>$c['field']!=='end_client_name'));
        foreach(['end_client_company_id'=>$company['id'],'end_client_name'=>$company['name']]as$field=>$value){
            if($company['create']||!jobdivaReconciliationValueEqual($placement[$field]??null,$value)){
                $placementPatch[$field]=$value;
                $changes[]=jobdivaReconciliationChange('placement',$field,$field,$placement[$field]??null,$value);
            }
        }
    }
    $currentRate=$placement?jobdivaReconciliationFetchRate($pdo,$placementOwner,(int)$placement['id']):null;

    $ratePlan = null;
    $hasBill = array_key_exists('bill_rate', $source);
    $hasPay = array_key_exists('pay_rate', $source);
    if ($hasBill xor $hasPay) {
        $errors[] = 'Bill Rate and Pay Rate must both be mapped for a rate write; partial economics are blocked.';
    } elseif ($hasBill && $hasPay) {
        $basis = integrationSourceRateBasis($source);
        foreach ($basis['errors'] as $basisError) $errors[] = $basisError;
        if ($basis['bill_rate_unit'] !== 'hour' || $basis['pay_rate_unit'] !== 'hour') {
            $errors[] = 'Automatic JobDiva economics require verified hourly bill/pay rates; other bases need a reviewed conversion.';
        }
        $desiredRate = [
            'effective_from' => $source['start_date'] ?? ($currentRate['effective_from'] ?? ($placement['start_date'] ?? date('Y-m-d'))),
            'effective_to' => array_key_exists('end_date', $source)
                ? $source['end_date']
                : ($currentRate['effective_to'] ?? null),
            'bill_rate' => (float) $source['bill_rate'],
            'pay_rate' => (float) $source['pay_rate'],
            'bill_rate_unit' => $basis['bill_rate_unit'],
            'pay_rate_unit' => $basis['pay_rate_unit'],
            'currency' => $basis['currency'],
        ];
        $rateChanged = $currentRate === null;
        foreach ($desiredRate as $field => $to) {
            $from = $currentRate[$field] ?? null;
            $ownershipField=match($field){'effective_from'=>'start_date','effective_to'=>'end_date',default=>$field};
            if(in_array($ownershipField,$overrides,true)){
                if(!jobdivaReconciliationValueEqual($from,$to))$protected[]=jobdivaReconciliationChange('rate',$field,$field,$from,$to);
                if($currentRate)$desiredRate[$field]=$from;
                else $errors[]='Review Core-owned rate fields before creating a new source rate.';
                continue;
            }
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
        '__review_evidence'=>['context'=>$context,'identity'=>$identity,'graph'=>$currentGraph,'rate'=>$currentRate,'candidate'=>$person['evidence'],'company'=>$company],
        '__company'=>$company,
        '__mapping'=>$identity['mapping']??null,
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
    unset($row['placement_patch'], $row['rate_plan'], $row['source'], $row['__review_evidence'], $row['__company'], $row['__mapping'], $row['person']['evidence']);
    return $row;
}

function jobdivaReconciliationBuildPlan(
    \PDO $pdo,
    int $tenantId,
    ?int $peopleTenantId,
    string $csv,
    ?array $columnMap
): array {
    require_once __DIR__.'/../../../core/jobdiva/sync.php';
    if($pdo!==getDB())throw new LogicException('CSV review must use the application database.');
    if(!JobdivaStoredReviewTransaction::active())return JobdivaStoredReviewTransaction::run(static fn()=>jobdivaReconciliationBuildPlan($pdo,$tenantId,$peopleTenantId,$csv,$columnMap),true);
    $context=jobdivaPlacementWriteContext($pdo,$tenantId);
    if($peopleTenantId!==null&&$peopleTenantId!==$context['people_owner'])throw new RuntimeException('The People directory changed. Refresh the CSV preview.',409);
    $q=$pdo->prepare('SELECT tenant_id,module_key,scope_mode FROM tenant_module_scope WHERE tenant_id IN(?,?) ORDER BY tenant_id,module_key FOR UPDATE');$q->execute([$tenantId,$context['placement_owner']]);$policies=$q->fetchAll(PDO::FETCH_ASSOC);
    $parsed = jobdivaReconciliationParse($csv, $columnMap);
    $rows = [];
    foreach ($parsed['rows'] as $rowNumber => $source) {
        $rows[] = jobdivaReconciliationBuildRowPlan(
            $pdo,
            $tenantId,
            $context,
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
    $token = jobdivaReconciliationFingerprint($tenantId, $csv, $parsed['column_map'], ['context'=>$context,'policies'=>$policies,'rows'=>$rows]);
    return [
        '__context'=>$context,
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
