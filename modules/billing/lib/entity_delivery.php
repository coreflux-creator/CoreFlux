<?php
/** Legal-entity identity and recipient/sender resolution for customer-facing billing. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/tenant_mail.php';

function billingEntityForDelivery(int $tenantId, int $entityId): ?array
{
    if ($tenantId <= 0 || $entityId <= 0 || !getDB()) return null;
    $st = getDB()->prepare('SELECT id, legal_name FROM accounting_entities
        WHERE tenant_id = :t AND id = :e AND active = 1 LIMIT 1');
    $st->execute(['t' => $tenantId, 'e' => $entityId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function billingInvoiceDeliveryEntityId(int $tenantId, array $invoice): ?int
{
    $entityId = (int) ($invoice['entity_id'] ?? 0);
    return billingEntityForDelivery($tenantId, $entityId) ? $entityId : null;
}

function billingClientContactForEntity(int $tenantId, int $entityId, string $clientName): ?array
{
    if ($tenantId <= 0 || $entityId <= 0 || trim($clientName) === '' || !getDB()) return null;
    $st = getDB()->prepare('SELECT ar_primary_email, ar_escalation_email
        FROM billing_client_contacts
        WHERE tenant_id = :t AND entity_id = :e AND client_name = :c LIMIT 1');
    $st->execute(['t' => $tenantId, 'e' => $entityId, 'c' => $clientName]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function billingEntityMailSender(int $tenantId, int $entityId): array
{
    $entity = billingEntityForDelivery($tenantId, $entityId);
    if (!$entity) throw new InvalidArgumentException('Select a legal entity in this workspace before sending billing email.');
    $base = cf_tenant_mail_sender($tenantId, 'billing');
    $count = getDB()->prepare('SELECT COUNT(*) FROM accounting_entities WHERE tenant_id = :t AND active = 1');
    $count->execute(['t' => $tenantId]);
    $multiEntity = (int) $count->fetchColumn() > 1;
    $st = getDB()->prepare('SELECT from_name, reply_to FROM billing_entity_mail_settings
        WHERE tenant_id = :t AND entity_id = :e LIMIT 1');
    $st->execute(['t' => $tenantId, 'e' => $entityId]);
    $settings = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $replyTo = trim((string) ($settings['reply_to'] ?? ''));
    if (!$multiEntity && $replyTo === '') $replyTo = trim((string) ($base['reply_to'] ?? ''));
    $base['from_name'] = trim((string) ($settings['from_name'] ?? '')) ?: (string) $entity['legal_name'];
    $base['reply_to'] = $replyTo ?: null;
    $base['entity_id'] = $entityId;
    $base['entity_name'] = $entity['legal_name'];
    $base['ready'] = (bool) ($base['enabled'] ?? true) && (!$multiEntity || $replyTo !== '');
    $base['reason'] = !($base['enabled'] ?? true) ? 'Billing email is disabled in notification settings.'
        : ($multiEntity && $replyTo === '' ? 'Set a reply-to address for this legal entity in Client contacts.' : null);
    return $base;
}
