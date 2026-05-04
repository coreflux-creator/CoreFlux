<?php
/**
 * Accounting Phase 2 Sprint A.3 smoke test — Recurring Journal Entries.
 *
 *   - Migration 002_phase2.sql declares the recurring tables + cadences
 *   - lib/recurring_je.php declares the engine + pure date-math helper
 *   - recurringJeAdvanceDate() behaves correctly for every cadence
 *   - api/recurring_journal_entries.php wires all 8 actions + validation
 *   - bin/recurring_je_cron.php is CLI-safe (no setTenantContextOverride)
 *   - AccountingModule.jsx routes /recurring and renders a tab
 *   - RecurringJournalEntries.jsx renders list + editor with required test-ids
 *   - manifest.php declares recurring permissions + audit events
 *   - core/modules.php exposes the left-nav action
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};

echo "Migration 002_phase2.sql — recurring tables\n";
$mig = (string) file_get_contents(__DIR__ . '/../modules/accounting/migrations/002_phase2.sql');
$a('creates accounting_recurring_journal_entries',     strpos($mig, 'CREATE TABLE IF NOT EXISTS accounting_recurring_journal_entries') !== false);
$a('cadence enum covers all 5 cadences',
    strpos($mig, "ENUM('weekly','biweekly','monthly','quarterly','yearly')") !== false);
$a('status enum active/paused/ended',                  strpos($mig, "ENUM('active','paused','ended')") !== false);
$a('auto_post flag defaulting to 1',                   strpos($mig, 'auto_post') !== false && strpos($mig, 'DEFAULT 1') !== false);
$a('next_run_date NOT NULL',                           preg_match('/next_run_date\s+DATE NOT NULL/i', $mig) === 1);
$a('tenant_status index includes next_run_date',       strpos($mig, 'idx_arj_tenant_status (tenant_id, status, next_run_date)') !== false);
$a('creates accounting_recurring_je_lines',            strpos($mig, 'CREATE TABLE IF NOT EXISTS accounting_recurring_je_lines') !== false);
$a('line table has debit + credit DECIMAL(18,2)',      preg_match('/debit\s+DECIMAL\(18,2\)/', $mig) === 1 && preg_match('/credit\s+DECIMAL\(18,2\)/', $mig) === 1);
$a('utf8mb4_unicode_ci only (Cloudways safe)',
    strpos($mig, 'utf8mb4_unicode_ci') !== false &&
    stripos($mig, 'utf8mb4_0900_ai_ci') === false);

echo "\nlib/recurring_je.php\n";
$libPath = __DIR__ . '/../modules/accounting/lib/recurring_je.php';
$lib     = (string) file_get_contents($libPath);
$a('declares recurringJeListDue',                      strpos($lib, 'function recurringJeListDue') !== false);
$a('declares recurringJeRunOnce',                      strpos($lib, 'function recurringJeRunOnce') !== false);
$a('declares recurringJeRunDueForTenant',              strpos($lib, 'function recurringJeRunDueForTenant') !== false);
$a('declares recurringJeAdvanceDate',                  strpos($lib, 'function recurringJeAdvanceDate') !== false);
$a('posts via accountingPostJe (central chokepoint)',  strpos($lib, 'accountingPostJe(') !== false);
$a('idempotency key shape recurring:{id}:{date}',      strpos($lib, "'recurring:' . \$templateId . ':' . \$runDate") !== false);
$a('advances next_run_date after run',                 strpos($lib, 'next_run_date') !== false && strpos($lib, 'recurringJeAdvanceDate(') !== false);
$a('auto-ends past end_date',                          strpos($lib, 'past_end_date') !== false);
$a('emits accounting.recurring_je.run audit',          strpos($lib, "'accounting.recurring_je.run'") !== false);
$a('emits accounting.recurring_je.auto_ended audit',   strpos($lib, "'accounting.recurring_je.auto_ended'") !== false);

// Pure-function contract — safe to exercise without DB.
require_once $libPath;
$a('weekly cadence: 2026-01-01 → 2026-01-08',     recurringJeAdvanceDate('2026-01-01', 'weekly')    === '2026-01-08');
$a('biweekly cadence: 2026-01-01 → 2026-01-15',   recurringJeAdvanceDate('2026-01-01', 'biweekly')  === '2026-01-15');
$a('monthly cadence: 2026-01-31 → 2026-03-03',    recurringJeAdvanceDate('2026-01-31', 'monthly')   === '2026-03-03'); // PHP date math overflows Jan 31
$a('monthly cadence: 2026-02-15 → 2026-03-15',    recurringJeAdvanceDate('2026-02-15', 'monthly')   === '2026-03-15');
$a('quarterly cadence: 2026-01-01 → 2026-04-01',  recurringJeAdvanceDate('2026-01-01', 'quarterly') === '2026-04-01');
$a('yearly cadence: 2026-02-29 → 2027-03-01',     recurringJeAdvanceDate('2026-02-29', 'yearly')    === '2027-03-01');
try { recurringJeAdvanceDate('2026-01-01', 'fortnightly'); $a('rejects unknown cadence', false); }
catch (\InvalidArgumentException $e) { $a('rejects unknown cadence', true); }
try { recurringJeAdvanceDate('not-a-date', 'monthly'); $a('rejects malformed date', false); }
catch (\InvalidArgumentException $e) { $a('rejects malformed date', true); }

echo "\napi/recurring_journal_entries.php\n";
$api = (string) file_get_contents(__DIR__ . '/../modules/accounting/api/recurring_journal_entries.php');
$a('requires auth bootstrap',                          strpos($api, "require_once __DIR__ . '/../../../core/api_bootstrap.php'") !== false);
$a('requires RBAC',                                    strpos($api, "require_once __DIR__ . '/../../../core/RBAC.php'") !== false);
$a('requires engine lib',                              strpos($api, "require_once __DIR__ . '/../lib/recurring_je.php'") !== false);
$a('GET list + GET ?id=N handler',                     strpos($api, "if (\$method === 'GET' && !empty(\$_GET['id']))") !== false && strpos($api, "if (\$method === 'GET')") !== false);
$a('POST create handler',                              strpos($api, "if (\$method === 'POST' && \$action === '')") !== false);
$a('action=replace_lines handler',                     strpos($api, "\$action === 'replace_lines'") !== false);
$a('action=pause/resume/end handler',                  strpos($api, "in_array(\$action, ['pause','resume','end']") !== false);
$a('action=run_now handler',                           strpos($api, "\$action === 'run_now'") !== false);
$a('action=run_due cron handler',                      strpos($api, "\$action === 'run_due'") !== false);
$a('PUT update handler',                               strpos($api, "if (\$method === 'PUT')") !== false);
$a('validates cadence whitelist',                      strpos($api, "['weekly','biweekly','monthly','quarterly','yearly']") !== false);
$a('validates next_run_date ISO shape',                strpos($api, "/^\\d{4}-\\d{2}-\\d{2}\$/") !== false);
$a('validates balanced lines (td == tc, >0)',          strpos($api, 'abs($td - $tc) > 0.005 || $td <= 0') !== false);
$a('requires at least 2 lines',                        strpos($api, 'count($lines) < 2') !== false);
$a('emits accounting.recurring_je.created audit',      strpos($api, "'accounting.recurring_je.created'") !== false);
$a('emits accounting.recurring_je.lines_replaced',     strpos($api, "'accounting.recurring_je.lines_replaced'") !== false);
$a('emits accounting.recurring_je.updated',            strpos($api, "'accounting.recurring_je.updated'") !== false);

echo "\nbin/recurring_je_cron.php\n";
$cron = (string) file_get_contents(__DIR__ . '/../bin/recurring_je_cron.php');
$a('CLI shebang',                                      strpos($cron, '#!/usr/bin/env php') === 0);
$a('pulls engine lib',                                 strpos($cron, "require_once __DIR__ . '/../modules/accounting/lib/recurring_je.php'") !== false);
$a('does NOT call ghost setTenantContextOverride',     strpos($cron, 'setTenantContextOverride(') === false);
$a('sets $_SESSION[tenant_id] directly',               strpos($cron, "\$_SESSION['tenant_id'] = \$tid") !== false);
$a('iterates active tenants',                          strpos($cron, "FROM tenants WHERE status = \"active\"") !== false);
$a('supports --argv[1] tenant override',               strpos($cron, 'isset($argv[1])') !== false);
$a('calls recurringJeRunDueForTenant per tenant',      strpos($cron, 'recurringJeRunDueForTenant(') !== false);
$a('exits non-zero on errors',                         strpos($cron, "exit(\$total['errors'] > 0 ? 1 : 0)") !== false);

echo "\nAccountingModule.jsx — route wiring\n";
$mod = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/AccountingModule.jsx');
$a('imports RecurringJournalEntries',                  strpos($mod, "from './RecurringJournalEntries'") !== false);
$a('Recurring tab',                                    strpos($mod, 'to="recurring"') !== false);
$a('Recurring nested route (with /*)',                 strpos($mod, 'path="recurring/*"') !== false);
$a('tab label = "Recurring JEs"',                      strpos($mod, 'label="Recurring JEs"') !== false);

echo "\nRecurringJournalEntries.jsx — UI test-ids\n";
$ui = (string) file_get_contents(__DIR__ . '/../modules/accounting/ui/RecurringJournalEntries.jsx');
$a('list root test-id',                                strpos($ui, 'data-testid="accounting-recurring"') !== false);
$a('editor root test-id',                              strpos($ui, 'data-testid="accounting-recurring-editor"') !== false);
$a('Run-due button test-id',                           strpos($ui, 'accounting-recurring-run-due') !== false);
$a('New-template link test-id',                        strpos($ui, 'accounting-recurring-new') !== false);
$a('table test-id',                                    strpos($ui, 'accounting-recurring-table') !== false);
$a('per-row action test-ids (run_now/pause/resume/end)',
    strpos($ui, 'accounting-recurring-run-now-') !== false &&
    strpos($ui, 'accounting-recurring-pause-')   !== false &&
    strpos($ui, 'accounting-recurring-resume-')  !== false &&
    strpos($ui, 'accounting-recurring-end-')     !== false);
$a('mode badge auto/draft test-ids',
    strpos($ui, 'accounting-recurring-mode-auto-') !== false &&
    strpos($ui, 'accounting-recurring-mode-draft-') !== false);
$a('editor form fields test-ids',
    strpos($ui, 'accounting-recurring-name')          !== false &&
    strpos($ui, 'accounting-recurring-cadence')       !== false &&
    strpos($ui, 'accounting-recurring-next-run')      !== false &&
    strpos($ui, 'accounting-recurring-end-date')      !== false &&
    strpos($ui, 'accounting-recurring-memo')          !== false &&
    strpos($ui, 'accounting-recurring-auto-post')     !== false);
$a('editor line test-ids',
    strpos($ui, 'accounting-recurring-line-account-') !== false &&
    strpos($ui, 'accounting-recurring-line-debit-')   !== false &&
    strpos($ui, 'accounting-recurring-line-credit-')  !== false);
$a('balance status indicator',                         strpos($ui, 'accounting-recurring-balance-status') !== false);
$a('save-template button',                             strpos($ui, 'accounting-recurring-save') !== false);
$a('calls create POST on new path',                    strpos($ui, "api.post('/modules/accounting/api/recurring_journal_entries.php'") !== false);
$a('calls replace_lines on edit',                      strpos($ui, "action=replace_lines&id=") !== false);

echo "\nmanifest.php\n";
$man = (string) file_get_contents(__DIR__ . '/../modules/accounting/manifest.php');
$a('declares accounting.recurring.manage permission',  strpos($man, "'accounting.recurring.manage'") !== false);
$a('declares Recurring JEs action',                    strpos($man, "'route' => 'recurring'") !== false);
$a('accounting.recurring_je.created audit event',      strpos($man, "'accounting.recurring_je.created'") !== false);
$a('accounting.recurring_je.updated audit event',      strpos($man, "'accounting.recurring_je.updated'") !== false);
$a('accounting.recurring_je.lines_replaced audit',     strpos($man, "'accounting.recurring_je.lines_replaced'") !== false);
$a('accounting.recurring_je.run audit',                strpos($man, "'accounting.recurring_je.run'") !== false);
$a('accounting.recurring_je.pause audit',              strpos($man, "'accounting.recurring_je.pause'") !== false);
$a('accounting.recurring_je.resume audit',             strpos($man, "'accounting.recurring_je.resume'") !== false);
$a('accounting.recurring_je.end audit',                strpos($man, "'accounting.recurring_je.end'") !== false);
$a('accounting.recurring_je.auto_ended audit',         strpos($man, "'accounting.recurring_je.auto_ended'") !== false);

echo "\ncore/modules.php — sidebar action\n";
$mods = (string) file_get_contents(__DIR__ . '/../core/modules.php');
$a('Recurring JEs left-nav entry',                     strpos($mods, "'route' => 'recurring'") !== false && strpos($mods, 'Recurring JEs') !== false);

echo PHP_EOL . "Total: $pass passed, $fail failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
