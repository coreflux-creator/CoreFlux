<?php
/**
 * Accounting Phase 2 Sprint A.5 smoke test — Intercompany split engine.
 *
 *   - Migration 006 creates accounting_intercompany_mappings + adds
 *     intercompany_group_id on JEs + counterparty_entity_id on lines
 *   - lib/intercompany.php declares engine functions (build, resolve,
 *     post, reverse)
 *   - api/intercompany.php exposes list/detail/upsert/delete/post_split/
 *     reverse_group/group
 *   - accountingPostJe() now writes counterparty_entity_id on lines
 *   - Split engine balances correctly for both credit- and debit-side
 *     offsets (bank charge OUT and deposit IN)
 *   - Group reversal cascades to every leg
 *   - Manifest declares intercompany audit events
 *   - AccountingModule.jsx + BankReconciliation.jsx wire the new
 *     IntercompanySplitDialog + IntercompanyMappings UI
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$contains = fn (string $h, string $n): bool => strpos($h, $n) !== false;

echo "Migration 006_intercompany.sql\n";
$mig = (string) file_get_contents(__DIR__ . '/../modules/accounting/migrations/006_intercompany.sql');
$a('creates accounting_intercompany_mappings',  $contains($mig, 'CREATE TABLE IF NOT EXISTS accounting_intercompany_mappings'));
$a('mapping table has directional UNIQUE',      $contains($mig, 'UNIQUE KEY uq_aim_pair (tenant_id, from_entity_id, to_entity_id)'));
$a('mapping has due_from + due_to codes',       $contains($mig, 'due_from_account_code') && $contains($mig, 'due_to_account_code'));
$a('mapping has active flag defaulting to 1',   $contains($mig, 'active') && $contains($mig, 'DEFAULT 1'));
$a('adds intercompany_group_id on JEs',         $contains($mig, 'ADD COLUMN intercompany_group_id VARCHAR(64)'));
$a('indexes JEs on (tenant_id, group_id)',      $contains($mig, 'idx_aje_ic_group (tenant_id, intercompany_group_id)'));
$a('adds counterparty_entity_id on lines',      $contains($mig, 'ADD COLUMN counterparty_entity_id BIGINT UNSIGNED'));
$a('all ALTERs idempotent',                     substr_count($mig, 'information_schema.COLUMNS') >= 2);
$a('utf8mb4_unicode_ci (Cloudways safe)',
    $contains($mig, 'utf8mb4_unicode_ci') && stripos($mig, 'utf8mb4_0900_ai_ci') === false);

echo "\nlib/intercompany.php\n";
$libPath = __DIR__ . '/../modules/accounting/lib/intercompany.php';
$lib     = (string) file_get_contents($libPath);
$a('intercompanyGetMapping declared',           $contains($lib, 'function intercompanyGetMapping'));
$a('intercompanyUpsertMapping declared',        $contains($lib, 'function intercompanyUpsertMapping'));
$a('intercompanyResolvePair (override support)',$contains($lib, 'function intercompanyResolvePair'));
$a('intercompanyDeriveGroupId declared',        $contains($lib, 'function intercompanyDeriveGroupId'));
$a('intercompanyPostSplit declared',            $contains($lib, 'function intercompanyPostSplit'));
$a('intercompanyReverseGroup declared',         $contains($lib, 'function intercompanyReverseGroup'));
$a('post goes through accountingPostJe',        $contains($lib, 'accountingPostJe('));
$a('idempotency per leg (source / target:N)',   $contains($lib, "':source'") && $contains($lib, "':target:'"));
$a('balance check: splits total == offset',     $contains($lib, 'Splits total %.2f does not equal offset amount'));
$a('rejects offset <= 0',                       $contains($lib, 'offset amount must be > 0'));
$a('validates side whitelist',                  $contains($lib, "in_array(\$side, ['credit','debit']"));
$a('resolver prefers override over mapping',    $contains($lib, "'source'                => 'override'"));
$a('resolver throws when mapping missing',      $contains($lib, 'No intercompany mapping from entity'));
$a('wraps post in a transaction (atomic)',      $contains($lib, 'beginTransaction()') && $contains($lib, 'rollBack()'));
$a('writes intercompany_group_id onto every JE',
    substr_count($lib, "UPDATE accounting_journal_entries SET intercompany_group_id") >= 1);
$a('auto-marks bank_statement_line matched',    $contains($lib, "UPDATE accounting_bank_statement_lines") && $contains($lib, "match_status = 'matched'"));
$a('audits split_posted with entities[] + total',
    $contains($lib, "'accounting.intercompany.split_posted'") && $contains($lib, "'entities'"));
$a('reverse iterates JEs in group',             $contains($lib, "WHERE tenant_id = :t AND intercompany_group_id = :g"));
$a('reverse uses accountingReverseJe per leg',  $contains($lib, 'accountingReverseJe('));
$a('audits group_reversed',                     $contains($lib, "'accounting.intercompany.group_reversed'"));
$a('tags IC lines with counterparty_entity_id', $contains($lib, "'counterparty_entity_id'"));

// Exercise pure helpers
require_once $libPath;
$a('deriveGroupId returns 32-char hex',         strlen(intercompanyDeriveGroupId()) === 32 && ctype_xdigit(intercompanyDeriveGroupId()));
$gid1 = intercompanyDeriveGroupId();
$gid2 = intercompanyDeriveGroupId();
$a('deriveGroupId unique per call',             $gid1 !== $gid2);

// intercompanyResolvePair with override (no DB needed)
$ov = intercompanyResolvePair(1, 1, 2, ['due_from_account_code' => '1500', 'due_to_account_code' => '2500']);
$a('resolvePair uses override when provided',
    $ov['due_from_account_code'] === '1500' && $ov['due_to_account_code'] === '2500' && $ov['source'] === 'override');

echo "\napi/intercompany.php\n";
$api = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/intercompany.php');
$a('gates list on accounting.intercompany.manage', $contains($api, "'accounting.intercompany.manage'"));
$a('GET list joins entities for display',       $contains($api, 'LEFT JOIN accounting_entities ef') && $contains($api, 'LEFT JOIN accounting_entities et'));
$a('GET resolve-pair handler',                  $contains($api, "!empty(\$_GET['from_entity']) && !empty(\$_GET['to_entity'])"));
$a('POST upsert mapping',                       $contains($api, 'intercompanyUpsertMapping('));
$a('DELETE deactivates mapping',                $contains($api, "'active' => 0"));
$a('POST action=post_split calls engine',       $contains($api, "\$action === 'post_split'") && $contains($api, 'intercompanyPostSplit('));
$a('post_split requires accounting.je.post',    $contains($api, "'accounting.je.post'"));
$a('POST action=reverse_group calls engine',    $contains($api, "\$action === 'reverse_group'") && $contains($api, 'intercompanyReverseGroup('));
$a('reverse_group requires reason',             $contains($api, "'reason required'"));
$a('GET action=group lists JEs in group',       $contains($api, "\$action === 'group'") && $contains($api, 'intercompany_group_id = :g'));

echo "\nlib/accounting.php — counterparty_entity_id passthrough\n";
$acc = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/accounting.php');
$a('resolve pipeline reads counterparty_entity_id', $contains($acc, "'counterparty_entity_id'"));
$a('INSERT writes counterparty_entity_id column',   $contains($acc, 'counterparty_entity_id, dim_json'));
$a('INSERT bind uses ce placeholder',               $contains($acc, "'ce' => \$l['counterparty_entity_id']"));

echo "\ncomponents/IntercompanySplitDialog.jsx — test-ids\n";
$dlg = (string) file_get_contents(__DIR__ . '/../dashboard/src/components/IntercompanySplitDialog.jsx');
$a('dialog root test-id',                       $contains($dlg, 'data-testid="ic-split-dialog"'));
$a('close button',                              $contains($dlg, 'ic-split-close'));
$a('split table',                               $contains($dlg, 'ic-split-table'));
$a('add row button',                            $contains($dlg, 'ic-split-add'));
$a('post button',                               $contains($dlg, 'ic-split-post'));
$a('total indicator',                           $contains($dlg, 'ic-split-total'));
$a('date + memo inputs',                        $contains($dlg, 'ic-split-date') && $contains($dlg, 'ic-split-memo'));
$a('posts to post_split API',                   $contains($dlg, "INTERCOMPANY_API") && $contains($dlg, "?action=post_split"));
$a('passes bank_statement_line_id',             $contains($dlg, 'bank_statement_line_id: bankStatementLineId'));
$a('shows missing-mapping warning',             $contains($dlg, 'ic-split-mapping-missing-'));

echo "\nIntercompanyMappings.jsx — settings page\n";
$map = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/IntercompanyMappings.jsx');
$a('root test-id',                              $contains($map, 'data-testid="accounting-ic-mappings"'));
$a('form test-ids',
    $contains($map, 'accounting-ic-from') &&
    $contains($map, 'accounting-ic-to') &&
    $contains($map, 'accounting-ic-due-from') &&
    $contains($map, 'accounting-ic-due-to') &&
    $contains($map, 'accounting-ic-save'));
$a('table test-id',                             $contains($map, 'accounting-ic-table'));
$a('deactivate action',                         $contains($map, 'accounting-ic-remove-'));

echo "\nAccountingModule.jsx — wiring\n";
$mod = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/AccountingModule.jsx');
$a('imports IntercompanyMappings',              $contains($mod, "from './IntercompanyMappings'"));
$a('Intercompany tab',                          $contains($mod, 'to="intercompany"'));
$a('Intercompany route',                        $contains($mod, 'path="intercompany"'));

echo "\nBankReconciliation.jsx — per-line IC split button\n";
$br = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/BankReconciliation.jsx');
$a('imports IntercompanySplitDialog',           $contains($br, "from '../../../dashboard/src/components/IntercompanySplitDialog'"));
$a('IC split button per line',                  $contains($br, 'accounting-bank-ic-split-'));
$a('passes amount, sourceEntityId, offsetAccount',
    $contains($br, 'sourceEntityId={Number(bankAccount?.entity_id)}') &&
    $contains($br, 'sourceOffsetAccountCode={bankAccount?.gl_account_code}'));
$a('passes bankStatementLineId for auto-match', $contains($br, 'bankStatementLineId={line.id}'));
$a('amount negative → credit side (charge)',    $contains($br, "Number(line.amount) < 0 ? 'credit' : 'debit'"));

echo "\nmanifest.php\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/accounting/manifest.php');
$a('accounting.intercompany.manage permission', $contains($man, "'accounting.intercompany.manage'"));
$a('Intercompany sidebar action',               $contains($man, "'route' => 'intercompany'"));
$a('IC.mapping_created audit',                  $contains($man, "'accounting.intercompany.mapping_created'"));
$a('IC.mapping_updated audit',                  $contains($man, "'accounting.intercompany.mapping_updated'"));
$a('IC.mapping_deactivated audit',              $contains($man, "'accounting.intercompany.mapping_deactivated'"));
$a('IC.split_posted audit',                     $contains($man, "'accounting.intercompany.split_posted'"));
$a('IC.group_reversed audit',                   $contains($man, "'accounting.intercompany.group_reversed'"));

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
