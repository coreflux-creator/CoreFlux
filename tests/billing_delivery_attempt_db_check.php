<?php
/** Exercise durable invoice delivery state in an isolated disposable database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}

$root = realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT'));
if ($root === false || !is_file($root . '/modules/billing/lib/invoice_delivery.php')) {
    throw new RuntimeException('Installed CoreAccounting runtime is required.');
}
require_once $root . '/modules/billing/lib/invoice_delivery.php';

$pdo = getDB();
if (!$pdo || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$scratch = 'coreaccounting_delivery_fixture';
$created = false;
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};

try {
    $pdo->exec("CREATE DATABASE {$scratch}");
    $created = true;
    foreach (['accounting_entities', 'accounting_journal_entries', 'billing_invoices',
        'billing_invoice_tokens'] as $table) {
        $pdo->exec("CREATE TABLE {$scratch}.{$table} LIKE coreaccounting_ci.{$table}");
    }
    $pdo->exec("USE {$scratch}");
    $pdo->exec("INSERT INTO accounting_entities
        (tenant_id, code, legal_name, base_currency, active)
        VALUES (17, 'CI', 'CI Delivery Company', 'USD', 1)");
    $entityId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO accounting_journal_entries
        (tenant_id, entity_id, period_id, je_number, posting_date, status, currency,
         total_debit, total_credit)
        VALUES (17, :entity_id, 1, "CI-DELIVERY-JE", "2026-10-11", "posted", "USD", 0.13, 0.13)')
        ->execute(['entity_id' => $entityId]);
    $journalId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO billing_invoices
        (tenant_id, entity_id, journal_entry_id, invoice_number, client_name,
         issue_date, due_date, subtotal, total, amount_due, status)
        VALUES (17, :entity_id, :journal_id, "CI-DELIVERY-INV", "CI Delivery Client",
                "2026-10-11", "2026-11-10", 0.13, 0.13, 0.13, "approved")')
        ->execute(['entity_id' => $entityId, 'journal_id' => $journalId]);
    $invoiceId = (int) $pdo->lastInsertId();
    $assert($invoiceId > 0, 'new synthetic posted invoice exists in the disposable database');

    $firstId = 'a223e456-e89b-42d3-a456-426614174000';
    $secondId = 'b223e456-e89b-42d3-a456-426614174000';
    $thirdId = 'c223e456-e89b-42d3-a456-426614174000';
    $recipient = 'delivery@coreaccounting.invalid';
    $first = billingDeliveryReserve(17, $invoiceId, $firstId, $recipient, false);
    $tokenId = (int) $first['token_id'];
    $token = $pdo->query("SELECT delivery_status, revoked_at, LENGTH(token_hash) AS hash_length
        FROM billing_invoice_tokens WHERE id = {$tokenId}")->fetch(PDO::FETCH_ASSOC);
    $assert(!$first['replayed'] && $token['delivery_status'] === 'pending'
        && $token['revoked_at'] === null && (int) $token['hash_length'] === 32,
        'first delivery reserves a hashed customer link before provider contact');
    $assert((int) (billingTokenFindByRaw($first['token'])['id'] ?? 0) === $tokenId,
        'reserved raw link resolves through its hash while it remains active');

    $again = billingDeliveryReserve(17, $invoiceId, $firstId, $recipient, false);
    $assert($again['replayed'] && (int) $again['token_id'] === $tokenId
        && (int) $pdo->query('SELECT COUNT(*) FROM billing_invoice_tokens')->fetchColumn() === 1,
        'exact retry cannot create another link');
    foreach ([
        [17, $invoiceId, $firstId, 'other@coreaccounting.invalid', false],
        [17, $invoiceId, $secondId, $recipient, false],
    ] as $attempt) {
        try {
            billingDeliveryReserve(...$attempt);
            throw new RuntimeException('Conflicting delivery was accepted.');
        } catch (DomainException $expected) {
            // The invoice lock must serialize both the request key and pending attempt.
        }
    }
    try {
        billingDeliveryReserve(18, $invoiceId, $secondId, $recipient, false);
        throw new RuntimeException('Another tenant reserved the invoice.');
    } catch (OutOfBoundsException $expected) {
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM billing_invoice_tokens')->fetchColumn() === 1,
        'recipient change, competing request and another tenant cannot create a token');
    try {
        billingDeliveryResolve(17, $invoiceId, $tokenId, 'not_accepted', null, 7);
        throw new RuntimeException('A still-processing delivery was prematurely resolved.');
    } catch (DomainException $expected) {
    }
    $assert((string) $pdo->query("SELECT delivery_status FROM billing_invoice_tokens
        WHERE id = {$tokenId}")->fetchColumn() === 'pending',
        'a new pending attempt cannot be declared undelivered during processing');

    billingDeliveryMarkUncertain(17, $invoiceId, $tokenId, $firstId, 'provider response lost');
    $uncertain = $pdo->query("SELECT delivery_status, revoked_at FROM billing_invoice_tokens
        WHERE id = {$tokenId}")->fetch(PDO::FETCH_ASSOC);
    $assert($uncertain['delivery_status'] === 'uncertain' && $uncertain['revoked_at'] === null,
        'uncertain delivery retains its possibly delivered customer link');
    try {
        billingDeliveryReserve(17, $invoiceId, $secondId, $recipient, false);
        throw new RuntimeException('Uncertain delivery allowed another send.');
    } catch (DomainException $expected) {
    }
    billingDeliveryResolve(17, $invoiceId, $tokenId, 'not_accepted', null, 7);
    $failed = $pdo->query("SELECT delivery_status, revoked_at FROM billing_invoice_tokens
        WHERE id = {$tokenId}")->fetch(PDO::FETCH_ASSOC);
    $assert($failed['delivery_status'] === 'failed' && $failed['revoked_at'] !== null,
        'reviewed non-acceptance revokes the link and reopens delivery');
    $assert(billingTokenFindByRaw($first['token']) === null,
        'the abandoned customer link no longer opens the invoice');

    $second = billingDeliveryReserve(17, $invoiceId, $secondId, $recipient, false);
    $revoked = billingDeliveryFinalize(17, $invoiceId, (int) $second['token_id'],
        $secondId, 'ci-provider-accepted', 7);
    $invoice = $pdo->query("SELECT status, sent_at FROM billing_invoices WHERE id = {$invoiceId}")
        ->fetch(PDO::FETCH_ASSOC);
    $assert($revoked === 0 && $invoice['status'] === 'sent' && $invoice['sent_at'] !== null,
        'provider acceptance commits sent invoice state');
    $assert(billingDeliveryReserve(17, $invoiceId, $secondId, $recipient, false)['replayed']
        && (int) $pdo->query('SELECT COUNT(*) FROM billing_invoice_tokens')->fetchColumn() === 2,
        'accepted request replays without resending');
    try {
        billingDeliveryReserve(17, $invoiceId, $thirdId, $recipient, false);
        throw new RuntimeException('Resend confirmation was not required.');
    } catch (DomainException $expected) {
    }

    $third = billingDeliveryReserve(17, $invoiceId, $thirdId, $recipient, true);
    billingDeliveryMarkUncertain(17, $invoiceId, (int) $third['token_id'], $thirdId,
        'provider accepted but final response was lost');
    $revoked = billingDeliveryResolve(17, $invoiceId, (int) $third['token_id'],
        'accepted', 'ci-provider-reviewed', 7);
    $links = $pdo->query('SELECT id, delivery_status, revoked_at FROM billing_invoice_tokens
        ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $assert($revoked === 1 && $links[1]['delivery_status'] === 'sent'
        && $links[1]['revoked_at'] !== null && $links[2]['delivery_status'] === 'sent'
        && $links[2]['revoked_at'] === null,
        'reviewed accepted resend rotates only the prior customer link');
    $assert(billingTokenFindByRaw($second['token']) === null
        && (int) (billingTokenFindByRaw($third['token'])['id'] ?? 0) === (int) $third['token_id'],
        'only the reviewed resend link remains usable by the customer');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM billing_invoice_tokens')->fetchColumn() === 3,
        'one invoice and three auditable attempts remain, with no duplicate request');
} finally {
    $pdo->exec('USE coreaccounting_ci');
    if ($created) $pdo->exec("DROP DATABASE {$scratch}");
}
