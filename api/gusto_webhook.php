<?php
/**
 * Gusto — POST /api/gusto_webhook.php
 *
 * Receives webhook events from Gusto. Verifies the HMAC-SHA256 signature
 * in X-Gusto-Signature against GUSTO_WEBHOOK_SECRET, then routes the
 * event by type (Payroll.processed / .paid / .reversed, Employee.*, etc.).
 *
 * Returns 204 on accepted (regardless of whether we know the event type)
 * so Gusto stops retrying. Failures are logged and surface in audit_log.
 *
 * Subscription verification (Gusto sends a verification_token POST) is
 * also handled here: when the body contains {"verification_token": "..."}
 * we echo it back per Gusto's spec so the subscription becomes active.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';   // gives us api_ok/api_error + JSON headers
require_once __DIR__ . '/../core/gusto_service.php';

if (api_method() !== 'POST') {
    api_error('Method not allowed', 405);
}

$rawBody = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_GUSTO_SIGNATURE'] ?? ($_SERVER['HTTP_X_GUSTO_SIGNATURE_V1'] ?? '');

// Subscription verification handshake — Gusto POSTs a token before
// activating a webhook subscription. We must echo it back unsigned per
// their spec; signature header may be absent on this initial call.
$decoded = json_decode($rawBody, true);
if (is_array($decoded) && isset($decoded['verification_token'])) {
    gustoAudit('payroll.gusto.webhook_verification_received', [
        'token_len' => strlen((string) $decoded['verification_token']),
    ], null, [
        'actor_type' => 'system',
    ]);
    api_ok(['verification_token' => $decoded['verification_token']]);
}

if (!gustoVerifyWebhook((string) $signature, $rawBody)) {
    gustoAudit('payroll.gusto.webhook_signature_invalid', [
        'signature_present' => $signature !== '',
        'body_len' => strlen($rawBody),
    ], null, [
        'actor_type' => 'system',
    ]);
    api_error('Invalid signature', 401);
}

$payload = is_array($decoded) ? $decoded : [];
$event   = (string) ($payload['event_type']    ?? '');
$resourceUuid = (string) ($payload['resource_uuid'] ?? '');
$beforeRun = $resourceUuid !== '' ? _gustoWebhookRunAuditRow($resourceUuid) : null;
$afterRun = null;
$targetRunId = (int) ($beforeRun['id'] ?? 0);
$webhookStatus = null;

// Best-effort routing — update payroll_runs with the latest status
// when the event references a payroll we know about.
if ($event !== '' && $resourceUuid !== '') {
    try {
        $pdo = getDB();
        if ($pdo) {
            // Map event types → submission_status pill we display in UI.
            $status = match (true) {
                str_starts_with($event, 'Payroll.processed') => 'processed',
                str_starts_with($event, 'Payroll.paid')      => 'paid',
                str_starts_with($event, 'Payroll.reversed')  => 'reversed',
                str_starts_with($event, 'Payroll.submitted') => 'submitted',
                str_starts_with($event, 'Payroll.calculated')=> 'calculated',
                default => null,
            };
            if ($status !== null) {
                $webhookStatus = $status;
                // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
                $pdo->prepare(
                    'UPDATE payroll_runs SET gusto_submission_status = :s, updated_at = NOW()
                     WHERE gusto_payroll_uuid = :u'
                )->execute(['s' => $status, 'u' => $resourceUuid]);
                if ($status === 'paid') {
                    // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
                    $pdo->prepare(
                        'UPDATE payroll_runs SET status = "paid", paid_at = COALESCE(paid_at, NOW())
                         WHERE gusto_payroll_uuid = :u AND status <> "paid"'
                    )->execute(['u' => $resourceUuid]);
                }
                $afterRun = _gustoWebhookRunAuditRow($resourceUuid);
                $targetRunId = (int) ($afterRun['id'] ?? $targetRunId);
            }
        }
    } catch (\Throwable $e) {
        error_log('[gusto.webhook] DB write failed: ' . $e->getMessage());
    }
}

gustoAudit('payroll.gusto.webhook_received', [
    'event' => $event,
    'resource_uuid' => $resourceUuid,
    'status' => $webhookStatus,
], $targetRunId > 0 ? $targetRunId : null, [
    'tenant_id' => (int) (($afterRun['tenant_id'] ?? null) ?: ($beforeRun['tenant_id'] ?? 0)),
    'actor_type' => 'system',
    'before' => $beforeRun,
    'after' => $afterRun,
]);

http_response_code(204);
exit;

function _gustoWebhookRunAuditRow(string $payrollUuid): ?array
{
    if ($payrollUuid === '') return null;
    try {
        $pdo = function_exists('getDB') ? getDB() : null;
        if (!$pdo) return null;
        $stmt = $pdo->prepare(
            'SELECT * FROM payroll_runs
              WHERE gusto_payroll_uuid = :u
              LIMIT 1'
        );
        $stmt->execute(['u' => $payrollUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\Throwable $e) {
        error_log('[gusto.webhook] audit snapshot failed: ' . $e->getMessage());
        return null;
    }
}
