<?php
/**
 * JobDiva -> CoreFlux canonical graph alignment.
 *
 * JobDiva exposes several native facets (Company, Customer, Candidate,
 * Job, Start/Assignment, Contact). CoreFlux workflows should see the
 * canonical product graph instead:
 *
 *   company, contact, person, placement, time_entry
 *
 * Raw native payload mirrors may still be retained for audit/debug, but
 * custom mapping and workflow validation should root in these canonical
 * entity types.
 */
declare(strict_types=1);

function jobdivaCanonicalEntityTypes(): array
{
    return ['placement', 'person', 'company', 'contact', 'time_entry'];
}

function jobdivaCanonicalEntityAliases(): array
{
    return [
        'placement' => [
            'placement', 'assignment', 'start', 'job', 'jobdiva_assignment', 'jobdiva_job',
        ],
        'person' => [
            'person', 'candidate', 'employee', 'worker', 'jobdiva_candidate',
        ],
        'company' => [
            'company', 'customer', 'client', 'end_client', 'jobdiva_customer',
        ],
        'contact' => [
            'contact', 'jobdiva_contact',
        ],
        'time_entry' => [
            'time_entry', 'time', 'timesheet', 'jobdiva_timesheet',
        ],
    ];
}

function jobdivaCanonicalEntityType(string $entityType): string
{
    $normalized = strtolower(trim($entityType));
    foreach (jobdivaCanonicalEntityAliases() as $canonical => $aliases) {
        if (in_array($normalized, $aliases, true)) return $canonical;
    }
    return $normalized;
}

function jobdivaNativeEntityTypesForCanonical(string $canonicalEntityType): array
{
    $canonical = jobdivaCanonicalEntityType($canonicalEntityType);
    return match ($canonical) {
        'placement' => ['placement', 'job', 'assignment', 'jobdiva_job', 'jobdiva_assignment'],
        'person'    => ['person', 'jobdiva_candidate'],
        'company'   => ['company', 'jobdiva_customer'],
        'contact'   => ['contact', 'jobdiva_contact'],
        'time_entry'=> ['time_entry', 'time'],
        default     => [$canonicalEntityType],
    };
}

function jobdivaCanonicalFieldIndexEntityTypes(string $nativeEntityType): array
{
    $canonical = jobdivaCanonicalEntityType($nativeEntityType);
    $out = [$nativeEntityType];
    if ($canonical !== $nativeEntityType) $out[] = $canonical;
    return array_values(array_unique($out));
}

function jobdivaCanonicalFacetNamespace(string $nativeEntityType, string $canonicalEntityType): ?string
{
    $native = strtolower(trim($nativeEntityType));
    $canonical = jobdivaCanonicalEntityType($canonicalEntityType);
    if ($canonical !== 'placement') return null;
    return match ($native) {
        'job', 'jobdiva_job' => 'job',
        'assignment', 'start', 'jobdiva_assignment' => 'assignment',
        default => null,
    };
}

function jobdivaCanonicalPayloadForEntity(string $nativeEntityType, string $targetEntityType, array $payload): array
{
    $namespace = jobdivaCanonicalFacetNamespace($nativeEntityType, $targetEntityType);
    return $namespace === null ? $payload : [$namespace => $payload];
}

function jobdivaCanonicalPlacementPayload(array $payload, array $subPayloads = []): array
{
    $out = $payload;
    $aliases = [
        'person'           => 'person',
        'job'              => 'job',
        'jobdiva_customer' => 'company',
        'contact'          => 'contact',
        'assignment'       => 'assignment',
    ];
    if (!$subPayloads) {
        $nested = [
            '_jd_candidate' => 'person',
            '_jd_job'       => 'job',
            '_jd_customer'  => 'jobdiva_customer',
            '_jd_contact'   => 'contact',
            '_jd_start'     => 'assignment',
        ];
        foreach ($nested as $key => $nativeType) {
            if (isset($payload[$key]) && is_array($payload[$key]) && $payload[$key] !== []) {
                $subPayloads[$nativeType] = $payload[$key];
            }
        }
    }
    foreach ($subPayloads as $nativeType => $subPayload) {
        if (!is_array($subPayload) || $subPayload === []) continue;
        $alias = $aliases[(string) $nativeType] ?? null;
        if ($alias === null) continue;
        if (!isset($out[$alias]) || !is_array($out[$alias])) {
            $out[$alias] = $subPayload;
            continue;
        }
        $out[$alias] = array_replace_recursive($subPayload, $out[$alias]);
    }
    return $out;
}

function jobdivaCanonicalSourcePathForEntity(string $nativeEntityType, string $targetEntityType, string $sourcePath): string
{
    $namespace = jobdivaCanonicalFacetNamespace($nativeEntityType, $targetEntityType);
    if ($namespace === null || $sourcePath === '' || $sourcePath === '$') return $sourcePath;
    return str_starts_with($sourcePath, $namespace . '.') ? $sourcePath : $namespace . '.' . $sourcePath;
}

function jobdivaCanonicalApplyEntityTypes(string $nativeEntityType): array
{
    $canonical = jobdivaCanonicalEntityType($nativeEntityType);
    $out = [$canonical];
    if ($nativeEntityType !== $canonical) $out[] = $nativeEntityType;
    return array_values(array_unique($out));
}

