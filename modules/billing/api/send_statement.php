<?php
/**
 * Billing API — Email an AR statement of open invoices to a client.
 *
 *   POST /api/billing/send_statement.php       body: {client_name, as_of?, dry_run?}
 *   GET  /api/billing/send_statement.php       ?client_name=…&as_of=…    (preview only)
 *
 * Preview returns the rendered subject/html/text + resolved recipients
 * without sending. POST actually sends via the tenant Resend pipeline.
 *
 * Idempotency: keyed `statement-{tenant}-{client_slug}-{Y-m-d}` so two
 * clicks within the same day do not result in two emails to the client.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/mail_bootstrap.php';
require_once __DIR__ . '/../../../core/tenant_mail.php';
require_once __DIR__ . '/../lib/statement.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();

if ($method !== 'GET' && $method !== 'POST') api_error('Method not allowed', 405);

$RBAC_PREVIEW = 'billing.view';
$RBAC_SEND    = 'billing.invoice.create';

$body       = $method === 'POST' ? api_json_body() : [];
$clientName = trim((string) ($body['client_name'] ?? $_GET['client_name'] ?? ''));
$asOf       = (string) ($body['as_of'] ?? $_GET['as_of'] ?? date('Y-m-d'));
$dryRun     = $method === 'GET' || !empty($body['dry_run']);

if ($clientName === '') api_error('client_name required', 422);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) api_error('as_of must be YYYY-MM-DD', 422);

rbac_legacy_require($user, $dryRun ? $RBAC_PREVIEW : $RBAC_SEND);

$invoices = billingStatementOpenInvoices($tid, $clientName, $asOf);
if (empty($invoices)) {
    api_error("Nothing outstanding for \"{$clientName}\" as of {$asOf}.", 409);
}
$buckets    = billingStatementBucket($invoices);
$recipients = billingStatementResolveRecipients($tid, $clientName);
$tenant     = scopedFind('SELECT name FROM tenants WHERE id = :tenant_id', []) ?: ['name' => 'CoreFlux'];
$email      = billingStatementRenderEmail((string) $tenant['name'], $clientName, $invoices, $buckets, $asOf);

if ($dryRun) {
    api_ok([
        'preview'    => true,
        'client'     => $clientName,
        'as_of'      => $asOf,
        'buckets'    => $buckets,
        'invoices'   => $invoices,
        'email'      => $email,
        'recipients' => $recipients,
    ]);
}

if (!$recipients['to']) {
    api_error('No AR contact on file for this client. Add one in Client contacts and retry.', 422);
}

$sender = cf_tenant_mail_sender($tid, 'billing');
$svc    = cf_mail_bootstrap();
$slug   = preg_replace('/[^a-z0-9]+/', '-', strtolower($clientName)) ?? 'client';
try {
    $svc->send($tid, 'billing', 'ar_statement', [$recipients['to']],
        $email['subject'], $email['text'], $email['html'], [], [
            'from'      => $sender['from']      ?? null,
            'from_name' => $sender['from_name'] ?? null,
            'reply_to'  => $sender['reply_to']  ?? null,
            'cc'        => $recipients['cc'],
            'idempotency_key' => "statement-{$tid}-{$slug}-" . date('Y-m-d'),
        ]
    );
} catch (\Throwable $e) {
    api_error('Send failed: ' . $e->getMessage(), 502);
}

billingAudit('billing.statement.sent', [
    'client_name'    => $clientName,
    'as_of'          => $asOf,
    'invoice_count'  => count($invoices),
    'total_due'      => $buckets['total'],
    'sent_to'        => $recipients['to'],
    'cc'             => $recipients['cc'],
]);

api_ok([
    'ok'         => true,
    'sent_to'    => $recipients['to'],
    'cc'         => $recipients['cc'],
    'count'      => count($invoices),
    'total_due'  => $buckets['total'],
]);
