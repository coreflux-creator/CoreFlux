<?php
/**
 * /api/admin/mail_outbox_show.php
 *
 * Single mail_outbox row detail. Used by the Mail Health card's
 * "Open in outbox" deep-link so an admin can read the full failed
 * payload + headers without leaving the dashboard.
 *
 *   GET ?id=<mail_outbox.id>
 *
 * Returns the full row minus body_html/body_text by default; pass
 * &include_body=1 to also surface the rendered HTML + plaintext (for
 * forwarding to a customer when their address bounces). PII-scrubbed
 * for non-admins via the existing RBAC gate.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) ($ctx['tenant_id'] ?? 0);
rbac_legacy_require($user, 'tenant_admin.integrations');

if (api_method() !== 'GET') api_error('Method not allowed', 405);

$id          = (int) (api_query('id') ?? 0);
$includeBody = (int) (api_query('include_body') ?? 0) === 1;
if ($id <= 0) api_error('id required', 422);

$pdo = getDB();
if (!$pdo) api_error('DB unavailable', 503);

try {
    $cols = 'id, tenant_id, module, purpose, connection_id, driver,
             to_addresses_json, from_address, reply_to, subject,
             status, provider_message_id, sent_at, error,
             created_by_user_id, created_at,
             body_truncated_at, attachments_json';
    if ($includeBody) {
        $cols .= ', body_text, body_html';
    }
    $st = $pdo->prepare("SELECT {$cols} FROM mail_outbox WHERE id = :id LIMIT 1");
    $st->execute(['id' => $id]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$row)                      api_error('Not found', 404);
    if ((int) $row['tenant_id'] !== $tid) api_error('Not found', 404);

    // Resolve associated webhook events for the message_id, if any.
    $events = [];
    if (!empty($row['provider_message_id'])) {
        try {
            // tenant-leak-allow: provider_message_id is globally unique across tenants (issued by Resend); the outer mail_outbox lookup above already validated tenant ownership of the parent row
            $stE = $pdo->prepare(
                'SELECT id, event_type, signature_verified, received_at, recipient_email
                   FROM mail_webhook_events
                  WHERE message_id = :m
               ORDER BY id ASC
                  LIMIT 20'
            );
            $stE->execute(['m' => (string) $row['provider_message_id']]);
            $events = $stE->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($events as &$e) {
                $e['id'] = (int) $e['id'];
                $e['signature_verified'] = (int) $e['signature_verified'] === 1;
            }
            unset($e);
        } catch (\Throwable $e) {
            // mail_webhook_events table missing — leave $events empty.
        }
    }

    // Decode arrays for the UI.
    $to = json_decode((string) ($row['to_addresses_json'] ?? '[]'), true);
    if (!is_array($to)) $to = [];
    $attachments = json_decode((string) ($row['attachments_json'] ?? 'null'), true);

    $resp = [
        'id'                  => (int) $row['id'],
        'module'              => (string) $row['module'],
        'purpose'             => (string) $row['purpose'],
        'driver'              => (string) $row['driver'],
        'status'              => (string) $row['status'],
        'subject'             => (string) $row['subject'],
        'to'                  => $to,
        'from_address'        => $row['from_address'],
        'reply_to'            => $row['reply_to'],
        'provider_message_id' => $row['provider_message_id'],
        'sent_at'             => $row['sent_at'],
        'error'               => $row['error'],
        'created_at'          => $row['created_at'],
        'body_truncated_at'   => $row['body_truncated_at'],
        'attachments'         => $attachments,
        'webhook_events'      => $events,
    ];
    if ($includeBody) {
        $resp['body_text'] = $row['body_text'];
        $resp['body_html'] = $row['body_html'];
    }
    api_ok($resp);
} catch (\Throwable $e) {
    api_error('Lookup failed: ' . $e->getMessage(), 500);
}
