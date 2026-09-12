<?php

$policy = file_get_contents(__DIR__ . '/../modules/ap/api/approval_policies.php');
$riskApi = file_get_contents(__DIR__ . '/../modules/ap/api/vendor_risk.php');
$riskLib = file_get_contents(__DIR__ . '/../modules/ap/lib/vendor_risk.php');

function assertApSchema(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

foreach ([$policy, $riskApi, $riskLib] as $source) {
    assertApSchema(!preg_match('/\\b(?:FROM|JOIN)\\s+ap_vendors\\b/i', $source),
        'AP workflow no longer queries the nonexistent ap_vendors table');
}
assertApSchema(str_contains($policy, 'v.vendor_name = b.vendor_name'),
    'approval routing resolves the AP vendor from the bill vendor name');
assertApSchema(str_contains($policy, "(float) (\$row['total'] ?? 0)"),
    'approval routing uses the canonical bill total');
assertApSchema(str_contains($riskLib, 'c.w9_on_file, c.coi_on_file, c.coi_expires_on'),
    'vendor risk reads current company compliance fields');
assertApSchema(str_contains($riskLib, "document_type = 'banking_form'"),
    'vendor risk uses approved banking-form history');
assertApSchema(str_contains($riskLib, 'SUM(total)'),
    'vendor risk uses the canonical bill amount');

fwrite(STDOUT, "AP policy and vendor risk schema smoke passed.\n");
