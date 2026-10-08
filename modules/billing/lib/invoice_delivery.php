<?php
/** Durable, tenant-scoped invoice email attempts on the shared Billing source. */
declare(strict_types=1);

require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/entity_delivery.php';

function billingDeliveryRequestId(string $value): string
{
    $id = strtolower(trim($value));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
        throw new InvalidArgumentException('A unique delivery request ID is required. Refresh the invoice and try again.');
    }
    return $id;
}

function billingDeliveryLockInvoice(PDO $pdo, int $tenantId, int $invoiceId): array
{
    $stmt = $pdo->prepare('SELECT * FROM billing_invoices
                            WHERE tenant_id = :t AND id = :i FOR UPDATE');
    $stmt->execute(['t' => $tenantId, 'i' => $invoiceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new OutOfBoundsException('Invoice not found.');
    return $row;
}

function billingDeliveryAssertPosted(PDO $pdo, int $tenantId, array $invoice): void
{
    if (!empty($invoice['opening_cutover_id'])
        || !in_array($invoice['status'], ['approved', 'sent', 'partially_paid', 'paid'], true)) {
        throw new DomainException('Only a posted, approved invoice can be sent.');
    }
    $stmt = $pdo->prepare('SELECT entity_id FROM accounting_journal_entries
                            WHERE tenant_id = :t AND id = :i AND status = "posted"');
    $stmt->execute(['t' => $tenantId, 'i' => (int) ($invoice['journal_entry_id'] ?? 0)]);
    $journalEntityId = (int) ($stmt->fetchColumn() ?: 0);
    $entityId = billingInvoiceDeliveryEntityId($tenantId, $invoice);
    if (!$entityId || $journalEntityId !== $entityId) {
        throw new DomainException('Invoice legal entity does not match its posted journal.');
    }
}

function billingDeliveryBlockingAttempt(PDO $pdo, int $tenantId, int $invoiceId): ?array
{
    $stmt = $pdo->prepare('SELECT id, delivery_status FROM billing_invoice_tokens
                            WHERE tenant_id = :t AND invoice_id = :i
                              AND delivery_status IN ("pending", "uncertain")
                            ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $stmt->execute(['t' => $tenantId, 'i' => $invoiceId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function billingDeliveryWasSent(PDO $pdo, int $tenantId, int $invoiceId, array $invoice): bool
{
    if (!empty($invoice['sent_at']) || $invoice['status'] === 'sent') return true;
    $stmt = $pdo->prepare('SELECT id FROM billing_invoice_tokens
                            WHERE tenant_id = :t AND invoice_id = :i
                              AND delivery_status = "sent"
                            ORDER BY id DESC LIMIT 1 FOR UPDATE');
    $stmt->execute(['t' => $tenantId, 'i' => $invoiceId]);
    return (bool) $stmt->fetchColumn();
}

/** Reserve before calling any mail driver, so concurrent requests see pending state. */
function billingDeliveryReserve(
    int $tenantId, int $invoiceId, string $requestId, string $recipient, bool $resend
): array {
    $requestId = billingDeliveryRequestId($requestId);
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Choose a valid invoice recipient.');
    }
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $invoice = billingDeliveryLockInvoice($pdo, $tenantId, $invoiceId);

        $prior = $pdo->prepare('SELECT id, delivery_status, delivery_recipient
                                 FROM billing_invoice_tokens
                                WHERE tenant_id = :t AND invoice_id = :i
                                  AND delivery_request_id = :r LIMIT 1 FOR UPDATE');
        $prior->execute(['t' => $tenantId, 'i' => $invoiceId, 'r' => $requestId]);
        $existing = $prior->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (strcasecmp((string) $existing['delivery_recipient'], $recipient) !== 0) {
                throw new DomainException('This delivery request was already used for a different recipient.');
            }
            $pdo->commit();
            return ['replayed' => true, 'token_id' => (int) $existing['id'],
                'delivery_status' => $existing['delivery_status']];
        }

        billingDeliveryAssertPosted($pdo, $tenantId, $invoice);

        if (billingDeliveryBlockingAttempt($pdo, $tenantId, $invoiceId)) {
            throw new DomainException('An earlier invoice email needs delivery review before another can be sent.');
        }
        $wasSent = billingDeliveryWasSent($pdo, $tenantId, $invoiceId, $invoice);
        if (!$wasSent && $resend) {
            throw new DomainException('This invoice has not been sent yet. Send it normally.');
        }
        if ($wasSent && !$resend) {
            throw new DomainException('This invoice was already sent. Confirm that you want to resend it.');
        }

        $token = billingIssueViewToken($tenantId, $invoiceId);
        $mark = $pdo->prepare('UPDATE billing_invoice_tokens
                                 SET delivery_request_id = :r, delivery_status = "pending",
                                     delivery_recipient = :recipient, delivery_started_at = NOW()
                               WHERE tenant_id = :t AND invoice_id = :i AND id = :token_id');
        $mark->execute(['r' => $requestId, 'recipient' => $recipient,
            't' => $tenantId, 'i' => $invoiceId, 'token_id' => $token['token_id']]);
        $pdo->commit();
        return $token + ['replayed' => false, 'delivery_status' => 'pending'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Provider acceptance and invoice/link rotation commit together. */
function billingDeliveryFinalize(
    int $tenantId, int $invoiceId, int $tokenId, string $requestId,
    ?string $providerMessageId, ?int $actorUserId
): int {
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $invoice = billingDeliveryLockInvoice($pdo, $tenantId, $invoiceId);
        billingDeliveryAssertPosted($pdo, $tenantId, $invoice);
        $tokenStmt = $pdo->prepare('SELECT id FROM billing_invoice_tokens
                                    WHERE tenant_id = :t AND invoice_id = :i AND id = :token_id
                                      AND delivery_request_id = :r AND delivery_status = "pending"
                                      AND revoked_at IS NULL FOR UPDATE');
        $tokenStmt->execute(['t' => $tenantId, 'i' => $invoiceId,
            'token_id' => $tokenId, 'r' => $requestId]);
        if (!$tokenStmt->fetchColumn()) {
            throw new RuntimeException('Invoice delivery changed while the provider was responding.');
        }
        $pdo->prepare('UPDATE billing_invoices
                          SET status = CASE WHEN status = "approved" THEN "sent" ELSE status END,
                              sent_at = COALESCE(sent_at, NOW())
                        WHERE tenant_id = :t AND id = :i')
            ->execute(['t' => $tenantId, 'i' => $invoiceId]);
        $revoked = billingRevokeInvoiceViewTokens($tenantId, $invoiceId, $actorUserId, $tokenId);
        $pdo->prepare('UPDATE billing_invoice_tokens
                          SET delivery_status = "sent", delivery_provider_id = :provider_id,
                              delivery_error = NULL, delivery_finished_at = NOW()
                        WHERE tenant_id = :t AND invoice_id = :i AND id = :token_id')
            ->execute(['provider_id' => $providerMessageId !== null ? substr($providerMessageId, 0, 255) : null,
                't' => $tenantId, 'i' => $invoiceId, 'token_id' => $tokenId]);
        $pdo->commit();
        return $revoked;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function billingDeliveryMarkUncertain(
    int $tenantId, int $invoiceId, int $tokenId, string $requestId, string $error
): void {
    getDB()->prepare('UPDATE billing_invoice_tokens
                         SET delivery_status = "uncertain", delivery_error = :error,
                             delivery_finished_at = NOW()
                       WHERE tenant_id = :t AND invoice_id = :i AND id = :token_id
                         AND delivery_request_id = :r AND delivery_status = "pending"')
        ->execute(['error' => substr($error, 0, 500), 't' => $tenantId, 'i' => $invoiceId,
            'token_id' => $tokenId, 'r' => $requestId]);
}

/** A human may resolve ambiguity only after checking the mail provider. */
function billingDeliveryResolve(
    int $tenantId, int $invoiceId, int $tokenId, string $outcome,
    ?string $providerMessageId, ?int $actorUserId
): int {
    if (!in_array($outcome, ['accepted', 'not_accepted'], true)) {
        throw new InvalidArgumentException('Choose whether the provider accepted the email.');
    }
    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $invoice = billingDeliveryLockInvoice($pdo, $tenantId, $invoiceId);
        $stmt = $pdo->prepare('SELECT id, delivery_status, revoked_at,
                                     (expires_at IS NULL OR expires_at > NOW()) AS link_not_expired,
                                     TIMESTAMPDIFF(SECOND, delivery_started_at, NOW()) AS age_seconds
                                FROM billing_invoice_tokens
                               WHERE tenant_id = :t AND invoice_id = :i AND id = :token_id
                                 AND delivery_request_id IS NOT NULL FOR UPDATE');
        $stmt->execute(['t' => $tenantId, 'i' => $invoiceId, 'token_id' => $tokenId]);
        $token = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$token || !in_array($token['delivery_status'], ['pending', 'uncertain'], true)
            || ($token['delivery_status'] === 'pending' && (int) $token['age_seconds'] < 120)) {
            throw new DomainException('This delivery is still processing or was already resolved.');
        }
        if ($outcome === 'accepted') {
            billingDeliveryAssertPosted($pdo, $tenantId, $invoice);
            if ($token['revoked_at'] !== null || !(bool) $token['link_not_expired']) {
                throw new DomainException('The customer link is no longer active. Escalate this delivery for review.');
            }
            $pdo->prepare('UPDATE billing_invoices
                              SET status = CASE WHEN status = "approved" THEN "sent" ELSE status END,
                                  sent_at = COALESCE(sent_at, NOW())
                            WHERE tenant_id = :t AND id = :i')
                ->execute(['t' => $tenantId, 'i' => $invoiceId]);
            $revoked = billingRevokeInvoiceViewTokens($tenantId, $invoiceId, $actorUserId, $tokenId);
            $pdo->prepare('UPDATE billing_invoice_tokens
                              SET delivery_status = "sent", delivery_provider_id = :provider_id,
                                  delivery_error = NULL, delivery_finished_at = NOW()
                            WHERE tenant_id = :t AND invoice_id = :i AND id = :token_id')
                ->execute(['provider_id' => $providerMessageId !== null ? substr($providerMessageId, 0, 255) : null,
                    't' => $tenantId, 'i' => $invoiceId, 'token_id' => $tokenId]);
        } else {
            $pdo->prepare('UPDATE billing_invoice_tokens
                              SET delivery_status = "failed", revoked_at = COALESCE(revoked_at, NOW()),
                                  revoked_by_user_id = :actor, delivery_finished_at = NOW()
                            WHERE tenant_id = :t AND invoice_id = :i AND id = :token_id')
                ->execute(['actor' => $actorUserId, 't' => $tenantId,
                    'i' => $invoiceId, 'token_id' => $tokenId]);
            $revoked = 0;
        }
        $pdo->commit();
        return $revoked;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
