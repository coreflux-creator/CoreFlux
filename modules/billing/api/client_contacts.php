<?php
/**
 * Billing API — Client AR contacts roster.
 *
 *   GET  /api/billing/client_contacts.php[?q=…]
 *   POST /api/billing/client_contacts.php           (upsert by client_name)
 *   POST /api/billing/client_contacts.php?action=delete&id=N
 *
 * Powers the per-client AR/escalation roster the dunning engine reads from.
 * Permissions: read = billing.view, write = billing.invoice.create.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET') {
    rbac_legacy_require($user, 'billing.view');
    $where = ['tenant_id = :tenant_id'];
    $params = [];
    if (!empty($_GET['q'])) {
        $where[] = 'client_name LIKE :q';
        $params['q'] = '%' . str_replace(['%','_'], ['\\%','\\_'], (string) $_GET['q']) . '%';
    }
    $rows = scopedQuery(
        'SELECT id, client_name, ar_primary_email, ar_escalation_email, notes, updated_at
           FROM billing_client_contacts WHERE ' . implode(' AND ', $where) . ' ORDER BY client_name ASC LIMIT 500',
        $params
    );
    api_ok(['rows' => $rows]);
}

if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'billing.invoice.create');
    $body = api_json_body();
    api_require_fields($body, ['client_name']);
    $name = trim((string) $body['client_name']);
    if ($name === '') api_error('client_name required', 422);
    foreach (['ar_primary_email', 'ar_escalation_email'] as $f) {
        if (!empty($body[$f]) && !filter_var($body[$f], FILTER_VALIDATE_EMAIL)) {
            api_error("invalid {$f}", 422);
        }
    }

    getDB()->prepare(
        'INSERT INTO billing_client_contacts
            (tenant_id, client_name, ar_primary_email, ar_escalation_email, notes, updated_by_user_id)
         VALUES (:t, :c, :p, :e, :n, :u)
         ON DUPLICATE KEY UPDATE
            ar_primary_email   = VALUES(ar_primary_email),
            ar_escalation_email= VALUES(ar_escalation_email),
            notes              = VALUES(notes),
            updated_by_user_id = VALUES(updated_by_user_id)'
    )->execute([
        't' => $tid, 'c' => $name,
        'p' => $body['ar_primary_email']    ?: null,
        'e' => $body['ar_escalation_email'] ?: null,
        'n' => $body['notes'] ?? null,
        'u' => $user['id'] ?? null,
    ]);
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
