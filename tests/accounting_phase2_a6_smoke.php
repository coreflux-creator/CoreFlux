<?php
/**
 * Accounting Phase 2 Sprint A.6 smoke test —
 * IC split wired into AP bills + Manual JE + Elimination worksheet.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$contains = fn (string $h, string $n): bool => strpos($h, $n) !== false;

echo "Migration ap/007_intercompany.sql\n";
$mig = (string) file_get_contents(__DIR__ . '/../modules/ap/migrations/007_intercompany.sql');
$a('idempotent ALTER via information_schema', $contains($mig, 'information_schema.COLUMNS'));
$a('adds intercompany_group_id on ap_bills',  $contains($mig, 'ADD COLUMN intercompany_group_id VARCHAR(64)'));
$a('indexes (tenant_id, group_id)',            $contains($mig, 'idx_apb_ic_group (tenant_id, intercompany_group_id)'));

echo "\nAP bills.php — post_with_ic_split action\n";
$bills = (string) file_get_contents(__DIR__ . '/../modules/ap/api/bills.php');
$a('action=post_with_ic_split handler',        $contains($bills, "\$action === 'post_with_ic_split'"));
$a('requires ap.bill.post AND accounting.je.post',
    $contains($bills, "'ap.bill.post'") && $contains($bills, "'accounting.je.post'"));
$a('short-circuits already-posted bills',      $contains($bills, 'idempotent_replay'));
$a('accepts dialog-native source.offset_line shape',
    $contains($bills, "\$body['source'] ?? null") && $contains($bills, "offset_line"));
$a('accepts slim entity_id + ap_account_code shape', $contains($bills, "'ap_account_code'"));
$a('calls intercompanyPostSplit',              $contains($bills, 'intercompanyPostSplit('));
$a('idempotency keyed on ic:bill:<id>',        $contains($bills, "'ic:bill:%d'"));
$a('links group_id + source je_id back to bill',
    $contains($bills, 'UPDATE ap_bills SET journal_entry_id = :j, intercompany_group_id = :g'));
$a('audits ap.bill.posted_ic',                 $contains($bills, "'ap.bill.posted_ic'"));

echo "\nlib/intercompany.php — elimination worksheet\n";
$lib = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/intercompany.php');
$a('intercompanyEliminationWorksheet declared',$contains($lib, 'function intercompanyEliminationWorksheet'));
$a('pulls IC groups from JEs',                 $contains($lib, 'intercompany_group_id IS NOT NULL'));
$a('computes per-pair totals from tagged lines',
    $contains($lib, 'l.counterparty_entity_id AS to_entity_id') &&
    $contains($lib, 'SUM(l.debit)'));
$a('computes imbalance_signed per pair',       $contains($lib, "'imbalance_signed'"));
$a('surfaces orphan IC-tagged lines (no group)',
    $contains($lib, 'intercompany_group_id IS NULL') &&
    $contains($lib, "'orphans'"));
$a('returns summary counts',                   $contains($lib, "'imbalanced_pairs'") && $contains($lib, "'orphan_line_count'"));

echo "\napi/intercompany.php — elimination endpoints\n";
$api = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/intercompany.php');
$a('GET action=elimination_worksheet',         $contains($api, "\$action === 'elimination_worksheet'") && $contains($api, 'intercompanyEliminationWorksheet('));
$a('gates on accounting.reports.view',         $contains($api, "'accounting.reports.view'"));
$a('POST action=narrate_elimination',          $contains($api, "\$action === 'narrate_elimination'"));
$a('narrative uses aiAsk chokepoint',          $contains($api, 'aiAsk(') && $contains($api, 'accounting.intercompany.elimination_narrative'));
$a('narrative prompt forbids dollar figures',  $contains($api, 'Do NOT restate'));
$a('audits elimination_viewed',                $contains($api, "'accounting.intercompany.elimination_viewed'"));
$a('audits elimination_narrative_generated',   $contains($api, "'accounting.intercompany.elimination_narrative_generated'"));

echo "\ncomponents/IntercompanySplitDialog.jsx — postUrl override\n";
$dlg = (string) file_get_contents(__DIR__ . '/../dashboard/src/components/IntercompanySplitDialog.jsx');
$a('accepts postUrl prop (defaults to IC engine)',
    $contains($dlg, "postUrl = '/modules/accounting/api/intercompany.php?action=post_split'"));
$a('submits to props.postUrl not hardcoded',   $contains($dlg, 'api.post(postUrl'));

echo "\nAP BillDetail.jsx — IC split button\n";
$bd = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/BillDetail.jsx');
$a('imports IntercompanySplitDialog',          $contains($bd, "from '../../../dashboard/src/components/IntercompanySplitDialog'"));
$a('Post-with-IC-split button',                $contains($bd, 'ap-bill-post-ic-split'));
$a('dialog postUrl points at bills endpoint',  $contains($bd, "postUrl={`/modules/ap/api/bills.php?action=post_with_ic_split&id=\${id}`}"));
$a('passes AP liability code 2000',            $contains($bd, 'sourceOffsetAccountCode="2000"'));
$a('side credit (bill = AP owed)',             $contains($bd, 'sourceOffsetSide="credit"'));

echo "\nJournalEntryCreate.jsx — split across entities\n";
$je = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/JournalEntryCreate.jsx');
$a('imports IntercompanySplitDialog',          $contains($je, "from '../../../dashboard/src/components/IntercompanySplitDialog'"));
$a('Split-across-entities button',             $contains($je, 'accounting-je-ic-split'));
$a('seeds dialog from current JE lines',       $contains($je, 'setIcSeed(') && $contains($je, "sourceOffsetAccountCode: offsetRow.account_code"));

echo "\nEliminationWorksheet.jsx — UI test-ids\n";
$ui = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/EliminationWorksheet.jsx');
$a('root test-id',                             $contains($ui, 'data-testid="accounting-ic-elimination"'));
$a('date filters',                             $contains($ui, 'accounting-ic-elim-from') && $contains($ui, 'accounting-ic-elim-to'));
$a('refresh button',                           $contains($ui, 'accounting-ic-elim-refresh'));
$a('CSV export',                               $contains($ui, 'accounting-ic-elim-csv'));
$a('narrate button',                           $contains($ui, 'accounting-ic-elim-narrate'));
$a('stats tiles',
    $contains($ui, 'accounting-ic-elim-stat-groups') &&
    $contains($ui, 'accounting-ic-elim-stat-pairs') &&
    $contains($ui, 'accounting-ic-elim-stat-imbalanced') &&
    $contains($ui, 'accounting-ic-elim-stat-orphans'));
$a('pairs + groups + orphans tables',
    $contains($ui, 'accounting-ic-elim-pairs-table') &&
    $contains($ui, 'accounting-ic-elim-groups-table') &&
    $contains($ui, 'accounting-ic-elim-orphans-table'));
$a('AI narrative panel',                       $contains($ui, 'accounting-ic-elim-narrative'));

echo "\nAccountingModule.jsx — Elimination tab\n";
$mod = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/AccountingModule.jsx');
$a('imports EliminationWorksheet',             $contains($mod, "from './EliminationWorksheet'"));
$a('Elimination tab',                          $contains($mod, 'to="elimination"'));
$a('Elimination route',                        $contains($mod, 'path="elimination"'));

echo "\nmanifests\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/accounting/manifest.php');
$a('accounting.intercompany.elimination_viewed audit',
    $contains($man, "'accounting.intercompany.elimination_viewed'"));
$a('accounting.intercompany.elimination_narrative_generated audit',
    $contains($man, "'accounting.intercompany.elimination_narrative_generated'"));
$apMan = (string) file_get_contents(__DIR__ . '/../modules/ap/manifest.php');
$a('ap.bill.posted_ic audit',                  $contains($apMan, "'ap.bill.posted_ic'"));

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
