<?php
/**
 * AR statement — batch send to all past-due clients with contacts on file.
 *
 *   POST /api/billing/send_statements_batch.php  body{as_of?, dry_run?}
 *
 * Iterates AR aging rows that have any past-due balance, looks up the
 * client_contacts row, and sends a statement to each. Per-client uses the
 * same entity-aware idempotency key as the singular endpoint, so a batch
 * cannot silently combine legal entities or resend an individual statement.
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

rbac_legacy_require($user, $dryRun ? 'billing.view' : 'billing.invoice.create');
try {
    $entity = billingStatementEntity($tid, $body['entity_id'] ?? null);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 422);
} catch (OutOfBoundsException $e) {
    api_error($e->getMessage(), 404);
}
$entityId = (int) $entity['id'];

$aging = billingComputeAging($tid, $asOf, $entityId);
$report = ['as_of' => $asOf, 'entity_id' => $entityId, 'entity_name' => $entity['legal_name'], 'preview' => $dryRun,
    'attempted' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'rows' => []];

$sender = billingEntityMailSender($tid, $entityId);
$svc    = $dryRun ? null : cf_mail_bootstrap();
$report['sender'] = $sender;

foreach ($aging as $row) {
    $past = (float) ($row['bucket_1_30'] ?? 0)
          + (float) ($row['bucket_31_60'] ?? 0)
          + (float) ($row['bucket_61_90'] ?? 0)
          + (float) ($row['bucket_91_plus'] ?? 0);
    if ($past <= 0.005) continue;
    $report['attempted']++;
    $client = (string) $row['client_name'];

    $recipients = billingStatementResolveRecipients($tid, $entityId, $client);
    if (!$recipients['to']) {
        $report['skipped']++;
        $report['rows'][] = ['client_name' => $client, 'status' => 'skipped', 'reason' => 'no AR contact on file'];
        continue;
    }
    if (!$sender['ready']) {
        $report['skipped']++;
        $report['rows'][] = ['client_name' => $client, 'status' => 'skipped', 'reason' => $sender['reason']];
        continue;
    }
    $invoices = billingStatementOpenInvoices($tid, $client, $asOf, $entityId);
    if (empty($invoices)) {
        $report['skipped']++;
        $report['rows'][] = ['client_name' => $client, 'status' => 'skipped', 'reason' => 'no open invoices at as_of'];
        continue;
    }
    $buckets = billingStatementBucket($invoices);
    if (abs($buckets['total'] - (float) $row['total_due']) > 0.01) {
        $report['failed']++;
        $report['rows'][] = ['client_name' => $client, 'status' => 'failed', 'reason' => 'Statement balance differs from AR aging'];
        continue;
    }
    if ($dryRun) {
        $report['rows'][] = ['client_name' => $client, 'status' => 'would_send', 'to' => $recipients['to'],
            'cc' => $recipients['cc'], 'invoice_count' => count($invoices), 'total_due' => $buckets['total']];
        $report['sent']++;
        continue;
    }
    $email = billingStatementRenderEmail((string) $entity['legal_name'], $client, $invoices, $buckets, $asOf, null, $tid);
    try {
        $sendResult = $svc->send($tid, 'billing', 'ar_statement', [$recipients['to']],
            $email['subject'], $email['text'], $email['html'], [], [
                'from'      => $sender['from']      ?? null,
                'from_name' => $sender['from_name'] ?? null,
                'reply_to'  => $sender['reply_to']  ?? null,
                'cc'        => $recipients['cc'],
                'idempotency_key' => billingStatementIdempotencyKey($tid, $entityId, $client, date('Y-m-d')),
            ]
        );
        if (($sendResult['status'] ?? 'failed') !== 'sent') {
            throw new RuntimeException((string) ($sendResult['error'] ?? 'Delivery provider did not accept the statement.'));
        }
        $report['sent']++;
        $report['rows'][] = ['client_name' => $client, 'status' => 'sent', 'to' => $recipients['to']];
    } catch (\Throwable $e) {
        $report['failed']++;
        $report['rows'][] = ['client_name' => $client, 'status' => 'failed', 'error' => $e->getMessage()];
    }
}
if (!$dryRun) {
    billingAudit('billing.statement.batch_sent', [
        'as_of' => $asOf, 'entity_id' => $entityId, 'attempted' => $report['attempted'],
        'sent'  => $report['sent'], 'skipped' => $report['skipped'], 'failed' => $report['failed'],
    ]);
}
api_ok($report);
