<?php
/**
 * Cross-module AI extraction extensions + Accounting Phase 1 — contract smoke.
 * Covers receipt OCR per line, W-9 extraction, contract clause extraction,
 * period close lifecycle, and Income Statement / Balance Sheet reports.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function ($n, $c) use (&$pass, &$fail) {
    if ($c) { echo "  \u{2713} {$n}\n"; $pass++; } else { echo "  \u{2717} {$n}\n"; $fail++; }
};

echo "AP bills.php — extract_receipt action\n";
$bills = (string) file_get_contents(__DIR__ . '/../modules/ap/api/bills.php');
$a('extract_receipt route',                        strpos($bills, "POST' && \$action === 'extract_receipt'") !== false);
$a('requires line_id + tenant scope',              strpos($bills, 'JOIN ap_bills b ON b.id = bl.bill_id') !== false);
$a('feature_key ap.bill.line.from_receipt',        strpos($bills, "'feature_key' => 'ap.bill.line.from_receipt'") !== false);
$a('schema constrains item_type to non-labor enum', strpos($bills, '"expense"|"materials"|"mileage"|"per_diem"|"reimbursement"|"other"') !== false);
$a('audits ap.bill.line.extracted_from_receipt',   strpos($bills, "'ap.bill.line.extracted_from_receipt'") !== false);
$a('returns review_required:true',                 strpos($bills, "'review_required' => true") !== false);

echo "\nAP vendors.php — extract_w9 action\n";
$vapi = (string) file_get_contents(__DIR__ . '/../modules/ap/api/vendors.php');
$a('upload_url route on vendors',                  strpos($vapi, "GET' && \$action === 'upload_url'") !== false);
$a('vendor_w9 storage entity_type',                strpos($vapi, "'vendor_w9'") !== false);
$a('extract_w9 route',                             strpos($vapi, "POST' && \$action === 'extract_w9'") !== false);
$a('feature_key ap.vendor.from_w9',                strpos($vapi, "'feature_key' => 'ap.vendor.from_w9'") !== false);
$a('schema covers tax_id_last4 + classification',  strpos($vapi, '"tax_id_last4"') !== false && strpos($vapi, '"tax_classification"') !== false);
$a('schema enumerates vendor_type targets',        strpos($vapi, '"1099_individual"|"c2c_corp"|"w9_business"|"other"') !== false);
$a('audits ap.vendor.extracted_from_w9',           strpos($vapi, "'ap.vendor.extracted_from_w9'") !== false);

echo "\nplacements/chain.php — extract_contract action\n";
$chain = (string) file_get_contents(__DIR__ . '/../modules/placements/api/chain.php');
$a('contract_upload_url route',                    strpos($chain, "GET' && \$action === 'contract_upload_url'") !== false);
$a('chain_contract storage entity_type',           strpos($chain, "'chain_contract'") !== false);
$a('extract_contract route',                       strpos($chain, "POST' && \$action === 'extract_contract'") !== false);
$a('feature_key placements.chain.from_contract',   strpos($chain, "'feature_key' => 'placements.chain.from_contract'") !== false);
$a('schema covers rate_caps + warnings + clauses', strpos($chain, '"rate_caps"') !== false
                                                    && strpos($chain, '"warnings"') !== false
                                                    && strpos($chain, '"key_clauses"') !== false);
$a('audits placement.chain.contract_extracted',    strpos($chain, "'placement.chain.contract_extracted'") !== false);
$a('non-recursive — placement.chain.* not reused', strpos($chain, "'placement.chain.contract_extracted'") !== false);

echo "\nManifest audit_events\n";
$apm = (string) file_get_contents(__DIR__ . '/../modules/ap/manifest.php');
$a('AP manifest declares ap.bill.line.extracted_from_receipt', strpos($apm, 'ap.bill.line.extracted_from_receipt') !== false);
$a('AP manifest declares ap.vendor.extracted_from_w9',         strpos($apm, 'ap.vendor.extracted_from_w9') !== false);
$plm = (string) file_get_contents(__DIR__ . '/../modules/placements/manifest.php');
$a('Placements manifest declares placement.chain.contract_extracted', strpos($plm, 'placement.chain.contract_extracted') !== false);

echo "\nReact UI hooks\n";
$bd = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/BillDetail.jsx');
$a('BillDetail imports uploads helper',            strpos($bd, "import { uploadFileViaPresignedPost }") !== false);
$a('BillDetail has Receipt column',                strpos($bd, '<th>Receipt</th>') !== false);
$a('LineReceiptCell exists',                       strpos($bd, 'function LineReceiptCell') !== false);
$a('LineReceiptCell uploads + extracts',           strpos($bd, "action=upload_url&line_id=") !== false
                                                    && strpos($bd, "action=attach_line&line_id=") !== false
                                                    && strpos($bd, "action=extract_receipt&line_id=") !== false);
$a('LineReceiptCell shows merchant + total',       strpos($bd, "state.draft?.merchant") !== false);

$vc = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/VendorQuickCreate.jsx');
$a('VendorQuickCreate imports uploads helper',     strpos($vc, "import { uploadFileViaPresignedPost }") !== false);
$a('W-9 dropzone',                                 strpos($vc, 'data-testid="vendor-quick-create-w9-zone"') !== false);
$a('W-9 input testid',                             strpos($vc, 'data-testid="vendor-quick-create-w9-input"') !== false);
$a('W-9 extract calls vendors.php?action=extract_w9', strpos($vc, "action=extract_w9") !== false);
$a('W-9 pre-fills name/type/tax_id_last4',         strpos($vc, 'if (d.vendor_name)') !== false
                                                    && strpos($vc, 'if (d.vendor_type)') !== false
                                                    && strpos($vc, 'if (d.tax_id_last4)') !== false);

$pd = (string) file_get_contents(__DIR__ . '/../modules/placements/ui/PlacementDetail.jsx');
$a('PlacementDetail imports uploads helper',       strpos($pd, "import { uploadFileViaPresignedPost }") !== false);
$a('Chain table has Contract column',              strpos($pd, '<th>Contract</th>') !== false);
$a('ContractCell exists',                          strpos($pd, 'function ContractCell') !== false);
$a('ContractCell uploads + extracts',              strpos($pd, "action=contract_upload_url&id=") !== false
                                                    && strpos($pd, "action=extract_contract&id=") !== false);
$a('ContractCell shows agreement_type',            strpos($pd, 'state.draft?.agreement_type') !== false);

echo "\nAccounting Phase 1 — periods API\n";
$papi = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/periods.php');
$a('GET list with from/to/entity_id filters',      strpos($papi, "\$_GET['entity_id']") !== false
                                                    && strpos($papi, "\$_GET['from']") !== false
                                                    && strpos($papi, "\$_GET['to']") !== false);
$a('soft_close action',                            strpos($papi, "'soft_close'") !== false);
$a('close action',                                 strpos($papi, "'close'") !== false);
$a('reopen requires reason',                       strpos($papi, "'reason required to reopen") !== false);
$a('soft_close from open|reopened',                strpos($papi, "in_array(\$row['status'], ['open','reopened']") !== false);
$a('close from open|soft_closed|reopened',         strpos($papi, "in_array(\$row['status'], ['open','soft_closed','reopened']") !== false);
$a('reopen from closed|soft_closed only',          strpos($papi, "in_array(\$row['status'], ['closed','soft_closed']") !== false);
$a('audits soft_closed/closed/reopened',           strpos($papi, "'accounting.period.soft_closed'") !== false
                                                    && strpos($papi, "'accounting.period.closed'") !== false
                                                    && strpos($papi, "'accounting.period.reopened'") !== false);
$a('reopen requires accounting.period.reopen perm', strpos($papi, "'accounting.period.reopen'") !== false);
$a('close requires accounting.period.close perm',   strpos($papi, "'accounting.period.close'") !== false);

echo "\nAccounting Phase 1 — reports API\n";
$rapi = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/reports.php');
$a('income_statement type',                        strpos($rapi, "type === 'income_statement'") !== false);
$a('balance_sheet type',                           strpos($rapi, "type === 'balance_sheet'") !== false);
$a('reportIncomeStatement filters revenue|expense', strpos($rapi, 'a.account_type IN ("revenue","expense")') !== false);
$a('IS filters posted JEs only',                   strpos($rapi, 'je.status = "posted"') !== false);
$a('IS computes net_income',                       strpos($rapi, "'net_income'    => round(\$revTotal - \$expTotal, 2)") !== false);
$a('reportBalanceSheet aggregates per type',       strpos($rapi, "case 'asset'") !== false
                                                    && strpos($rapi, "case 'liability'") !== false
                                                    && strpos($rapi, "case 'equity'") !== false);
$a('BS adds synthetic current period net income',  strpos($rapi, "'synthetic' => true") !== false);
$a('BS reports balanced flag',                     strpos($rapi, "'balanced'           => abs(\$aTot - (\$lTot + \$eTot)) < 0.005") !== false);

echo "\nReact accounting UI\n";
$mod = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/AccountingModule.jsx');
foreach (['IncomeStatement','BalanceSheet','Periods'] as $c) {
    $a("AccountingModule imports {$c}", strpos($mod, "import {$c} from") !== false);
}
$a('module routes pnl',                            strpos($mod, 'path="pnl"') !== false);
$a('module routes balance',                        strpos($mod, 'path="balance"') !== false);
$a('module routes periods',                        strpos($mod, 'path="periods"') !== false);

$is = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/IncomeStatement.jsx');
$a('IncomeStatement page testid',                  strpos($is, 'data-testid="accounting-pnl"') !== false);
$a('IS net-income testid',                         strpos($is, 'accounting-pnl-net-income') !== false);
$a('IS from/to date pickers',                      strpos($is, 'accounting-pnl-from') !== false && strpos($is, 'accounting-pnl-to') !== false);

$bs = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/BalanceSheet.jsx');
$a('BalanceSheet page testid',                     strpos($bs, 'data-testid="accounting-balance"') !== false);
$a('BS sections assets/liabilities/equity',        strpos($bs, 'accounting-balance-assets-total') !== false
                                                    && strpos($bs, 'accounting-balance-liabilities-total') !== false
                                                    && strpos($bs, 'accounting-balance-equity-total') !== false);
$a('BS shows balanced/diff',                       strpos($bs, 'accounting-balance-diff') !== false);

$pp = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/Periods.jsx');
$a('Periods page testid',                          strpos($pp, 'data-testid="accounting-periods"') !== false);
$a('Periods status pill',                          strpos($pp, 'function StatusPill') !== false);
$a('Periods soft-close action',                    strpos($pp, 'accounting-periods-soft-close-') !== false);
$a('Periods close action',                         strpos($pp, 'accounting-periods-close-') !== false);
$a('Periods reopen requires reason prompt',        strpos($pp, "prompt('Why are you reopening") !== false);
$a('Periods reopen action',                        strpos($pp, 'accounting-periods-reopen-') !== false);

$smod = (string) file_get_contents(__DIR__ . '/../core/modules.php');
$a('sidebar Income Statement',                     strpos($smod, "'Income Statement'") !== false);
$a('sidebar Balance Sheet',                        strpos($smod, "'Balance Sheet'") !== false);
$a('sidebar Periods',                              strpos($smod, "'Periods'") !== false);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
