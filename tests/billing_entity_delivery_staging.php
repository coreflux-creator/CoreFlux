<?php
/** Rollback-only customer-delivery isolation check on the isolated staging simulation tenant. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--check') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --check.\n");
    exit(2);
}

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/billing/lib/statement.php';
require_once __DIR__ . '/../modules/billing/lib/dunning.php';

$pdo = getDB();
if (!$pdo
    || realpath(__DIR__ . '/..') !== '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html'
    || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'muzqvdvqbx'
    || (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id = 999')->fetchColumn() !== 1) {
    throw new RuntimeException('Refusing a non-staging application, database, or tenant.');
}

$entities = $pdo->query('SELECT id, legal_name FROM accounting_entities
    WHERE tenant_id = 999 AND active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_ASSOC);
if (count($entities) !== 2) throw new RuntimeException('Expected two simulation legal entities.');
$first = (int) $entities[0]['id'];
$second = (int) $entities[1]['id'];
$client = 'DELIVERY-ISOLATION-' . bin2hex(random_bytes(5));
$legacy = 'DELIVERY-LEGACY-' . bin2hex(random_bytes(5));
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};

$pdo->beginTransaction();
try {
    $add = $pdo->prepare('INSERT INTO billing_client_contacts
        (tenant_id, entity_id, client_name, ar_primary_email, ar_escalation_email)
        VALUES (999, :e, :c, :p, :a)');
    $add->execute(['e' => $first, 'c' => $client,
        'p' => 'ar-one@example.test', 'a' => 'controller-one@example.test']);
    $add->execute(['e' => $second, 'c' => $client,
        'p' => 'ar-two@example.test', 'a' => 'controller-two@example.test']);
    $add->execute(['e' => null, 'c' => $legacy,
        'p' => 'legacy@example.test', 'a' => null]);

    $one = billingStatementResolveRecipients(999, $first, $client);
    $two = billingStatementResolveRecipients(999, $second, $client);
    $check($one['to'] === 'ar-one@example.test' && $one['cc'] === ['controller-one@example.test'],
        'first entity statement contact');
    $check($two['to'] === 'ar-two@example.test' && $two['cc'] === ['controller-two@example.test'],
        'second entity statement contact');
    $check(billingStatementResolveRecipients(999, $first, $legacy)['to'] === null,
        'unassigned legacy contact cannot send');

    $policy = billingDunningDefaultPolicy();
    $invoice = ['client_name' => $client, 'bill_to_json' => null, 'entity_id' => $second];
    $dunning = billingDunningResolveRecipients(999, $invoice, 2, $policy);
    $check($dunning['to'] === 'ar-two@example.test'
        && $dunning['cc'] === ['controller-two@example.test'], 'reminder contact follows invoice entity');

    $save = $pdo->prepare('INSERT INTO billing_entity_mail_settings
        (tenant_id, entity_id, from_name, reply_to) VALUES (999, :e, NULL, :r)
        ON DUPLICATE KEY UPDATE from_name = VALUES(from_name), reply_to = VALUES(reply_to)');
    $save->execute(['e' => $first, 'r' => 'billing-one@example.test']);
    $save->execute(['e' => $second, 'r' => 'billing-two@example.test']);
    $senderOne = billingEntityMailSender(999, $first);
    $senderTwo = billingEntityMailSender(999, $second);
    $check($senderOne['ready'] && $senderOne['reply_to'] === 'billing-one@example.test'
        && $senderOne['from_name'] === $entities[0]['legal_name'], 'first entity sender');
    $check($senderTwo['ready'] && $senderTwo['reply_to'] === 'billing-two@example.test'
        && $senderTwo['from_name'] === $entities[1]['legal_name'], 'second entity sender');
    try {
        billingEntityMailSender(999, 999999999);
        throw new RuntimeException('Foreign entity accepted.');
    } catch (InvalidArgumentException $expected) {
        $checks++;
    }
} finally {
    $pdo->rollBack();
}

echo "Billing delivery isolation: {$checks} rollback-only checks passed.\n";
