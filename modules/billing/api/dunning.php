<?php
/**
 * Billing API — Dunning.
 *
 *   GET    /api/billing/dunning?action=queue           — overdue invoices + next-stage info
 *   GET    /api/billing/dunning?action=policy          — load tenant policy
 *   POST   /api/billing/dunning?action=policy          — upsert tenant policy
 *   POST   /api/billing/dunning?action=send_now&id=N   — manual send for one invoice
 *   POST   /api/billing/dunning?action=pause&id=N      — body: {until: 'YYYY-MM-DD'}
 *   POST   /api/billing/dunning?action=resume&id=N
 *   GET    /api/billing/dunning?action=ai_suggest&client=X — escalation heuristic
 *
 * Permissions: read = billing.view, write = billing.invoice.create.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/mail_bootstrap.php';
require_once __DIR__ . '/../../../core/tenant_mail.php';
require_once __DIR__ . '/../lib/dunning.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

if ($method === 'GET' && $action === 'policy') {
    RBAC::requirePermission($user, 'billing.view');
    api_ok(['policy' => billingDunningGetPolicy($tid)]);
}

if ($method === 'POST' && $action === 'policy') {
    RBAC::requirePermission($user, 'billing.invoice.create');
    $body = api_json_body();
    api_require_fields($body, ['schedule']);
    billingDunningSavePolicy($tid, $body, $user['id'] ?? null);
    api_ok(['ok' => true]);
}

if ($method === 'GET' && $action === 'queue') {
    RBAC::requirePermission($user, 'billing.view');
    $today = date('Y-m-d');
    $policy = billingDunningGetPolicy($tid);
    $rows = billingDunningEligibleInvoices($tid, $today);
    $dnc = array_map('strval', (array) ($policy['do_not_contact'] ?? []));
    $out = [];
    foreach ($rows as $inv) {
        $stage = billingDunningPickStage($inv, $policy, $today);
        $isDnc = in_array((string) $inv['client_name'], $dnc, true);
        $cadence = billingDunningWithinCadence($inv, (int) $policy['cadence_days']);
        $recipients = billingDunningResolveRecipients($tid, $inv, (int) $inv['dunning_attempts'] + 1, $policy);

        $out[] = [
            'invoice_id'    => (int) $inv['id'],
            'invoice_number'=> $inv['invoice_number'],
            'client_name'   => $inv['client_name'],
            'amount_due'    => (float) $inv['amount_due'],
            'currency'      => $inv['currency'],
            'due_date'      => $inv['due_date'],
            'days_overdue'  => max(0, (int) round((strtotime($today) - strtotime((string) $inv['due_date'])) / 86400)),
            'attempts'      => (int) $inv['dunning_attempts'],
            'last_sent_at'  => $inv['dunning_last_sent_at'],
            'paused_until'  => $inv['dunning_paused_until'],
            'current_stage' => (int) ($inv['dunning_stage'] ?? 0),
            'next_stage'    => $stage,
            'recipients'    => $recipients,
            'block_reason'  => $isDnc ? 'do_not_contact'
                              : ($recipients['to'] === null ? 'no_contact'
                              : (($cadence) ? 'cadence' : null)),
        ];
    }
    api_ok(['rows' => $out, 'policy' => $policy, 'today' => $today]);
}

if ($method === 'POST' && $action === 'send_now') {
    RBAC::requirePermission($user, 'billing.invoice.create');
    $id  = (int) ($_GET['id'] ?? 0);
    $inv = scopedFind('SELECT * FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$inv) api_error('Not found', 404);
    if ((float) $inv['amount_due'] < 0.005) api_error('Invoice is paid', 409);

    $policy = billingDunningGetPolicy($tid);
    $today  = date('Y-m-d');
    $stage  = billingDunningPickStage($inv, $policy, $today)
            ?? ['stage_no' => (int) ($inv['dunning_stage'] ?? 0) + 1, 'template_key' => 'soft']; // manual fallback

    $recipients = billingDunningResolveRecipients($tid, $inv, (int) $inv['dunning_attempts'] + 1, $policy);
    if (!$recipients['to']) {
        billingDunningRecordSend($tid, $id, $stage, '', [], 'suppressed', 'no recipient resolved');
        api_error('No AR contact found on invoice or client. Add one in Client contacts.', 422);
    }

    $tenant = scopedFind('SELECT * FROM tenants WHERE id = :tenant_id', []) ?: ['name' => 'CoreFlux'];
    $email  = billingDunningRenderEmail((string) $stage['template_key'], $inv, $tenant);
    $sender = cf_tenant_mail_sender($tid, 'billing');
    $svc    = cf_mail_bootstrap();
    try {
        $svc->send($tid, 'billing', "dunning_{$stage['template_key']}", [$recipients['to']],
            $email['subject'], $email['text'], $email['html'], [], [
                'from'      => $sender['from'] ?? null,
                'from_name' => $sender['from_name'] ?? null,
                'reply_to'  => $sender['reply_to'] ?? null,
                'cc'        => $recipients['cc'],
                'idempotency_key' => 'dunning-' . $id . '-' . $stage['stage_no'] . '-' . date('Y-m-d'),
            ]
        );
        billingDunningRecordSend($tid, $id, $stage, $recipients['to'], $recipients['cc'], 'sent');
        api_ok(['ok' => true, 'stage' => $stage, 'recipients' => $recipients]);
    } catch (\Throwable $e) {
        billingDunningRecordSend($tid, $id, $stage, $recipients['to'], $recipients['cc'], 'failed', $e->getMessage());
        api_error('Send failed: ' . $e->getMessage(), 502);
    }
}

if ($method === 'POST' && $action === 'pause') {
    RBAC::requirePermission($user, 'billing.invoice.create');
    $id    = (int) ($_GET['id'] ?? 0);
    $body  = api_json_body();
    $until = (string) ($body['until'] ?? date('Y-m-d', strtotime('+7 days')));
    getDB()->prepare('UPDATE billing_invoices SET dunning_paused_until = :u WHERE tenant_id = :t AND id = :id')
           ->execute(['u' => $until, 't' => $tid, 'id' => $id]);
    api_ok(['ok' => true, 'paused_until' => $until]);
}

if ($method === 'POST' && $action === 'resume') {
    RBAC::requirePermission($user, 'billing.invoice.create');
    $id = (int) ($_GET['id'] ?? 0);
    getDB()->prepare('UPDATE billing_invoices SET dunning_paused_until = NULL WHERE tenant_id = :t AND id = :id')
           ->execute(['t' => $tid, 'id' => $id]);
    api_ok(['ok' => true]);
}

if ($method === 'GET' && $action === 'ai_suggest') {
    RBAC::requirePermission($user, 'billing.view');
    $client = trim((string) ($_GET['client'] ?? ''));
    if ($client === '') api_error('client required', 422);
    $policy = billingDunningGetPolicy($tid);
    $suggestion = billingDunningAiEscalationSuggestion($tid, $client, $policy);
    api_ok(['suggestion' => $suggestion]);
}

api_error('Method/action not allowed', 405);
