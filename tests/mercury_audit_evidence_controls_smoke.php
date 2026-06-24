<?php
/**
 * Mercury audit evidence controls smoke
 *
 * Mercury is a Treasury rail for bank connection, recipient, payment, and
 * reconciliation actions. These paths must use the shared platform audit
 * writer and preserve reconstructable evidence without leaking token or bank
 * ciphertext.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$containsAll = static function (string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
        if (!str_contains($haystack, $needle)) return false;
    }
    return true;
};

$helper = (string) file_get_contents($root . '/core/mercury_audit.php');
$payments = (string) file_get_contents($root . '/core/mercury_payments.php');
$connection = (string) file_get_contents($root . '/api/mercury_connection.php');
$recipients = (string) file_get_contents($root . '/api/mercury_recipients.php');
$reconciliation = (string) file_get_contents($root . '/api/mercury_reconciliation.php');
$auditDoc = (string) file_get_contents($root . '/docs/AUDIT_GOVERNANCE.md');
$alignmentDoc = (string) file_get_contents($root . '/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md');

echo "Shared Mercury audit helper\n";
$a('helper uses platform audit writer',
    $containsAll($helper, [
        "require_once __DIR__ . '/audit.php'",
        'function mercuryAuditLogWrite(',
        'platformAuditLogWrite($tenantId, $actorUserId, $event, $targetId, $meta',
    ]));
$a('helper stamps Treasury source and Mercury object types',
    $containsAll($helper, [
        "return 'mercury_payment'",
        "return 'mercury_recipient'",
        "return 'mercury_connection'",
        "return 'mercury_reconciliation'",
        "'source' => \$meta['source'] ?? 'treasury'",
    ]));
$a('connection snapshots scrub encrypted token ciphertext',
    $containsAll($helper, [
        'function mercuryAuditConnectionRow(int $tenantId): ?array',
        "unset(\$row['api_token_ct'])",
    ]));
$a('recipient snapshots expose only masked bank methods and mappings',
    $containsAll($helper, [
        'function mercuryAuditRecipientRow(int $tenantId, int $recipientId): ?array',
        'account_number_last4',
        'account_type',
        'mercury_recipient_mappings',
    ])
    && !str_contains($helper, 'routing_number_ct, account_number_ct'));
$a('payment instruction snapshot helper exists',
    $containsAll($helper, [
        'function mercuryAuditPaymentInstructionRow(int $tenantId, int $instructionId): ?array',
        'FROM payment_instructions',
    ]));

echo "\nNo direct audit_log writes in Mercury rail cluster\n";
foreach ([
    'core/mercury_payments.php' => $payments,
    'api/mercury_connection.php' => $connection,
    'api/mercury_recipients.php' => $recipients,
    'api/mercury_reconciliation.php' => $reconciliation,
] as $label => $contents) {
    $a($label . ' avoids direct INSERT INTO audit_log',
        !preg_match('/INSERT INTO audit_log/', $contents));
}

echo "\nPayment state-machine evidence\n";
$a('payment transitions lock full rows and capture before/after',
    $containsAll($payments, [
        'SELECT * FROM payment_instructions WHERE tenant_id = :t AND id = :id FOR UPDATE',
        '$before = $cur->fetch',
        '$after = mercuryAuditPaymentInstructionRow($tenantId, $instructionId)',
        "'before' => \$before",
        "'after' => \$after",
    ]));
$a('payment create emits after snapshot',
    $containsAll($payments, [
        "'mercury.payment.created'",
        "'after' => \$row",
    ]));
$a('coapproval, cool-off, auto-advance, and CFO notices use helper',
    $containsAll($payments, [
        "'mercury.payment.coapproval_recorded'",
        "'mercury.payment.cool_off_deferred'",
        "'mercury.payment.auto_advance_failed'",
        "'mercury.payment.cfo_notified'",
        'mercuryAuditLogWrite($tenantId',
    ]));

echo "\nConnection, recipient, reconciliation evidence\n";
$a('connection lifecycle snapshots before/after rows',
    $containsAll($connection, [
        "require_once __DIR__ . '/../core/mercury_audit.php'",
        '$before = mercuryAuditConnectionRow($tenantId)',
        "'mercury.connection.connected'",
        "'mercury.connection.disconnected'",
        "'mercury.connection.probe_failed'",
        "'before' => \$before",
        "'after' => mercuryAuditConnectionRow(\$tenantId)",
    ]));
$a('recipient lifecycle snapshots before/after rows',
    $containsAll($recipients, [
        "require_once __DIR__ . '/../core/mercury_audit.php'",
        '$before = mercuryAuditRecipientRow($tenantId, $id)',
        "'mercury.recipient.created'",
        "'mercury.recipient.updated'",
        "'mercury.recipient.revoked'",
        "'before' => \$before",
        "'after' => mercuryAuditRecipientRow(\$tenantId, \$id)",
    ]));
$a('recipient rail setup snapshots mappings/defaults',
    $containsAll($recipients, [
        "'mercury.recipient.pushed'",
        "'mercury.funding_default.set'",
        "'mercury.sweep_destination.counterparty_set'",
        '$before = mercuryAuditConnectionRow($tenantId)',
        '$before = mercuryAuditRecipientRow($tenantId, $recipientId)',
    ]));
$a('reconciliation run uses platform writer',
    $containsAll($reconciliation, [
        "require_once __DIR__ . '/../core/mercury_audit.php'",
        "'mercury.reconciliation.run'",
        'mercuryAuditLogWrite($tenantId',
        "'after' => \$out",
    ]));

echo "\nDocs\n";
$a('audit governance names Mercury rail lifecycle',
    str_contains($auditDoc, 'Mercury rail lifecycle'));
$a('architecture alignment records Mercury rail audit evidence',
    $containsAll($alignmentDoc, [
        'Mercury payment instruction, co-approval, connection, recipient, and',
        'reconciliation rail events',
        '`mercuryAuditLogWrite`',
        'before/after source-row snapshots',
    ]));

echo "\nMercury audit evidence controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
