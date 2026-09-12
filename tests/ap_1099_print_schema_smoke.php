<?php

$source = file_get_contents(__DIR__ . '/../modules/ap/api/1099.php');

function assert1099Print(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

assert1099Print(str_contains($source, "rbac_legacy_require(\$user, 'ap.vendor.view_pii')"),
    'printing full tax forms requires the sensitive vendor-data permission');
assert1099Print(str_contains($source, 'LEFT JOIN ap_vendors_index v'),
    'print data uses the canonical AP vendor table');
assert1099Print(str_contains($source, 'LEFT JOIN companies c'),
    'print data uses the linked company address');
assert1099Print(str_contains($source, 'LEFT JOIN accounting_entities e'),
    'payer details use the accounting legal entity');
assert1099Print(str_contains($source, 'c.postal_code AS zip'),
    'company postal codes map to the print template field');
assert1099Print(str_contains($source, "\$form['vendor_tax_id_ct'] ?? \$form['tax_id_full_ct']"),
    'recipient tax ID falls back to the encrypted 1099 ledger snapshot');
assert1099Print(str_contains($source, 'decryptField($taxIdCiphertext)'),
    'encrypted recipient tax IDs are decrypted only during authorized rendering');
assert1099Print(!str_contains($source, 'LEFT JOIN ap_vendors v'),
    'removed the nonexistent legacy AP vendor table');
assert1099Print(!str_contains($source, 'v.tax_id_full,'),
    'removed the nonexistent plaintext tax ID column');
assert1099Print(str_contains($source, '$recipientTin = h($tin);'),
    'recipient tax ID is escaped before HTML rendering');

fwrite(STDOUT, "1099 print schema smoke passed.\n");
