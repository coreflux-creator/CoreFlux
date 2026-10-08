<?php
/** Pure contract checks for legal-entity billing delivery. No database or email send. */
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'contacts API' => 'modules/billing/api/client_contacts.php',
    'delivery helper' => 'modules/billing/lib/entity_delivery.php',
    'invoice API' => 'modules/billing/api/invoices.php',
    'dunning API' => 'modules/billing/api/dunning.php',
    'dunning cron' => 'scripts/dunning_daily.php',
    'statement API' => 'modules/billing/api/send_statement.php',
    'batch API' => 'modules/billing/api/send_statements_batch.php',
    'mail service' => 'core/MailService.php',
    'Resend driver' => 'core/mail/ResendDriver.php',
];
$sources = [];
$checks = 0;
foreach ($files as $label => $relative) {
    $path = $root . '/' . $relative;
    $source = file_get_contents($path);
    if ($source === false || !str_contains((string) shell_exec('php -l ' . escapeshellarg($path)), 'No syntax errors')) {
        throw new RuntimeException("{$label} does not parse");
    }
    $sources[$label] = $source;
    $checks++;
}
$expect = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException($label);
    $checks++;
};
$expect(str_contains($sources['delivery helper'], 'AND entity_id = :e AND client_name = :c'), 'contacts must be entity-scoped');
$expect(str_contains($sources['contacts API'], "entity_id IS NULL"), 'unassigned contacts remain visible');
$expect(str_contains($sources['contacts API'], "action === 'assign'"), 'legacy contact assignment');
$expect(str_contains($sources['invoice API'], '(int) $postedEntry[\'entity_id\'] !== $entityId'), 'invoice journal entity checked');
$expect(str_contains($sources['invoice API'], 'billingClientContactForEntity('), 'invoice fallback scoped');
$expect(str_contains($sources['dunning API'], 'billingEntityMailSender('), 'manual reminders use entity sender');
$expect(str_contains($sources['dunning cron'], 'billingEntityMailSender('), 'scheduled reminders use entity sender');
$expect(str_contains($sources['statement API'], 'billingStatementResolveRecipients($tid, $entityId,'), 'statement contact scoped');
$expect(str_contains($sources['batch API'], 'billingStatementResolveRecipients($tid, $entityId,'), 'batch contact scoped');
$expect(str_contains($sources['mail service'], "'cc'            => \$cc"), 'mail envelope contains cc');
$expect(str_contains($sources['Resend driver'], "\$payload['cc'] = \$cc"), 'provider receives cc');
$expect(str_contains($sources['Resend driver'], "\$payload['attachments'] = \$files"), 'provider receives attachments');
echo "Billing delivery: {$checks} checks passed.\n";
