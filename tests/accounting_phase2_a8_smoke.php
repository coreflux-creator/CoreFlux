<?php
/**
 * Accounting Phase 2 Sprint A.8 smoke test —
 * Consolidation lock/publish + NCI + entity pickers on create flows
 * + AR invoice IC split + period-reopen auto-reverse.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$c = fn (string $h, string $n): bool => strpos($h, $n) !== false;

echo "Migration 008_consolidation_runs.sql\n";
$mig = (string) file_get_contents(__DIR__ . '/../modules/accounting/migrations/008_consolidation_runs.sql');
$a('creates accounting_consolidation_runs',          $c($mig, 'CREATE TABLE IF NOT EXISTS accounting_consolidation_runs'));
$a('report_type enum (IS/BS/TB)',                    $c($mig, "ENUM('income_statement','balance_sheet','trial_balance')"));
$a('status enum locked/reversed/draft',              $c($mig, "ENUM('locked','reversed','draft')"));
$a('payload_json LONGTEXT',                          $c($mig, 'payload_json          LONGTEXT'));
$a('entity_ids_json captures scope',                 $c($mig, 'entity_ids_json'));
$a('locked_at / reversed_at / reverse_reason',       $c($mig, 'locked_at') && $c($mig, 'reversed_at') && $c($mig, 'reverse_reason'));
$a('adds intercompany_group_id on billing_invoices', $c($mig, 'billing_invoices') && $c($mig, 'ADD COLUMN intercompany_group_id'));
$a('utf8mb4_unicode_ci (no 0900_ai_ci)',             $c($mig, 'utf8mb4_unicode_ci') && stripos($mig, 'utf8mb4_0900_ai_ci') === false);

echo "\nlib/consolidation.php — lock + NCI\n";
$lib = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/consolidation.php');
$a('consolidationLockRun declared',                  $c($lib, 'function consolidationLockRun'));
$a('consolidationReverseRun declared',               $c($lib, 'function consolidationReverseRun'));
$a('consolidationListRuns declared',                 $c($lib, 'function consolidationListRuns'));
$a('consolidationGetRun declared',                   $c($lib, 'function consolidationGetRun'));
$a('Lock persists JSON payload',                     $c($lib, 'INSERT INTO accounting_consolidation_runs') && $c($lib, 'json_encode($payload'));
$a('Lock fallback to root_entity_id tree',           $c($lib, "entityRelationshipResolveDescendants") && $c($lib, "\$input['root_entity_id']"));
$a('Lock rejects invalid report_type',               $c($lib, 'report_type must be income_statement|balance_sheet|trial_balance'));
$a('Reverse requires reason',                        $c($lib, 'function consolidationReverseRun') && $c($lib, "trim(\$reason) === ''"));
$a('Reverse refuses non-locked runs',                $c($lib, 'Cannot reverse from status'));
$a('audits run_locked',                              $c($lib, "'accounting.consolidation.run_locked'"));
$a('audits run_reversed',                            $c($lib, "'accounting.consolidation.run_reversed'"));

echo "\n  NCI breakout on Balance Sheet\n";
$a('BS queries ownership_pct for each in-scope entity',
    $c($lib, 'FROM accounting_entity_relationships') && $c($lib, "WHERE tenant_id = :t AND child_entity_id = :c"));
$a('BS skips full-owned (pct == 100)',               $c($lib, '$pct >= 100.0'));
$a('BS skips non-full consolidation methods',        $c($lib, "\$edge['consolidation_method'] !== 'full'"));
$a('BS computes NCI as (100-pct)% of child equity',  $c($lib, '(100 - $pct) / 100.0'));
$a('BS returns controlling_equity + nci_equity + nci_detail',
    $c($lib, "'controlling_equity'") && $c($lib, "'nci_equity'") && $c($lib, "'nci_detail'"));

// Pure-function validation
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';
require_once __DIR__ . '/../modules/accounting/lib/consolidation.php';
try { consolidationLockRun(1, ['report_type' => 'bogus', 'entity_ids' => [1]], null); $a('lock rejects invalid report_type', false); }
catch (\InvalidArgumentException $e) { $a('lock rejects invalid report_type', true); }
try { consolidationLockRun(1, ['report_type' => 'balance_sheet', 'entity_ids' => []], null); $a('lock rejects empty scope', false); }
catch (\InvalidArgumentException $e) { $a('lock rejects empty scope', true); }
try { consolidationReverseRun(1, 999, '', null); $a('reverse rejects blank reason', false); }
catch (\InvalidArgumentException $e) { $a('reverse rejects blank reason', true); }

echo "\napi/consolidation_runs.php\n";
$api = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/consolidation_runs.php');
$a('GET list + GET id + POST lock + POST reverse',
    $c($api, "if (\$method === 'GET' && !empty(\$_GET['id']))") &&
    $c($api, "if (\$method === 'GET')") &&
    $c($api, "\$action === 'lock'") &&
    $c($api, "\$action === 'reverse'"));
$a('list/detail gates on accounting.reports.view',   $c($api, "'accounting.reports.view'"));
$a('lock/reverse gates on accounting.reports.export',$c($api, "'accounting.reports.export'"));

echo "\nperiods.php — reopen auto-reverses locked runs\n";
$per = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/periods.php');
$a('pulls consolidation lib on reopen',              $c($per, "require_once __DIR__ . '/../lib/consolidation.php'"));
$a('queries locked runs in the reopened period',     $c($per, 'accounting_consolidation_runs') && $c($per, "status = \"locked\""));
$a('calls consolidationReverseRun with reason',      $c($per, 'consolidationReverseRun(') && $c($per, 'Period reopened: '));
$a('audits runs_auto_reversed',                      $c($per, "'accounting.consolidation.runs_auto_reversed'"));

echo "\nConsolidation.jsx — Lock UI\n";
$ui = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/Consolidation.jsx');
$a('Lock & publish button',                          $c($ui, 'accounting-consol-lock') && $c($ui, '🔒 Lock & publish'));
$a('Past runs table',                                $c($ui, 'accounting-consol-runs') && $c($ui, 'accounting-consol-run-'));
$a('Per-run reverse button',                         $c($ui, 'accounting-consol-run-reverse-'));
$a('Controlling + NCI rows on BS view',              $c($ui, 'accounting-consol-controlling-equity') && $c($ui, 'accounting-consol-nci-equity'));

echo "\nBilling invoices.php — post_with_ic_split\n";
$inv = (string) file_get_contents(__DIR__ . '/../modules/billing/api/invoices.php');
$a('action=post_with_ic_split handler',              $c($inv, "\$action === 'post_with_ic_split'"));
$a('requires billing.invoice.approve + je.post',     $c($inv, "'billing.invoice.approve'") && $c($inv, "'accounting.je.post'"));
$a('idempotency on ic:invoice:<id>',                 $c($inv, "'ic:invoice:%d'"));
$a('posts AR side=debit (money owed TO us)',         $c($inv, "'side'         => 'debit'"));
$a('links group + source je_id to invoice row',      $c($inv, 'UPDATE billing_invoices SET journal_entry_id = :j, intercompany_group_id = :g'));
$a('emits billing.invoice.posted_ic audit',          $c($inv, "'billing.invoice.posted_ic'"));

echo "\nEntity pickers on create flows\n";
$ep = (string) file_get_contents(__DIR__ . '/../dashboard/src/components/EntityPicker.jsx');
$a('EntityPicker component exists',                  $c($ep, 'export default function EntityPicker'));

$bc = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/BillCreate.jsx');
$a('BillCreate imports EntityPicker',                $c($bc, "from '../../../dashboard/src/components/EntityPicker'"));
$a('BillCreate form has entity-picker test-id',      $c($bc, 'ap-bill-create-entity'));
$a('BillCreate payload includes entity_id',          $c($bc, 'entity_id: entityId'));

$ic = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/InvoiceCreate.jsx');
$a('InvoiceCreate imports EntityPicker',             $c($ic, "from '../../../dashboard/src/components/EntityPicker'"));
$a('InvoiceCreate has entity-picker test-id',        $c($ic, 'billing-invoice-create-entity'));
$a('InvoiceCreate payload includes entity_id',       $c($ic, 'entity_id: entityId'));

$pc = (string) file_get_contents(__DIR__ . '/../modules/people/ui/PersonCreate.jsx');
$a('PersonCreate imports EntityPicker',              $c($pc, "from '../../../dashboard/src/components/EntityPicker'"));
$a('PersonCreate has entity-picker test-id',         $c($pc, 'person-create-entity'));
$a('PersonCreate form state carries entity_id',      $c($pc, 'entity_id: null'));

echo "\nBackend accepts entity_id in create payload\n";
$apiBills = (string) file_get_contents(__DIR__ . '/../modules/ap/api/bills.php');
$a("ap_bills INSERT includes entity_id",             $c($apiBills, "'entity_id'         => !empty(\$body['entity_id'])"));
$apiInv = $inv;
$a("billing_invoices INSERT includes entity_id",     $c($apiInv, "'entity_id'         => !empty(\$body['entity_id'])"));
$apiPpl = (string) file_get_contents(__DIR__ . '/../modules/people/api/people.php');
$a("people INSERT accepts entity_id",                $c($apiPpl, "\$insert['entity_id'] = (int) \$body['entity_id']"));

echo "\nmanifests\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/accounting/manifest.php');
$a('run_locked audit',                               $c($man, "'accounting.consolidation.run_locked'"));
$a('run_reversed audit',                             $c($man, "'accounting.consolidation.run_reversed'"));
$a('runs_auto_reversed audit',                       $c($man, "'accounting.consolidation.runs_auto_reversed'"));
$billingMan = (string) file_get_contents(__DIR__ . '/../modules/billing/manifest.php');
$a('billing.invoice.posted_ic audit',                $c($billingMan, "'billing.invoice.posted_ic'"));

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
