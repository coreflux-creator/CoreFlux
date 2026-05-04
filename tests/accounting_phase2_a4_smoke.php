<?php
/**
 * Accounting Phase 2 Sprint A.4 smoke test —
 * CSV ledger import/export + Standard reports + Reconciliation packet.
 *
 *   - Migration 005 idempotent ALTERs for recon packet workflow columns
 *   - api/export.php streams CSV for all 10 report/ledger types
 *   - api/import.php wires dry_run + commit for coa/je/periods
 *   - api/standard_reports.php exposes gl_detail / unposted_jes /
 *     approval_queue / audit_log / account_activity
 *   - api/reconciliations.php wires list/detail/open/close/reopen/packet/
 *     generate_ai_narrative
 *   - lib/reconciliation_packet.php builds packet struct + AI narrative
 *   - AccountingModule.jsx routes /reports, /import, /bank-rec, /recurring
 *   - BankReconciliation.jsx exposes reconciliations list + packet routes
 *   - StandardReports.jsx + AccountingImport.jsx + ReconciliationPacket.jsx
 *     declare required test-ids
 *   - manifest.php declares new permissions + audit events
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$contains = fn (string $h, string $n): bool => strpos($h, $n) !== false;

echo "Migration 005_reconciliation_packet.sql\n";
$mig = (string) file_get_contents(__DIR__ . '/../modules/accounting/migrations/005_reconciliation_packet.sql');
$a('adds opened_at column',          $contains($mig, 'ADD COLUMN opened_at'));
$a('adds opened_by_user_id',         $contains($mig, 'ADD COLUMN opened_by_user_id'));
$a('adds reopened_at',               $contains($mig, 'ADD COLUMN reopened_at'));
$a('adds reopened_by_user_id',       $contains($mig, 'ADD COLUMN reopened_by_user_id'));
$a('adds reopen_reason',             $contains($mig, 'ADD COLUMN reopen_reason'));
$a('adds ai_narrative TEXT',         $contains($mig, 'ADD COLUMN ai_narrative TEXT'));
$a('adds ai_narrative_generated_at', $contains($mig, 'ADD COLUMN ai_narrative_generated_at'));
$a('all ALTERs idempotent (information_schema)', substr_count($mig, 'information_schema.COLUMNS') >= 7);
$a('utf8mb4_unicode_ci respected (no 0900_ai_ci)', stripos($mig, 'utf8mb4_0900_ai_ci') === false);

echo "\napi/export.php — CSV exports\n";
$ex = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/export.php');
$a('requires auth bootstrap + RBAC',            $contains($ex, "require_once __DIR__ . '/../../../core/api_bootstrap.php'") && $contains($ex, "require_once __DIR__ . '/../../../core/RBAC.php'"));
$a('gates on accounting.reports.export',        $contains($ex, "'accounting.reports.export'"));
$a('type=coa handler',                          $contains($ex, "\$type === 'coa'"));
$a('type=je handler',                           $contains($ex, "\$type === 'je'"));
$a('type=je_lines / gl_detail handler',         $contains($ex, "\$type === 'je_lines' || \$type === 'gl_detail'"));
$a('type=tb (trial balance) handler',           $contains($ex, "\$type === 'tb'") && $contains($ex, 'accountingTrialBalance('));
$a('type=periods handler',                      $contains($ex, "\$type === 'periods'"));
$a('type=bank_statements handler',              $contains($ex, "\$type === 'bank_statements'"));
$a('type=unposted_jes handler',                 $contains($ex, "\$type === 'unposted_jes'"));
$a('type=approval_queue handler',               $contains($ex, "\$type === 'approval_queue'"));
$a('type=audit_log handler',                    $contains($ex, "\$type === 'audit_log'"));
$a('type=account_activity with running balance',$contains($ex, "\$type === 'account_activity'") && $contains($ex, 'running_balance'));
$a('streams text/csv + Content-Disposition',    $contains($ex, "header('Content-Type: text/csv")  && $contains($ex, 'Content-Disposition: attachment'));
$a('emits accounting.ledger.exported audit',    $contains($ex, "'accounting.ledger.exported'"));

echo "\napi/import.php — CSV imports\n";
$im = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/import.php');
$a('uses CsvImportService',                     $contains($im, 'use Core\\CsvImportService'));
$a('gates on accounting.coa.manage',            $contains($im, "'accounting.coa.manage'"));
$a('registers accounting_coa schema',           $contains($im, "'accounting_coa'"));
$a('registers accounting_je schema',            $contains($im, "'accounting_je'"));
$a('registers accounting_periods schema',       $contains($im, "'accounting_periods'"));
$a('coa schema includes account_type enum',     $contains($im, "'asset','liability','equity','revenue','expense'"));
$a('action=template returns CSV',               $contains($im, "\$action === 'template'") && $contains($im, 'buildTemplate'));
$a('action=dry_run + commit handlers',          $contains($im, "'dry_run'") && $contains($im, "'commit'") && $contains($im, "in_array(\$action, ['dry_run','commit']"));
$a('coa commit UPSERTS by code',                $contains($im, 'UPDATE accounting_accounts SET'));
$a('je commit uses accountingPostJe',           $contains($im, 'accountingPostJe(') && $contains($im, "'idempotency_key' => 'csv:'"));
$a('je idempotency keyed by SHA-256(batch_ref)',$contains($im, "hash('sha256'"));
$a('periods commit UPSERTS by (entity_id, start_date)', $contains($im, 'entity_id = :e AND start_date = :sd'));
$a('emits accounting.ledger.imported audit',    $contains($im, "'accounting.ledger.imported'"));

echo "\napi/standard_reports.php\n";
$sr = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/standard_reports.php');
$a('gates on accounting.reports.view',          $contains($sr, "'accounting.reports.view'"));
$a('gl_detail reports posted JEs joined to accounts',
    $contains($sr, "\$type === 'gl_detail'") &&
    $contains($sr, "je.status = 'posted'") &&
    $contains($sr, 'accounting_journal_entry_lines'));
$a('unposted_jes handler',                      $contains($sr, "\$type === 'unposted_jes'") || $contains($sr, "\$type === 'unposted'"));
$a('approval_queue shows draft JEs',            $contains($sr, "\$type === 'approval_queue'") && $contains($sr, "status = 'draft'"));
$a('audit_log requires accounting.audit.view',  $contains($sr, "'accounting.audit.view'"));
$a('audit_log filters accounting.* events',     $contains($sr, "event LIKE 'accounting.%'"));
$a('account_activity returns ending_balance + running',
    $contains($sr, "\$type === 'account_activity'") &&
    $contains($sr, 'running_balance') &&
    $contains($sr, 'ending_balance'));

echo "\napi/reconciliations.php\n";
$rc = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/reconciliations.php');
$a('gates on accounting.bank.reconcile',        $contains($rc, "'accounting.bank.reconcile'"));
$a('action=open inserts recon row',             $contains($rc, "\$action === 'open'") && $contains($rc, 'INSERT INTO accounting_reconciliations'));
$a('action=close refuses already-closed',       $contains($rc, "\$action === 'close'") && $contains($rc, "'Already closed'"));
$a('action=reopen requires reason',             $contains($rc, "\$action === 'reopen'") && $contains($rc, 'reason required to reopen'));
$a('action=packet returns builder output',      $contains($rc, "\$action === 'packet'") && $contains($rc, 'reconciliationPacketBuild('));
$a('action=generate_ai_narrative calls lib',    $contains($rc, "\$action === 'generate_ai_narrative'") && $contains($rc, 'reconciliationPacketGenerateNarrative('));
$a('action=save_ai_narrative persists final content',
    $contains($rc, "\$action === 'save_ai_narrative'") &&
    $contains($rc, 'reconciliationPacketSaveNarrative(') &&
    $contains($rc, 'final_content'));
$a('emits recon.opened/closed/reopened audits',
    $contains($rc, "'accounting.reconciliation.opened'") &&
    $contains($rc, "'accounting.reconciliation.closed'") &&
    $contains($rc, "'accounting.reconciliation.reopened'"));
$a('emits packet_built audit',                  $contains($rc, "'accounting.reconciliation.packet_built'"));

echo "\nlib/reconciliation_packet.php\n";
$lp = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/reconciliation_packet.php');
$a('reconciliationPacketBuild declared',        $contains($lp, 'function reconciliationPacketBuild'));
$a('reconciliationPacketGenerateNarrative declared', $contains($lp, 'function reconciliationPacketGenerateNarrative'));
$a('reconciliationPacketSaveNarrative declared',$contains($lp, 'function reconciliationPacketSaveNarrative'));
$a('generate does NOT auto-persist',
    !preg_match('/function reconciliationPacketGenerateNarrative.*?\n\}/s', $lp, $m) ||
    strpos($m[0], 'UPDATE accounting_reconciliations') === false);
$a('save persists ai_narrative',
    $contains($lp, 'function reconciliationPacketSaveNarrative') &&
    $contains($lp, 'UPDATE accounting_reconciliations') &&
    $contains($lp, 'ai_narrative = :n'));
$a('builds matched + unmatched + totals',
    $contains($lp, "'matched'") && $contains($lp, "'unmatched'") && $contains($lp, "'totals'"));
$a('narrative goes through aiAsk chokepoint',   $contains($lp, 'aiAsk(') && $contains($lp, 'accounting.reconciliation.packet_narrative'));
$a('persists narrative onto recon row',         $contains($lp, 'UPDATE accounting_reconciliations') && $contains($lp, 'ai_narrative = :n'));
$a('narrative prompt forbids dollar figures',   $contains($lp, 'Do NOT restate'));

echo "\nAccountingModule.jsx — new tabs + routes\n";
$mod = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/AccountingModule.jsx');
$a('imports StandardReports',                   $contains($mod, "from './StandardReports'"));
$a('imports AccountingImport',                  $contains($mod, "from './AccountingImport'"));
$a('Reports tab',                               $contains($mod, 'to="reports"'));
$a('Import tab',                                $contains($mod, 'to="import"'));
$a('Reports route',                             $contains($mod, 'path="reports"'));
$a('Import route',                              $contains($mod, 'path="import"'));

echo "\nBankReconciliation.jsx — recon list + packet routes\n";
$br = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/BankReconciliation.jsx');
$a('imports ReconciliationPacket',              $contains($br, "from './ReconciliationPacket'"));
$a('reconciliations/:id route',                 $contains($br, 'path="reconciliations/:id"'));
$a('packet/:id route',                          $contains($br, 'path="packet/:id"'));
$a('reconciliations-list test-id',              $contains($br, 'data-testid="accounting-reconciliations-list"'));
$a('recon-open button test-id',                 $contains($br, 'accounting-recon-open'));
$a('recon packet link test-id',                 $contains($br, 'accounting-recon-packet-'));

echo "\nStandardReports.jsx — test-ids\n";
$ui = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/StandardReports.jsx');
$a('root test-id',                              $contains($ui, 'data-testid="accounting-standard-reports"'));
$a('5 tab test-ids',
    $contains($ui, 'accounting-report-tab-gl_detail') &&
    $contains($ui, 'accounting-report-tab-unposted_jes') &&
    $contains($ui, 'accounting-report-tab-approval_queue') &&
    $contains($ui, 'accounting-report-tab-audit_log') &&
    $contains($ui, 'accounting-report-tab-account_activity'));
$a('each tab has export button',
    $contains($ui, 'accounting-report-gl-detail-export') &&
    $contains($ui, 'accounting-report-unposted-export') &&
    $contains($ui, 'accounting-report-approval-export') &&
    $contains($ui, 'accounting-report-audit-export') &&
    $contains($ui, 'accounting-report-account-export'));
$a('GL detail table',                           $contains($ui, 'accounting-report-gl-detail-table'));
$a('Unposted table',                            $contains($ui, 'accounting-report-unposted-table'));
$a('Approval queue table',                      $contains($ui, 'accounting-report-approval-table'));
$a('Audit log table',                           $contains($ui, 'accounting-report-audit-table'));
$a('Account activity table',                    $contains($ui, 'accounting-report-account-table'));
$a('account activity requires code',            $contains($ui, 'accounting-report-account-code'));

echo "\nAccountingImport.jsx — test-ids\n";
$ai = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/AccountingImport.jsx');
$a('root test-id',                              $contains($ai, 'data-testid="accounting-import"'));
$a('type dropdown',                             $contains($ai, 'accounting-import-type'));
$a('download template',                         $contains($ai, 'accounting-import-download-template'));
$a('csv textarea',                              $contains($ai, 'accounting-import-csv'));
$a('dry-run button',                            $contains($ai, 'accounting-import-dry-run'));
$a('commit button',                             $contains($ai, 'accounting-import-commit'));
$a('skip-invalid checkbox',                     $contains($ai, 'accounting-import-skip-invalid'));
$a('dry result panel',                          $contains($ai, 'accounting-import-dry-result'));
$a('commit result panel',                       $contains($ai, 'accounting-import-commit-result'));

echo "\nReconciliationPacket.jsx — test-ids\n";
$rp = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/ReconciliationPacket.jsx');
$a('root test-id',                              $contains($rp, 'data-testid="accounting-reconciliation-packet"'));
$a('print button',                              $contains($rp, 'accounting-packet-print'));
$a('csv download link',                         $contains($rp, 'accounting-packet-csv'));
$a('summary table',                             $contains($rp, 'accounting-packet-summary'));
$a('matched + unmatched tables',
    $contains($rp, 'accounting-packet-matched-table') &&
    $contains($rp, 'accounting-packet-unmatched-table'));
$a('workflow kv',                               $contains($rp, 'accounting-packet-workflow'));
$a('AI narrative generate button',              $contains($rp, 'accounting-packet-generate-narrative'));
$a('uses <AISuggestion /> review component',
    $contains($rp, "from '../../../dashboard/src/components/AISuggestion'") &&
    $contains($rp, '<AISuggestion'));
$a('AISuggestion wired with featureKey + subject',
    $contains($rp, 'featureKey="accounting.reconciliation.packet_narrative"') &&
    $contains($rp, 'subjectType="accounting_reconciliation"'));
$a('onAccepted persists via save_ai_narrative',
    $contains($rp, 'action=save_ai_narrative'));
$a('close button',                              $contains($rp, 'accounting-packet-close'));
$a('reopen button + reason input',
    $contains($rp, 'accounting-packet-reopen-reason') &&
    $contains($rp, 'accounting-packet-reopen'));
$a('@media print CSS hides controls',           $contains($rp, '@media print') && $contains($rp, '.cf-no-print'));

echo "\nmanifest.php\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/accounting/manifest.php');
$a('accounting.ledger.import permission',       $contains($man, "'accounting.ledger.import'"));
$a('Standard Reports action',                   $contains($man, 'Standard Reports'));
$a('CSV Import action',                         $contains($man, 'CSV Import'));
$a('recon.opened audit',                        $contains($man, "'accounting.reconciliation.opened'"));
$a('recon.closed audit',                        $contains($man, "'accounting.reconciliation.closed'"));
$a('recon.reopened audit',                      $contains($man, "'accounting.reconciliation.reopened'"));
$a('recon.packet_built audit',                  $contains($man, "'accounting.reconciliation.packet_built'"));
$a('recon.ai_narrative_generated audit',        $contains($man, "'accounting.reconciliation.ai_narrative_generated'"));
$a('recon.ai_narrative_accepted audit',         $contains($man, "'accounting.reconciliation.ai_narrative_accepted'"));
$a('ledger.imported audit',                     $contains($man, "'accounting.ledger.imported'"));
$a('ledger.exported audit',                     $contains($man, "'accounting.ledger.exported'"));

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
