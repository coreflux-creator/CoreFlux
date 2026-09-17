<?php
declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
$failures = [];
$check = static function (string $label, bool $passed) use (&$failures): void {
    echo ($passed ? 'OK  ' : 'BAD ') . $label . PHP_EOL;
    if (!$passed) $failures[] = $label;
};

$php = [
    'modules/billing/api/invoices.php',
    'core/MailService.php',
];
foreach ($php as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg("{$root}/{$file}") . ' 2>&1', $output, $code);
    $check("PHP lint {$file}", $code === 0);
}

$api = (string) file_get_contents("{$root}/modules/billing/api/invoices.php");
$list = (string) file_get_contents("{$root}/modules/billing/ui/InvoicesList.jsx");
$detail = (string) file_get_contents("{$root}/modules/billing/ui/InvoiceDetail.jsx");

$check('invoice recipient prefers bill-to and falls back to client contacts',
    str_contains($api, 'function billingInvoiceDefaultRecipient')
    && str_contains($api, "'source' => 'invoice bill-to'")
    && str_contains($api, "'source' => 'client contacts'"));
$check('invoice detail and list expose saved recipient data',
    str_contains($api, "'default_recipient' => billingInvoiceDefaultRecipient")
    && str_contains($api, "\$invoiceRow['recipient_email']")
    && str_contains($detail, 'data?.default_recipient?.email'));
$check('invoice list exposes journal posting state',
    str_contains($api, 'journal_entry_id, sent_at'));
$check('server search uses separate placeholders to avoid native-prepare HY093 errors',
    str_contains($api, 'invoice_number LIKE :q_invoice')
    && str_contains($api, 'client_name LIKE :q_client')
    && str_contains($api, "\$params['q_invoice']")
    && str_contains($api, "\$params['q_client']"));

$failedGuard = strpos($api, "if ((\$sendRes['status'] ?? 'failed') !== 'sent')");
$sentUpdate = strpos($api, 'UPDATE billing_invoices SET status = "sent"', $failedGuard === false ? 0 : $failedGuard);
$check('failed email leaves invoice approved and retryable',
    $failedGuard !== false
    && $sentUpdate !== false
    && $failedGuard < $sentUpdate
    && str_contains($api, 'The invoice remains approved so you can retry.')
    && str_contains($api, "'retryable' => true"));

$check('invoice work queue supports bulk approve, post, and send',
    str_contains($list, 'billing-invoices-approve-selected')
    && str_contains($list, 'billing-invoices-post-selected')
    && str_contains($list, 'billing-invoices-send-selected')
    && str_contains($list, 'action=approve&id=${id}')
    && str_contains($list, 'action=post&id=${id}')
    && str_contains($list, 'action=send&id=${id}'));
$check('bulk approval distinguishes completed and awaiting-policy rows',
    str_contains($list, 'if (result?.approved)')
    && str_contains($list, 'awaiting another approver'));
$check('bulk send uses saved recipients and retains row-level failures',
    str_contains($list, 'invoice bill-to email or the client’s saved AR contact')
    && str_contains($list, 'setSelected(new Set(failures.map'));
$check('invoice queue search and pagination are server-backed',
    str_contains($list, "qs.set('q', query)")
    && str_contains($list, "qs.set('page', String(page))")
    && str_contains($list, 'billing-invoices-pagination')
    && str_contains($list, 'billing-invoices-per-page'));

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Billing bulk invoice workflow smoke passed.' . PHP_EOL;
