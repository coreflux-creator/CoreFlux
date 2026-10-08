<?php
/** Hosted log-only billing delivery acceptance; never run against production. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';
require_once __DIR__ . '/../core/mail_bootstrap.php';
require_once __DIR__ . '/../modules/billing/lib/entity_delivery.php';

$mail = cf_mail_bootstrap();
if (!defined('COREFLUX_STAGING') || !COREFLUX_STAGING
    || $mail->default_driver_name() !== 'log' || $mail->driver('resend') !== null) {
    throw new RuntimeException('Refusing to run unless outbound mail is staging log-only.');
}

$entities = [];
foreach (['SIM-LIFECYCLE-QA', 'SIM-CUTOVER-QA'] as $code) {
    $row = qaOne($pdo, 'SELECT id, legal_name FROM accounting_entities
        WHERE tenant_id = :t AND code = :code AND active = 1',
        ['t' => QA_TENANT, 'code' => $code]);
    if (!$row) throw new RuntimeException("Missing synthetic legal entity {$code}");
    $entities[$code] = $row;
}
$first = (int) $entities['SIM-LIFECYCLE-QA']['id'];
$second = (int) $entities['SIM-CUTOVER-QA']['id'];
$existingSenders = qaOne($pdo, 'SELECT COUNT(*) AS n FROM billing_entity_mail_settings
    WHERE tenant_id = :t AND entity_id IN (:first, :second)',
    ['t' => QA_TENANT, 'first' => $first, 'second' => $second]);
if ((int) ($existingSenders['n'] ?? -1) !== 0) {
    throw new RuntimeException('Refusing to replace existing entity mail settings.');
}

$run = gmdate('YmdHis') . '-' . bin2hex(random_bytes(3));
$client = 'Synthetic Delivery QA ' . $run;
$maker = null;
$reviewer = null;
$cookies = [];
$senderIds = [];
try {
    $maker = qaEnsureActor($pdo, 'delivery-maker');
    $reviewer = qaEnsureActor($pdo, 'delivery-reviewer');
    $makerCookie = tempnam(sys_get_temp_dir(), 'cf-del-maker-');
    $reviewerCookie = tempnam(sys_get_temp_dir(), 'cf-del-review-');
    $cookies = array_values(array_filter([$makerCookie, $reviewerCookie], 'is_string'));
    if ($makerCookie === false || $reviewerCookie === false) {
        throw new RuntimeException('Could not create isolated test sessions.');
    }
    qaLogin($maker, $makerCookie);
    qaLogin($reviewer, $reviewerCookie);

    foreach ([
        [$first, 'ar-one@example.test', 'controller-one@example.test', 'reply-one@example.test'],
        [$second, 'ar-two@example.test', 'controller-two@example.test', 'reply-two@example.test'],
    ] as [$entityId, $primary, $escalation, $replyTo]) {
        qaRequest('/modules/billing/api/client_contacts.php', 'POST', [
            'entity_id' => $entityId,
            'client_name' => $client,
            'ar_primary_email' => $primary,
            'ar_escalation_email' => $escalation,
            'notes' => 'Synthetic staging delivery acceptance ' . $run,
        ], $makerCookie);
        $senderIds[] = $entityId;
        qaRequest('/modules/billing/api/client_contacts.php?action=sender&entity_id=' . $entityId,
            'POST', ['from_name' => '', 'reply_to' => $replyTo], $makerCookie);
    }
    $contactOne = billingClientContactForEntity(QA_TENANT, $first, $client);
    $contactTwo = billingClientContactForEntity(QA_TENANT, $second, $client);
    qaExpect(($contactOne['ar_primary_email'] ?? null) === 'ar-one@example.test'
        && ($contactTwo['ar_primary_email'] ?? null) === 'ar-two@example.test',
        'same-named client contacts remain separate by legal entity');
    $senderOne = billingEntityMailSender(QA_TENANT, $first);
    $senderTwo = billingEntityMailSender(QA_TENANT, $second);
    qaExpect($senderOne['ready'] && $senderTwo['ready']
        && $senderOne['from_name'] === $entities['SIM-LIFECYCLE-QA']['legal_name']
        && $senderTwo['from_name'] === $entities['SIM-CUTOVER-QA']['legal_name']
        && $senderOne['reply_to'] === 'reply-one@example.test'
        && $senderTwo['reply_to'] === 'reply-two@example.test',
        'entity-specific senders use legal names and distinct reply addresses');

    $date = date('Y-m-d');
    $due = date('Y-m-d', strtotime($date . ' +30 days'));
    $invoice = qaRequest('/modules/billing/api/invoices.php', 'POST', [
        'entity_id' => $first, 'client_name' => $client,
        'issue_date' => $date, 'due_date' => $due,
        'currency' => 'USD', 'tax_rate_pct' => 0,
        'notes_internal' => 'Synthetic staging delivery acceptance ' . $run,
        'lines' => [['description' => 'Invented delivery check', 'quantity' => 1,
            'unit' => 'each', 'unit_price' => 19, 'item_type' => 'fixed_fee',
            'gl_revenue_account_code' => '4000']],
    ], $makerCookie);
    $invoiceId = (int) ($invoice['id'] ?? 0);
    qaExpect($invoiceId > 0, 'synthetic delivery invoice drafted');
    qaRequest('/modules/billing/api/invoices.php?action=request_approval&id=' . $invoiceId,
        'POST', [], $makerCookie);
    qaRequest('/modules/billing/api/approval_assignment.php', 'POST', [
        'invoice_id' => $invoiceId, 'reviewer_user_ids' => [(int) $reviewer['id']],
    ], $makerCookie);
    $approved = qaRequest('/modules/billing/api/invoices.php?action=approve&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    qaExpect(!empty($approved['approved']), 'independent reviewer approved invoice');
    $posted = qaRequest('/modules/billing/api/invoices.php?action=post&id=' . $invoiceId,
        'POST', [], $reviewerCookie);
    qaExpect((int) ($posted['journal_entry_id'] ?? 0) > 0,
        'invoice posted to canonical journal before delivery');

    $invoiceRow = qaOne($pdo, 'SELECT invoice_number FROM billing_invoices
        WHERE tenant_id = :t AND id = :id AND entity_id = :e',
        ['t' => QA_TENANT, 'id' => $invoiceId, 'e' => $first]);
    if (!$invoiceRow) throw new RuntimeException('Posted invoice not found in its entity.');
    $sent = qaRequest('/modules/billing/api/invoices.php?action=send&id=' . $invoiceId,
        'POST', [], $makerCookie);
    qaExpect(($sent['email_status'] ?? null) === 'sent' && !empty($sent['pdf_attached']),
        'log-only invoice send rendered and attached its PDF');
    $invoiceOutbox = qaOne($pdo, 'SELECT id, driver, status, to_addresses_json,
        cc_addresses_json, reply_to, attachments_json FROM mail_outbox
        WHERE tenant_id = :t AND module = "billing" AND purpose = "invoice_sent"
          AND subject LIKE :subject ORDER BY id DESC LIMIT 1',
        ['t' => QA_TENANT, 'subject' => '%' . $invoiceRow['invoice_number'] . '%']);
    $invoiceTo = json_decode((string) ($invoiceOutbox['to_addresses_json'] ?? 'null'), true);
    $invoiceAttachments = json_decode((string) ($invoiceOutbox['attachments_json'] ?? 'null'), true);
    qaExpect($invoiceOutbox && $invoiceOutbox['driver'] === 'log'
        && $invoiceOutbox['status'] === 'sent'
        && $invoiceTo === ['ar-one@example.test']
        && $invoiceOutbox['reply_to'] === 'reply-one@example.test'
        && is_array($invoiceAttachments) && count($invoiceAttachments) === 1
        && str_ends_with((string) ($invoiceAttachments[0]['filename'] ?? ''), '.pdf'),
        'invoice outbox records the correct recipient, reply address and PDF');

    $preview = qaRequest('/modules/billing/api/send_statement.php?entity_id=' . $first
        . '&client_name=' . rawurlencode($client) . '&as_of=' . $date,
        'GET', null, $makerCookie);
    qaExpect(($preview['recipients']['to'] ?? null) === 'ar-one@example.test'
        && ($preview['recipients']['cc'] ?? null) === ['controller-one@example.test']
        && ($preview['sender']['reply_to'] ?? null) === 'reply-one@example.test'
        && ($preview['entity_name'] ?? null) === $entities['SIM-LIFECYCLE-QA']['legal_name'],
        'statement preview retains entity contact, CC and legal identity');
    $statement = qaRequest('/modules/billing/api/send_statement.php', 'POST', [
        'entity_id' => $first, 'client_name' => $client, 'as_of' => $date,
    ], $makerCookie);
    qaExpect(($statement['sent_to'] ?? null) === 'ar-one@example.test'
        && ($statement['cc'] ?? null) === ['controller-one@example.test'],
        'log-only statement sent to its scoped recipient and CC');
    $statementOutbox = qaOne($pdo, 'SELECT id, driver, status, to_addresses_json,
        cc_addresses_json, reply_to FROM mail_outbox
        WHERE tenant_id = :t AND module = "billing" AND purpose = "ar_statement"
          AND id > :after ORDER BY id DESC LIMIT 1',
        ['t' => QA_TENANT, 'after' => (int) $invoiceOutbox['id']]);
    qaExpect($statementOutbox && $statementOutbox['driver'] === 'log'
        && $statementOutbox['status'] === 'sent'
        && json_decode((string) $statementOutbox['to_addresses_json'], true) === ['ar-one@example.test']
        && json_decode((string) $statementOutbox['cc_addresses_json'], true) === ['controller-one@example.test']
        && $statementOutbox['reply_to'] === 'reply-one@example.test',
        'statement outbox audits the scoped CC and reply address');

    echo json_encode(['run' => $run, 'invoice_id' => $invoiceId,
        'invoice_outbox_id' => (int) $invoiceOutbox['id'],
        'statement_outbox_id' => (int) $statementOutbox['id'],
        'mail_driver' => $mail->default_driver_name()], JSON_PRETTY_PRINT), "\n";
} finally {
    foreach ([$maker, $reviewer] as $actor) {
        if ($actor && isset($actor['id'])) {
            $pdo->prepare('UPDATE users SET is_active = 0, password_hash = NULL
                WHERE id = :id AND tenant_id = :t')
                ->execute(['id' => $actor['id'], 't' => QA_TENANT]);
        }
    }
    foreach ($cookies as $cookie) {
        if (is_file($cookie)) unlink($cookie);
    }
    $deleteContacts = $pdo->prepare('DELETE FROM billing_client_contacts
        WHERE tenant_id = :t AND client_name = :client AND entity_id IN (:first, :second)');
    $deleteContacts->execute(['t' => QA_TENANT, 'client' => $client,
        'first' => $first, 'second' => $second]);
    foreach ($senderIds as $entityId) {
        $pdo->prepare('DELETE FROM billing_entity_mail_settings
            WHERE tenant_id = :t AND entity_id = :e AND reply_to = :reply')
            ->execute(['t' => QA_TENANT, 'e' => $entityId,
                'reply' => $entityId === $first ? 'reply-one@example.test' : 'reply-two@example.test']);
    }
}
