<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../core/audit.php';

function staffingClientAuditSnapshot(array $row): array
{
    $keys = [
        'id',
        'name',
        'legal_name',
        'industry',
        'primary_contact_name',
        'primary_contact_email',
        'primary_contact_phone',
        'billing_city',
        'billing_state',
        'billing_country',
        'payment_terms_days',
        'status',
        'msa_status',
        'msa_executed_at',
        'msa_expires_at',
    ];
    $out = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) $out[$key] = $row[$key];
    }
    return $out;
}

function staffingClientAudit(int $tenantId, ?int $actorUserId, string $event, ?int $targetId, array $meta): void
{
    try {
        platformAuditLogWrite(
            $tenantId,
            $actorUserId,
            $event,
            $targetId,
            $meta,
            [
                'source' => 'staffing',
                'object_type' => 'staffing_client',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    } catch (\Throwable $e) {
        error_log('[staffing.client.audit] ' . $event . ' failed: ' . $e->getMessage());
    }
}
