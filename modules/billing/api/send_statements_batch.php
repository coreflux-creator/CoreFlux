<?php
/**
 * AR statement — batch send to all past-due clients with contacts on file.
 *
 *   POST /api/billing/send_statements_batch.php  body{as_of?, dry_run?}
 *
 * Iterates AR aging rows that have any past-due balance, looks up the
 * client_contacts row, and sends a statement to each. Per-client uses the
 * same idempotency key as the singular send_statement endpoint so a
 * tenant running the batch on a day the individual button was already
 * clicked for a given client won't double-send.
 *
 * Returns a per-client report: sent / skipped (no contact) / failed.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/mail_bootstrap.php';
require_once __DIR__ . '/../../../core/tenant_mail.php';
require_once __DIR__ . '/../lib/statement.php';
require_once __DIR__ . '/../lib/billing.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();
if ($method !== 'POST') api_error('Method not allowed', 405);

$body   = api_json_body();
$asOf   = (string) ($body['as_of'] ?? date('Y-m-d'));
$dryRun = !empty($body['dry_run']);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) api_error('as_of must be YYYY-MM-DD', 422);

RBAC::requirePermission($user, $dryRun ? 'billing.view' : 'billing.invoice.create');

$aging = billingComputeAging($tid, $asOf);
$report = ['as_of' => $asOf, 'attempted' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'rows' => []];

$tenant = scopedFind('SELECT name FROM tenants WHERE id = :tenant_id', []) ?: ['name' => 'CoreFlux'];
$sender = $dryRun ? null : cf_tenant_mail_sender($tid, 'billing');
$svc    = $dryRun ? null : cf_mail_bootstrap();

foreach ($aging as $row) {
    $past = (float) ($row['bucket_1_30'] ?? 0)
          + (float) ($row['bucket_31_60'] ?? 0)
          + (float) ($row['bucket_61_90'] ?? 0)
          + (float) ($row['bucket_91_plus'] ?? 0);
    if ($past <= 0.005) continue;
    $report['attempted']++;
    $client = (string) $row['client_name'];

    $recipients = billingStatementResolveRecipients($tid, $client);
    if (!$recipients['to']) {
        $report['skipped']++;
        $report['rows'][] = ['client_name' => $client, 'status' => 'skipped', 'reason' => 'no AR contact on file'];
        continue;
    }
    if ($dryRun) {
        $report['rows'][] = ['client_name' => $client, 'status' => 'would_send', 'to' => $recipients['to'], 'cc' => $recipients['cc']];
        $report['sent']++;
        continue;
    }
    $invoices = billingStatementOpenInvoices($tid, $client, $asOf);
    if (empty($invoices)) {
        $report['skipped']++;
        $report['rows'][] = ['client_name' => $client, 'status' => 'skipped', 'reason' => 'no open invoices at as_of'];
        continue;
    }
    $buckets = billingStatementBucket($invoices);
    $email   = billingStatementRenderEmail((string) $tenant['name'], $client, $invoices, $buckets, $asOf, null, $tid);
    $slug    = preg_replace('/[^a-z0-9]+/', '-', strtolower($client)) ?: 'client';
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
        $report['sent']++;
        $report['rows'][] = ['client_name' => $client, 'status' => 'sent', 'to' => $recipients['to']];
    } catch (\Throwable $e) {
        $report['failed']++;
        $report['rows'][] = ['client_name' => $client, 'status' => 'failed', 'error' => $e->getMessage()];
    }
}
if (!$dryRun) {
    billingAudit('billing.statement.batch_sent', [
        'as_of' => $asOf, 'attempted' => $report['attempted'],
        'sent'  => $report['sent'], 'skipped' => $report['skipped'], 'failed' => $report['failed'],
    ]);
}
api_ok($report);
