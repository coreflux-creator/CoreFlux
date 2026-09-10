<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../core/audit.php';

function staffingClientAudit(int $tenantId, ?int $actorUserId, string $event, ?int $clientId, array $meta = []): void
{
    platformAuditLogWrite($tenantId, $actorUserId, $event, $clientId, $meta, [
        'object_type' => 'staffing_client',
        'source' => 'staffing',
    ]);
}

function staffingClientAuditSnapshot(array $client): array
{
    $allowed = [
        'id', 'company_id', 'name', 'legal_name', 'industry',
        'primary_contact_name', 'primary_contact_email', 'primary_contact_phone',
        'billing_address_line1', 'billing_address_line2', 'billing_city',
        'billing_state', 'billing_postal_code', 'billing_country',
        'payment_terms_days', 'status', 'msa_status', 'msa_executed_at',
        'msa_expires_at', 'notes',
    ];
    return array_intersect_key($client, array_flip($allowed));
}
