<?php
/** Compare one verified pilot with its stored source snapshot. No live fetch or writes. */
declare(strict_types=1);
require_once __DIR__ . '/staffing_directory_inventory.php';
require_once __DIR__ . '/../core/jobdiva/assignment_contract.php';
require_once __DIR__ . '/../core/integrations/source_values.php';

function fullCorePilotAlignedValue(mixed $value, string $field): ?string
{
    if ($value === null || !is_scalar($value)) return null;
    $text = trim((string) $value);
    if ($text === '' || strtolower($text) === 'null') return null;
    if ($field === 'worksite_country') return integrationSourceCountry($text);
    if (in_array($field, ['start_date', 'end_date'], true)) {
        foreach (['!Y-m-d', '!m/d/Y', '!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $text);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }
        return null;
    }
    if ($field === 'workers_comp_class' && preg_match('/^[+-]?0+(?:\.0+)?$/D', $text)) return null;
    if ($field === 'engagement_type') return jobdivaClassificationValue($value);
    if ($field === 'status') return strtolower($text);
    return $text;
}

function fullCorePilotSnapshotFieldComparison(array $placement, array $payload, string $expectedStart): array
{
    $contract = jobdivaAssignmentContractFromSnapshot($payload);
    if (!$contract) return ['status'=>'contract_unavailable', 'fields'=>[]];
    if (!is_string($contract['start_id'] ?? null) || $contract['start_id'] !== $expectedStart) {
        return ['status'=>'contract_assignment_mismatch', 'fields'=>[]];
    }
    $fields = [];
    foreach (['branch'=>'branch', 'workers_comp_class'=>'workers_comp_class',
        'worksite_city'=>'worksite_city', 'worksite_state'=>'worksite_state', 'worksite_country'=>'worksite_country',
        'engagement_type'=>'engagement_type', 'start_date'=>'start_date', 'end_date'=>'end_date',
        'status'=>'placement_status'] as $column=>$source) {
        if (!array_key_exists($column, $placement)) { $fields[$column] = 'core_column_unavailable'; continue; }
        $raw = $contract[$source] ?? null;
        // The reviewed assignment adapter keeps JOB division separate from employer.
        // Its documented default supplies branch only when an explicit branch is absent.
        if ($column === 'branch' && ($raw === null || (is_string($raw) && trim($raw) === ''))) {
            $raw = $contract['job_division'] ?? null;
        }
        $value = fullCorePilotAlignedValue($raw, $column);
        $current = fullCorePilotAlignedValue($placement[$column], $column);
        if ($column === 'end_date' && ($contract['end_date_present'] ?? null) === true
            && ($raw === null || (is_string($raw) && trim($raw) === ''))) {
            $fields[$column] = $placement[$column] === null || trim((string)$placement[$column]) === ''
                ? 'matches_explicit_open_end' : 'differs_from_explicit_open_end';
        } elseif ($column === 'engagement_type' && isset($contract['classification_review'])
            && ($contract['classification_review']['status'] ?? null) !== 'resolved') {
            $fields[$column] = 'source_classification_needs_review';
        } elseif ($value === null) {
            $fields[$column] = $raw === null || (is_string($raw) && trim($raw) === '')
                ? 'source_value_missing' : 'source_value_unrecognized';
        } elseif ($current === null) {
            $fields[$column] = $placement[$column] === null || (is_string($placement[$column]) && trim($placement[$column]) === '')
                ? 'core_value_missing' : 'core_value_unrecognized';
        } else {
            $fields[$column] = $current === $value ? 'matches_stored_source' : 'differs_from_stored_source';
        }
    }
    return ['status'=>'compared', 'fields'=>$fields];
}

function fullCoreStaffingPilotAlignment(PDO $pdo, array $pilot): array
{
    $identity = fullCoreStaffingPilotInventory($pdo, $pilot);
    $result = ['status'=>'identity_not_verified',
        'scope'=>'Nine placement dimensions against the stored exact-assignment contract; no raw worker values.',
        'source_refreshed'=>false, 'records_changed'=>false,
        'limits'=>['Stored evidence may be stale.', 'Default source dimensions only; tenant mappings and Core overrides can intentionally differ. No overwrite is proposed.',
            'Employer, rates, custom-field values and downstream delivery require separate evidence.']];
    if ($identity['status'] !== 'observed') return $result;
    foreach (['person','placement','job'] as $entity) {
        if (($identity['jobdiva_identity'][$entity]['status'] ?? null) !== 'exact_match') return $result;
    }
    $s = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:t');
    $s->execute(['t'=>'external_entity_mappings']);
    $mappingColumns = $s->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('payload_snapshot', $mappingColumns, true)) return array_replace($result, ['status'=>'snapshot_schema_unavailable']);
    $s->execute(['t'=>'placements']); $placementColumns = $s->fetchAll(PDO::FETCH_COLUMN);
    $selected = array_values(array_intersect(['branch','workers_comp_class','worksite_city','worksite_state',
        'worksite_country','engagement_type','start_date','end_date','status'], $placementColumns));
    if (!$selected) return array_replace($result, ['status'=>'placement_schema_unavailable']);
    $s = $pdo->prepare('SELECT '.implode(',', array_map(static fn($field)=>'`'.$field.'`', $selected)).'
        FROM placements WHERE id=:id AND tenant_id=:t AND person_id=:p AND deleted_at IS NULL');
    $s->execute(['id'=>$pilot['placement_id'], 't'=>$pilot['tenant_id'], 'p'=>$pilot['person_id']]);
    $placement = $s->fetch(PDO::FETCH_ASSOC);
    if (!$placement) return $result;
    $s = $pdo->prepare('SELECT payload_snapshot'.(in_array('last_synced_at', $mappingColumns, true)?',last_synced_at':'').'
        FROM external_entity_mappings WHERE tenant_id=:t AND source_system="jobdiva"
        AND internal_entity_type="placement" AND internal_entity_id=:id AND BINARY external_id=BINARY :external');
    $s->execute(['t'=>$pilot['tenant_id'],'id'=>$pilot['placement_id'],'external'=>$pilot['jobdiva_ids']['placement']]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1) return $result;
    try { $payload = json_decode((string)$rows[0]['payload_snapshot'], true, 128, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { return array_replace($result, ['status'=>'snapshot_unreadable']); }
    if (!is_array($payload)) return array_replace($result, ['status'=>'snapshot_unavailable']);
    return array_replace($result, fullCorePilotSnapshotFieldComparison($placement, $payload, $pilot['jobdiva_ids']['placement']),
        ['source_last_synced_at'=>$rows[0]['last_synced_at'] ?? null]);
}
