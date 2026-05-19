<?php
/**
 * Mercury — Slice 3.5 (AP integration) + Slice 2 polish (CSV recipients) smoke.
 *
 * Coverage:
 *   - mpCreateFromApPayment service contract: validates ap_payment state,
 *     refuses duplicate live instructions, vendor-name lookup against
 *     mercury_recipients, stamps ap_payments.rail_external_ref.
 *   - AP API: new ?action=send_via_mercury route + mercury_connected flag
 *     in GET response.
 *   - AP UI: per-row "Send via Mercury" button gated on eligibility.
 *   - CSV recipient import API: 3 actions, schema registration, duplicate
 *     name detection in dry_run, commit wires through mercuryRecipientCreate.
 *   - UI: CSV import + template buttons in MercuryRecipients page.
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$c = fn (string $h, string $n): bool => strpos($h, $n) !== false;

// ----------------------------------------------------------------- mpCreateFromApPayment
echo "core/mercury_payments.php — mpCreateFromApPayment\n";
$svc = (string) file_get_contents(__DIR__ . '/../core/mercury_payments.php');
$a('helper exported',                            $c($svc, 'function mpCreateFromApPayment'));
$a('requires status=sent',                       $c($svc, 'ap_payment must be in status=sent'));
$a('refuses if already attached to a rail',
    $c($svc, 'is already attached to rail'));
$a('refuses duplicate live instruction (non-terminal)',
    $c($svc, 'state NOT IN ("Cancelled","Failed","Returned")'));
$a('looks up mercury_recipient by case-insensitive vendor name',
    $c($svc, 'LOWER(name) = LOWER(:n)'));
$a('requires kind=vendor + status=active',
    $c($svc, 'kind = "vendor" AND status = "active"'));
$a('error message points operator at Recipients page',
    $c($svc, 'add them under Treasury → Pay-out Rails → Recipients first'));
$a('converts AP $ amount to cents',              $c($svc, "(int) round(((float) \$row['amount']) * 100)"));
$a('source_module + source_ref persisted',
    $c($svc, "'source_module'  => 'ap'") && $c($svc, "'source_ref'     => (string) \$apPaymentId"));
$a('idempotency_key namespaced by ap_payment id',
    $c($svc, "'idempotency_key' => 'ap:' . \$apPaymentId"));
$a('stamps ap_payments.rail_external_ref → pi:{id}',
    $c($svc, "SET rail_external_ref = :ref, disbursement_rail = \"mercury\""));
$a('SoD preserved (instruction starts in Draft via mpCreate)',
    $c($svc, 'function mpCreateFromApPayment')
    && !preg_match('/mpCreateFromApPayment\b[\s\S]*?mpApprove\(/', $svc)
    && !preg_match('/mpCreateFromApPayment\b[\s\S]*?mpTransition\([^,]+,[^,]+,\s*[\'\"]Approved/', $svc));

// ----------------------------------------------------------------- AP API
echo "\nmodules/ap/api/payments.php — Slice 3.5 wire-up\n";
$ap = (string) file_get_contents(__DIR__ . '/../modules/ap/api/payments.php');
$a('new POST ?action=send_via_mercury route',
    $c($ap, "\$method === 'POST' && \$action === 'send_via_mercury'"));
$a('action requires ap.payment.send RBAC',
    $c($ap, "rbac_legacy_require(\$user, 'ap.payment.send')") &&
    preg_match('/send_via_mercury.*rbac_legacy_require/s', $ap));
$a('action calls mpCreateFromApPayment',         $c($ap, 'mpCreateFromApPayment($tid, $id'));
$a('returns instruction_id in response',         $c($ap, "'instruction_id' =>"));
$a('GET response now includes mercury_connected flag',
    $c($ap, "'mercury_connected'     => \$mercuryConnected"));
$a('mercury_connected reads mercury_connections',
    $c($ap, "FROM mercury_connections WHERE tenant_id = :tenant_id"));
$a('mercury_connected gracefully degrades',
    $c($ap, '$mercuryConnected = false;') &&
    preg_match('/mercury_connections.*catch \(\\\\Throwable/s', $ap));

// ----------------------------------------------------------------- AP UI
echo "\nmodules/ap/ui/PaymentsList.jsx — per-row Send via Mercury\n";
$pl = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/PaymentsList.jsx');
$a('reads mercury_connected from API',           $c($pl, 'mercuryConnected = !!data?.mercury_connected'));
$a('mercuryEligible() guard',
    $c($pl, 'const mercuryEligible = (p) =>') &&
    $c($pl, "p.status === 'sent'") &&
    $c($pl, '!p.rail_external_ref'));
$a('per-row Send-via-Mercury button testid',
    $c($pl, 'data-testid={`ap-send-via-mercury-${p.id}`}'));
$a('button POSTs send_via_mercury action',       $c($pl, '?action=send_via_mercury'));
$a('success / error per-row affordances',
    $c($pl, 'ap-send-via-mercury-error-') && $c($pl, 'ap-send-via-mercury-ok-'));
$a('success message shows pi: prefix from response',
    $c($pl, 'pi:${res.instruction_id}'));

// ----------------------------------------------------------------- CSV import API
echo "\napi/mercury_recipients_csv_import.php\n";
$csvPath = __DIR__ . '/../api/mercury_recipients_csv_import.php';
$a('CSV import endpoint exists',                 is_file($csvPath));
$csv = (string) file_get_contents($csvPath);
$a('registers schema via CsvImportService',
    $c($csv, "CsvImportService::registerSchema('mercury_vendor_recipients'"));
$a('schema declares 8 fields (name + email + payment_method + 3 bank + nickname + notes)',
    $c($csv, "'name'") && $c($csv, "'email'") && $c($csv, "'payment_method'") &&
    $c($csv, "'routing_number'") && $c($csv, "'account_number'") && $c($csv, "'account_type'") &&
    $c($csv, "'nickname'") && $c($csv, "'notes'"));
$a('name is required',                           $c($csv, "'name'           => ['label' => 'Vendor name',      'required' => true]"));
$a('payment_method enum allowlist',              $c($csv, "'enum'  => ['ach', 'wire', 'check']"));
$a('account_type enum allowlist',                $c($csv, "'enum'  => ['checking', 'savings']"));
$a('unique_within_batch on name (prevents dup CSV rows)',
    $c($csv, "'unique_within_batch' => ['name']"));
$a('GET ?action=template streams CSV download',  $c($csv, "buildTemplate('mercury_vendor_recipients')"));
$a('POST ?action=dry_run validates + flags duplicates',
    $c($csv, "action === 'dry_run'") && $c($csv, 'already exists as a recipient'));
$a('dry_run case-insensitive duplicate match',
    $c($csv, 'array_map(\'strtolower\', $names)'));
$a('POST ?action=commit invokes mercuryRecipientCreate',
    $c($csv, 'mercuryRecipientCreate($tenantId, ['));
$a('commit always sets kind=vendor',             $c($csv, "'kind'           => 'vendor'"));
$a('commit threads through skip_invalid',        $c($csv, "['skip_invalid' => \$skipInvalid"));
$a('RBAC: writes need bank.manage',              $c($csv, "rbac_legacy_require(\$user, 'accounting.bank.manage')"));
$a('RBAC: template read accepts bank.view OR manage',
    $c($csv, "rbac_legacy_can(\$user, 'accounting.bank.view')"));
$a('rejects unknown actions',                    $c($csv, "Unknown action. Use ?action=template|dry_run|commit"));

// ----------------------------------------------------------------- CSV UI
echo "\nUI — MercuryRecipients.jsx CSV affordances\n";
$ui = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/MercuryRecipients.jsx');
$a('CSV template download link',
    $c($ui, 'data-testid="mercury-recipients-csv-template-btn"') &&
    $c($ui, '/api/mercury_recipients_csv_import.php?action=template'));
$a('CSV import label (file picker)',
    $c($ui, 'data-testid="mercury-recipients-csv-import-btn"'));
$a('hidden file input testid',
    $c($ui, 'data-testid="mercury-recipients-csv-input"'));
$a('accept=".csv,text/csv" on file input',       $c($ui, 'accept=".csv,text/csv"'));
$a('dry_run before commit',                      $c($ui, '?action=dry_run') && $c($ui, '?action=commit'));
$a('prompts user when dry_run errors > 0',       $c($ui, 'CSV has ${dry.error_count} invalid row(s)'));
$a('commit uses skip_invalid=1',                 $c($ui, '?action=commit&skip_invalid=1'));
$a('CSV body sent as text/csv via raw fetch',
    $c($ui, "'Content-Type': 'text/csv'") && $c($ui, 'body: text'));

// ----------------------------------------------------------------- Syntax
echo "\nSyntax sanity (php -l)\n";
foreach ([
    'core/mercury_payments.php',
    'api/mercury_recipients_csv_import.php',
    'modules/ap/api/payments.php',
] as $rel) {
    $out = []; $rc = 0;
    @exec('php -l ' . escapeshellarg(__DIR__ . '/../' . $rel) . ' 2>&1', $out, $rc);
    $a("php -l {$rel}", $rc === 0);
}

echo "\n=========================================\n";
echo "Mercury 3.5 + 2-polish + funding-leg smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
