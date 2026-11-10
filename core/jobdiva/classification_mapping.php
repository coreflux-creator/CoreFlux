<?php
declare(strict_types=1);
require_once __DIR__ . '/classification.php';
require_once __DIR__ . '/contract_projection.php';
require_once __DIR__ . '/../integrations/field_map.php';

/** A tenant's explicit source-field mapping may fill a gap, never override assignment evidence. */
function jobdivaClassificationPayloadForTenant(int $tenantId, array $payload): array
{
    unset($payload['__cf_classification_review']);
    $contract = jobdivaContractProjectionContract($payload);
    if (($contract['classification_review']['source'] ?? '') === 'mapped_source') {
        // Re-read the mapping and value on every preview/apply. Cached mapping evidence is not authority.
        unset($contract['classification_review'], $contract['engagement_type']);
    }
    $payload['_jd_contract'] = $contract;
    $review = jobdivaClassificationFromPayload($payload);
    if (!$review['has_evidence']) {
        $statement = getDB()->prepare('SELECT id,source_path,external_field,transform,updated_at FROM tenant_integration_field_map
            WHERE tenant_id=:t AND integration="jobdiva" AND entity_type="placement" AND internal_field="engagement_type" AND enabled=1
              AND (target_table IS NULL OR (target_table="placements" AND target_column="engagement_type")) LIMIT 1');
        $statement->execute(['t'=>$tenantId]);
        $mapping = $statement->fetch(PDO::FETCH_ASSOC);
        if ($mapping) {
            $path = trim((string) ($mapping['source_path'] ?: $mapping['external_field']));
            $raw = tenantIntegrationFieldMapPluckPath($payload, $path);
            if (!tenantIntegrationFieldMapValueIsPresent($raw) && $mapping['external_field'] !== $path) {
                $path = (string) $mapping['external_field'];
                $raw = tenantIntegrationFieldMapPluckPath($payload, $path);
            }
            $value = tenantIntegrationFieldMapApplyTransform($raw, (string) $mapping['transform']);
            $review = jobdivaClassificationReview([['key'=>'engagement_type','path'=>$path,'value'=>$value]], 'mapped_source');
            $review['mapping_id'] = (int) $mapping['id'];
            $review['mapping_revision'] = hash('sha256', json_encode($mapping, JSON_THROW_ON_ERROR));
            $review['source_value'] = $raw;
        }
    }
    if ($contract !== []) {
        $contract['classification_review'] = $review;
        $contract['engagement_type'] = $review['engagement_type'] ?? '';
        $payload['_jd_contract'] = $contract;
    } else {
        // Classification evidence does not fabricate an exact financial contract.
        $payload['__cf_classification_review'] = $review;
        unset($payload['_jd_contract']);
    }
    return $payload;
}