function jobdivaCanonicalGraphCatalog(): array
{
    return [
        'placement' => [
            'label' => 'Placement',
            'core_owner' => 'Placements graph',
            'core_table' => 'placements',
            'jobdiva_facets' => ['Start / Assignment', 'Job context'],
            'identity_rule' => "external_entity_mappings(jobdiva, placement) -> placements.id",
            'consumed_by' => ['time', 'billing', 'AP', 'payroll', 'reporting'],
        ],
        'person' => [
            'label' => 'Person',
            'core_owner' => 'People graph',
            'core_table' => 'people',
            'jobdiva_facets' => ['Candidate', 'Employee'],
            'identity_rule' => "external_entity_mappings(jobdiva, person) -> people.id",
            'consumed_by' => ['placements.person_id', 'time_entries.person_id', 'payroll readiness'],
        ],
        'company' => [
            'label' => 'Company',
            'core_owner' => 'People / Companies graph',
            'core_table' => 'companies',
            'jobdiva_facets' => ['Company', 'Customer / end-client'],
            'identity_rule' => "JobDiva company/customer facets resolve to companies.id; native customer evidence may remain under jobdiva_customer to avoid source-id collisions.",
            'consumed_by' => ['company_contacts', 'placements.end_client_company_id', 'staffing_clients.company_id'],
        ],
        'contact' => [
            'label' => 'Contact',
            'core_owner' => 'Company contacts',
            'core_table' => 'company_contacts',
            'jobdiva_facets' => ['Contact', 'Hiring contact'],
            'identity_rule' => "external_entity_mappings(jobdiva, contact) -> company_contacts.id",
            'consumed_by' => ['client approval', 'sales/account context'],
        ],
        'time_entry' => [
            'label' => 'Time Entry',
            'core_owner' => 'Time graph',
            'core_table' => 'time_entries',
            'jobdiva_facets' => ['Timesheet'],
            'identity_rule' => "external_entity_mappings(jobdiva, time_entry) -> time_entries.id",
            'consumed_by' => ['billing extraction', 'AP extraction', 'payroll runs'],
        ],
    ];
}

function jobdivaCanonicalizePayloadSources(array $sources): array
{
    $out = [];
    foreach ($sources as $src) {
        if (($src['integration'] ?? null) !== 'jobdiva') {
            $out[] = $src;
            continue;
        }
        $canonical = jobdivaCanonicalEntityType((string) ($src['entity_type'] ?? ''));
        if (!in_array($canonical, jobdivaCanonicalEntityTypes(), true)) {
            $out[] = $src;
            continue;
        }
        $key = 'jobdiva|' . $canonical;
        if (!isset($out[$key])) {
            $copy = $src;
            $copy['entity_type'] = $canonical;
            $copy['native_entity_types'] = [];
            $copy['path_count'] = 0;
            $out[$key] = $copy;
        }
        $native = (string) ($src['entity_type'] ?? '');
        if ($native !== '' && !in_array($native, $out[$key]['native_entity_types'], true)) {
            $out[$key]['native_entity_types'][] = $native;
        }
        $out[$key]['path_count'] = (int) ($out[$key]['path_count'] ?? 0) + (int) ($src['path_count'] ?? 0);
        $seenAt = (string) ($src['last_seen_at'] ?? '');
        $curAt = (string) ($out[$key]['last_seen_at'] ?? '');
        if ($seenAt !== '' && ($curAt === '' || strcmp($seenAt, $curAt) > 0)) {
            $out[$key]['last_seen_at'] = $seenAt;
        }
    }
    return array_values($out);
}

function jobdivaCanonicalPayloadFieldIndexList(int $tenantId, string $entityType, int $limit = 500): array
{
    if (!function_exists('integrationPayloadFieldIndexList')) return [];
    $canonical = jobdivaCanonicalEntityType($entityType);
    $merged = [];
    foreach (jobdivaNativeEntityTypesForCanonical($canonical) as $native) {
        $rows = integrationPayloadFieldIndexList($tenantId, 'jobdiva', $native, $limit);
        foreach ($rows as $row) {
            $path = jobdivaCanonicalSourcePathForEntity(
                $native,
                $canonical,
                (string) ($row['source_path'] ?? '')
            );
            if ($path === '') continue;
            $row['source_path'] = $path;
            if (!isset($merged[$path])) {
                $row['source_entity_type'] = $native;
                $row['source_entity_types'] = [$native];
                $merged[$path] = $row;
                continue;
            }
            $merged[$path]['occurrence_count'] = (int) ($merged[$path]['occurrence_count'] ?? 0)
                + (int) ($row['occurrence_count'] ?? 0);
            if (!in_array($native, $merged[$path]['source_entity_types'], true)) {
                $merged[$path]['source_entity_types'][] = $native;
            }
            $seenAt = (string) ($row['last_seen_at'] ?? '');
            $curAt = (string) ($merged[$path]['last_seen_at'] ?? '');
            if ($seenAt !== '' && ($curAt === '' || strcmp($seenAt, $curAt) > 0)) {
                $merged[$path]['last_seen_at'] = $seenAt;
                $merged[$path]['sample_value'] = $row['sample_value'] ?? $merged[$path]['sample_value'] ?? null;
                $merged[$path]['value_type'] = $row['value_type'] ?? $merged[$path]['value_type'] ?? 'string';
            }
        }
    }
    $rows = array_values($merged);
    usort($rows, static function ($a, $b) {
        $ac = (int) ($a['occurrence_count'] ?? 0);
        $bc = (int) ($b['occurrence_count'] ?? 0);
        if ($ac !== $bc) return $bc <=> $ac;
        return strcmp((string) ($a['source_path'] ?? ''), (string) ($b['source_path'] ?? ''));
    });
    return array_slice($rows, 0, max(1, min(2000, $limit)));
}
