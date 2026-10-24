<?php
/**
 * Billing API — Client AR contacts roster.
 *
 *   GET  /api/billing/client_contacts.php[?entity_id=N&q=…]
 *   POST /api/billing/client_contacts.php           (upsert by entity/client)
 *   POST /api/billing/client_contacts.php?action=assign&id=N
 *   GET/POST /api/billing/client_contacts.php?action=sender&entity_id=N
 *   POST /api/billing/client_contacts.php?action=delete&id=N
 *
 * Powers the per-client AR/escalation roster the dunning engine reads from.
 * Permissions: read = billing.view, write = billing.invoice.create.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/entity_delivery.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

if ($action === 'sender' && ($method === 'GET' || $method === 'POST')) {
    rbac_legacy_require($user, $method === 'GET' ? 'billing.view' : 'billing.invoice.create');
    $entityId = (int) ($_GET['entity_id'] ?? 0);
    if (!billingEntityForDelivery($tid, $entityId)) api_error('Select a legal entity in this workspace.', 422);
    if ($method === 'POST') {
        $body = api_json_body();
        $name = trim((string) ($body['from_name'] ?? ''));
        $replyTo = trim((string) ($body['reply_to'] ?? ''));
        if (strlen($name) > 120 || preg_match('/[\r\n<>]/', $name)) api_error('Invalid sender display name.', 422);
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) api_error('Invalid reply-to email.', 422);
        getDB()->prepare('INSERT INTO billing_entity_mail_settings
            (tenant_id, entity_id, from_name, reply_to, updated_by_user_id)
            VALUES (:t, :e, :n, :r, :u)
            ON DUPLICATE KEY UPDATE from_name = VALUES(from_name), reply_to = VALUES(reply_to),
                updated_by_user_id = VALUES(updated_by_user_id)')
            ->execute(['t' => $tid, 'e' => $entityId, 'n' => $name ?: null,
                'r' => $replyTo ?: null, 'u' => $user['id'] ?? null]);
    }
    $st = getDB()->prepare('SELECT from_name, reply_to, updated_at FROM billing_entity_mail_settings
        WHERE tenant_id = :t AND entity_id = :e');
    $st->execute(['t' => $tid, 'e' => $entityId]);
    api_ok(['settings' => $st->fetch(PDO::FETCH_ASSOC) ?: null,
        'sender' => billingEntityMailSender($tid, $entityId)]);
}

if ($method === 'GET' && $action === '') {
    rbac_legacy_require($user, 'billing.view');
    $entityId = (int) ($_GET['entity_id'] ?? 0);
    if ($entityId && !billingEntityForDelivery($tid, $entityId)) api_error('Legal entity not found.', 404);
    $params = ['t' => $tid];
    $search = '';
    if (!empty($_GET['q'])) {
        $search = ' AND client_name LIKE :q';
        $params['q'] = '%' . str_replace(['%','_'], ['\\%','\\_'], (string) $_GET['q']) . '%';
    }
    $selected = $entityId ? ' AND entity_id = :e' : ' AND entity_id IS NOT NULL';
    if ($entityId) $params['e'] = $entityId;
    $st = getDB()->prepare('SELECT id, entity_id, client_name, ar_primary_email,
        ar_escalation_email, notes, updated_at FROM billing_client_contacts
        WHERE tenant_id = :t' . $selected . $search . ' ORDER BY client_name ASC LIMIT 500');
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    unset($params['e']);
    $legacy = getDB()->prepare('SELECT id, entity_id, client_name, ar_primary_email,
        ar_escalation_email, notes, updated_at FROM billing_client_contacts
        WHERE tenant_id = :t AND entity_id IS NULL' . $search . ' ORDER BY client_name ASC LIMIT 500');
    $legacy->execute($params);
    api_ok(['entity_id' => $entityId ?: null, 'rows' => $rows,
        'unassigned' => $legacy->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'POST' && ($action === '' || $action === 'assign')) {
    rbac_legacy_require($user, 'billing.invoice.create');
    $body = api_json_body();
    $entityId = (int) ($body['entity_id'] ?? 0);
    if (!billingEntityForDelivery($tid, $entityId)) api_error('Select a legal entity in this workspace.', 422);
    if ($action === '') api_require_fields($body, ['client_name']);
    $name = trim((string) ($body['client_name'] ?? ''));
    if ($action === '' && $name === '') api_error('client_name required', 422);
    foreach (['ar_primary_email', 'ar_escalation_email'] as $f) {
        if (!empty($body[$f]) && !filter_var($body[$f], FILTER_VALIDATE_EMAIL)) {
            api_error("invalid {$f}", 422);
        }
    }
    if (strlen(trim((string) ($body['notes'] ?? ''))) > 500) api_error('Notes must be 500 characters or less.', 422);
    $values = ['t' => $tid, 'e' => $entityId,
        'p' => trim((string) ($body['ar_primary_email'] ?? '')) ?: null,
        'a' => trim((string) ($body['ar_escalation_email'] ?? '')) ?: null,
        'n' => trim((string) ($body['notes'] ?? '')) ?: null,
        'u' => $user['id'] ?? null];
    if ($action === 'assign') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) api_error('Contact not found.', 404);
        $st = getDB()->prepare('UPDATE billing_client_contacts
            SET entity_id = :e, ar_primary_email = :p, ar_escalation_email = :a,
                notes = :n, updated_by_user_id = :u
            WHERE tenant_id = :t AND id = :id AND entity_id IS NULL');
        try {
            $st->execute($values + ['id' => $id]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') api_error('This legal entity already has a contact for that client.', 409);
            throw $e;
        }
        if ($st->rowCount() !== 1) api_error('Unassigned contact not found or already assigned.', 409);
    } else {
        getDB()->prepare('INSERT INTO billing_client_contacts
            (tenant_id, entity_id, client_name, ar_primary_email, ar_escalation_email, notes, updated_by_user_id)
            VALUES (:t, :e, :c, :p, :a, :n, :u)
            ON DUPLICATE KEY UPDATE ar_primary_email = VALUES(ar_primary_email),
                ar_escalation_email = VALUES(ar_escalation_email), notes = VALUES(notes),
                updated_by_user_id = VALUES(updated_by_user_id)')
            ->execute($values + ['c' => $name]);
    }
    api_ok(['ok' => true]);
}

if ($method === 'POST' && $action === 'delete') {
    rbac_legacy_require($user, 'billing.invoice.create');
    $id = (int) ($_GET['id'] ?? 0);
    getDB()->prepare('DELETE FROM billing_client_contacts WHERE tenant_id = :t AND id = :id')
           ->execute(['t' => $tid, 'id' => $id]);
    api_ok(['ok' => true, 'deleted' => true]);
}

api_error('Method/action not allowed', 405);
