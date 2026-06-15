<?php
declare(strict_types=1);

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
        getDB()->prepare(
            'INSERT INTO audit_log
                (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, created_at)
             VALUES
                (:tenant_id, :actor_user_id, :event, :target_id, :meta_json, :ip_address, NOW())'
        )->execute([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'event' => $event,
            'target_id' => $targetId,
            'meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log('[staffing.client.audit] ' . $event . ' failed: ' . $e->getMessage());
    }
}
