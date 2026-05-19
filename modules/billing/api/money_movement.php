<?php
/**
 * Money Movement digest — preview + on-demand send.
 *
 *   GET  /api/billing/money_movement.php[?as_of=YYYY-MM-DD]
 *     → returns snapshot + rendered email preview (read-only).
 *
 *   POST /api/billing/money_movement.php?action=send_now body{as_of?}
 *     → ships the digest right now to all resolved CFO inbox recipients.
 *
 * Both gated by billing.view for preview, admin/manager role for send.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/mail_bootstrap.php';
require_once __DIR__ . '/../../../core/tenant_mail.php';
require_once __DIR__ . '/../lib/money_movement.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

$canSend = function (array $u): bool {
    $role = (string) ($u['role']        ?? '');
    $g    = (string) ($u['global_role'] ?? '');
    return in_array($role, ['admin','manager'], true)
        || in_array($g,    ['master_admin','tenant_admin'], true);
};

if ($method === 'GET') {
    rbac_legacy_require($user, 'billing.view');
    $asOf = (string) ($_GET['as_of'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) api_error('as_of must be YYYY-MM-DD', 422);

    $snapshot   = moneyMovementSnapshot($tid, $asOf);
    $tenant     = scopedFind('SELECT name FROM tenants WHERE id = :tenant_id', []) ?: ['name' => 'CoreFlux'];
    $email      = moneyMovementRenderEmail($snapshot, (string) $tenant['name']);
    $recipients = moneyMovementResolveRecipients(getDB(), $tid);
    api_ok([
        'snapshot'   => $snapshot,
        'email'      => $email,
        'recipients' => $recipients,
    ]);
}

if ($method === 'POST' && $action === 'send_now') {
    if (!$canSend($user)) api_error('Admin/manager role required', 403);
    $body = api_json_body();
    $asOf = (string) ($body['as_of'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) api_error('as_of must be YYYY-MM-DD', 422);

    $snapshot   = moneyMovementSnapshot($tid, $asOf);
    $tenant     = scopedFind('SELECT name FROM tenants WHERE id = :tenant_id', []) ?: ['name' => 'CoreFlux'];
    $recipients = moneyMovementResolveRecipients(getDB(), $tid);
    if (empty($recipients)) api_error('No CFO inbox recipients on file for this tenant.', 422);

    $sender = cf_tenant_mail_sender($tid, 'billing');
    $svc    = cf_mail_bootstrap();
    $sent = 0; $failed = 0; $errors = [];
    foreach ($recipients as $r) {
        if (empty($r['email']) || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) continue;
        $email = moneyMovementRenderEmail($snapshot, (string) $tenant['name'], trim((string) ($r['name'] ?? '')));
        try {
            $svc->send($tid, 'billing', 'money_movement_digest', [$r['email']],
                $email['subject'], $email['text'], $email['html'], [], [
                    'from'      => $sender['from']      ?? null,
                    'from_name' => $sender['from_name'] ?? null,
                    'reply_to'  => $sender['reply_to']  ?? null,
                    'idempotency_key' => "money-mvmt-{$tid}-{$r['id']}-{$asOf}",
                ]
            );
            $sent++;
        } catch (\Throwable $e) { $failed++; $errors[] = "{$r['email']}: " . $e->getMessage(); }
    }
    billingAudit('billing.money_movement.sent', [
        'as_of' => $asOf, 'sent' => $sent, 'failed' => $failed,
        'net'   => ($snapshot['cash_in']['total'] ?? 0) - ($snapshot['cash_out']['total'] ?? 0),
    ]);
    api_ok(['ok' => true, 'sent' => $sent, 'failed' => $failed, 'errors' => $errors, 'as_of' => $asOf]);
}

api_error('Method/action not allowed', 405);
