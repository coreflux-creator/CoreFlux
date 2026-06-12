<?php
/**
 * AP batch export smoke
 *
 * Validates:
 *  - POST /api/ap/payments?action=originate_batch (NACHA file for many payments)
 *    - permission gate, state validation, atomic persist, single-file output
 *  - GET  /api/ap/export?ids=... (bulk-CSV scoping for bills + payments + expenses)
 *  - PaymentsList + BillsList wire bulk-select via useBulkSelection
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$c = fn (string $h, string $n): bool => strpos($h, $n) !== false;

echo "ap/api/payments.php — originate_batch action\n";
$ap = (string) file_get_contents(__DIR__ . '/../modules/ap/api/payments.php');
$a('originate_batch action handler present',          $c($ap, "\$action === 'originate_batch'"));
$a('requires ap.payment.send permission',             $c($ap, "rbac_legacy_require(\$user, 'ap.payment.send')"));
$a('refuses empty ids[]',                             $c($ap, 'ids[] required'));
$a('caps batch at 500 entries',                       $c($ap, 'Batch limited to 500 payments'));
$a('refuses ids missing from this tenant',            $c($ap, 'Some ids not found in this tenant'));
$a('rejects ineligible payment statuses',             $c($ap, 'not eligible (status='));
$a('rejects non-ach/plaid methods',                   $c($ap, 'not eligible (must be ach|plaid)'));
$a('builds RailItems via paymentRailsBuildItem',      $c($ap, 'paymentRailsBuildItem('));
$a('PPD for 1099_individual, CCD for businesses',     $c($ap, "vendor_type'] === '1099_individual' ? 'ppd' : 'ccd'"));
$a('dispatches single rail batch',                    $c($ap, "paymentRailsDispatch('ap'"));
$a('atomic transaction wrap',                         $c($ap, '$pdo->beginTransaction()')
                                                  &&  $c($ap, '$pdo->commit()')
                                                  &&  $c($ap, '$pdo->rollBack()'));
$a('persists rail metadata on every payment',         $c($ap, "rail_external_ref   = :x"));
$a('audits ap.payment.batch_originated',              $c($ap, "'ap.payment.batch_originated'"));
$a('audits ap.payment.batch_originate_failed',        $c($ap, "'ap.payment.batch_originate_failed'"));
$a('returns combined NACHA file as base64',           $c($ap, 'nacha_file_b64'));
$a('returns canonical filename',                      $c($ap, "ap-batch-%s-%d.ach"));
$a('persists by external_ref keyed payments',         $c($ap, "ap_payment:' . \$r['id']"));

echo "\nap/api/export.php — bulk ?ids= filter\n";
$ex = (string) file_get_contents(__DIR__ . '/../modules/ap/api/export.php');
$datasets = (string) file_get_contents(__DIR__ . '/../core/export_datasets.php');
$a('parses ?ids=1,2,3',                              $c($ex, "explode(',', \$idsRaw)"));
$a('caps ids list at 1000',                          $c($ex, 'ids list too large (max 1000)'));
$a('legacy endpoint passes ids to governed datasets', $c($ex, "\$opts['ids'] = \$ids")
                                                  &&  $c($ex, "\$opts['line_ids'] = \$ids"));
$a('bills dataset honours ids',                      $c($datasets, "function exportDatasetFetchApBills")
                                                  &&  $c($datasets, "\$where[] = 'id IN ("));
$a('payments dataset honours ids',                   $c($datasets, "function exportDatasetFetchApPayments")
                                                  &&  $c($datasets, "\$where[] = 'p.id IN ("));
$a('expenses dataset filters by line_id (erl.id)',   $c($datasets, "\$where[] = 'erl.id IN ("));
$a('named placeholders avoid PDO param leak',        $c($datasets, "\$key = 'id' . \$i")
                                                  &&  $c($datasets, "\$key = 'line_id' . \$i"));

echo "\nuseBulkSelection hook\n";
$hk = (string) file_get_contents(__DIR__ . '/../dashboard/src/lib/useBulkSelection.js');
$a('exports useBulkSelection',                        $c($hk, 'export function useBulkSelection'));
$a('toggle / toggleAll / clear',                      $c($hk, 'toggle') && $c($hk, 'toggleAll') && $c($hk, 'clear'));
$a('reports allSelected + someSelected',              $c($hk, 'allSelected') && $c($hk, 'someSelected'));
$a('returns ids array',                               $c($hk, 'Array.from(set)'));

echo "\nPaymentsList — bulk-select + NACHA batch\n";
$pl = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/PaymentsList.jsx');
$a('imports useBulkSelection',                        $c($pl, "from '../../../dashboard/src/lib/useBulkSelection'"));
$a('row-level checkbox + select-all',                 $c($pl, 'data-testid="ap-payments-select-all"')
                                                  &&  $c($pl, 'ap-payment-select-${p.id}'));
$a('bulk-actions bar (NACHA copy intentionally removed 2026-02)',
    $c($pl, 'data-testid="ap-payments-bulk-bar"'));
$a('export-via-template picker present',              $c($pl, 'ExportTemplatePicker'));
$a('export selected button',                          $c($pl, 'data-testid="ap-payments-export-selected"'));
$a('triggers blob download for NACHA file',           $c($pl, 'new Blob') && $c($pl, 'res.nacha_filename'));
$a('eligibility = ach|plaid + draft|queued|sent w/o rail',
    $c($pl, "['ach','plaid'].includes(p.method)") &&
    $c($pl, "['draft','queued'].includes(p.status)") &&
    $c($pl, "(p.status === 'sent' && !p.rail_external_ref)"));
$a('clears selection on success',                     $c($pl, 'sel.clear()'));
$a('shows rail + rail_status column',                 $c($pl, 'p.disbursement_rail'));

echo "\nBillsList — bulk-select + Export selected\n";
$bl = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/BillsList.jsx');
$a('imports useBulkSelection',                        $c($bl, "from '../../../dashboard/src/lib/useBulkSelection'"));
$a('row-level checkbox + select-all',                 $c($bl, 'data-testid="ap-bills-select-all"')
                                                  &&  $c($bl, 'ap-bill-select-${r.id}'));
$a('export selected button',                          $c($bl, 'data-testid="ap-bills-export-selected"'));
$a('export-via-template picker present',              $c($bl, 'ExportTemplatePicker') && $c($bl, 'dataset="ap_bills"'));
$a('passes ids in CSV URL',                           $c($bl, 'type=bills&ids=${sel.ids.join'));

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
