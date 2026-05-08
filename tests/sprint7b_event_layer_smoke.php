<?php
/**
 * Sprint 7b smoke — accounting events + posting rules engine (spec §12-13).
 *
 * Structural assertions only — covers:
 *   - 4 migrations (events, posting_rules, journal_templates+lines, subledger_links)
 *   - core/posting_engine/process.php API surface + lifecycle handling
 *   - api/accounting_events.php endpoint behaviour: POST, dry_run, sandbox,
 *     re-process failed, list with filters
 *   - Treasury bank-feed slice exercises subledger_links
 *
 * Runtime DB assertions (full E2E) are deferred to the live tenant smoke
 * because we don't have a MySQL fixture in CI.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Migration 015 — accounting_events\n";
$m15 = (string) file_get_contents("{$ROOT}/modules/accounting/migrations/015_accounting_events.sql");
$assert('migration exists',                strlen($m15) > 0);
$assert('table accounting_events',         stripos($m15, 'CREATE TABLE IF NOT EXISTS accounting_events') !== false);
$assert('status enum complete',            stripos($m15, "ENUM('received','mapped','posted','failed','ignored','reversed')") !== false);
$assert('idempotency unique key',          stripos($m15, 'uq_ae_tenant_source') !== false
                                         && stripos($m15, '(tenant_id, source_module, source_record_id, event_type)') !== false);
$assert('payload column is JSON',          stripos($m15, 'payload JSON NOT NULL') !== false);
$assert('FK fields present',               stripos($m15, 'journal_entry_id') !== false
                                         && stripos($m15, 'posting_rule_id') !== false);
$assert('indexes for hot paths',           stripos($m15, 'idx_ae_tenant_status_type') !== false
                                         && stripos($m15, 'idx_ae_tenant_je') !== false);

echo "\nMigration 016 — posting_rules\n";
$m16 = (string) file_get_contents("{$ROOT}/modules/accounting/migrations/016_posting_rules.sql");
$assert('table accounting_posting_rules',  stripos($m16, 'CREATE TABLE IF NOT EXISTS accounting_posting_rules') !== false);
$assert('priority column',                 stripos($m16, 'priority INT NOT NULL DEFAULT 100') !== false);
$assert('status enum',                     stripos($m16, "ENUM('active','draft','archived')") !== false);
$assert('conditions JSON',                 stripos($m16, 'conditions JSON NULL') !== false);
$assert('event_type indexed',              stripos($m16, 'idx_apr_tenant_event') !== false);
$assert('entity_id nullable (cross-entity rules)',
                                           stripos($m16, 'entity_id BIGINT UNSIGNED NULL') !== false);

echo "\nMigration 017 — journal_templates + lines\n";
$m17 = (string) file_get_contents("{$ROOT}/modules/accounting/migrations/017_journal_templates.sql");
$assert('table accounting_journal_templates',
                                           stripos($m17, 'CREATE TABLE IF NOT EXISTS accounting_journal_templates') !== false);
$assert('table accounting_journal_template_lines',
                                           stripos($m17, 'CREATE TABLE IF NOT EXISTS accounting_journal_template_lines') !== false);
$assert('account_selector column',         stripos($m17, 'account_selector VARCHAR') !== false);
$assert('debit_formula + credit_formula',  stripos($m17, 'debit_formula') !== false
                                         && stripos($m17, 'credit_formula') !== false);
$assert('dimensions_json column',          stripos($m17, 'dimensions_json JSON NULL') !== false);
$assert('FK cascade delete',               stripos($m17, 'ON DELETE CASCADE') !== false);
$assert('uq lines per template',           stripos($m17, 'uq_ajtl_tpl_line') !== false);

echo "\nMigration 018 — subledger_links\n";
$m18 = (string) file_get_contents("{$ROOT}/modules/accounting/migrations/018_subledger_links.sql");
$assert('table accounting_subledger_links',
                                           stripos($m18, 'CREATE TABLE IF NOT EXISTS accounting_subledger_links') !== false);
$assert('link_kind column',                stripos($m18, 'link_kind VARCHAR') !== false);
$assert('one source can have many JEs',    stripos($m18, 'uq_asl_source_je') !== false
                                         && stripos($m18, '(tenant_id, source_module, source_record_id, journal_entry_id, link_kind)') !== false);
$assert('accounting_event_id FK',          stripos($m18, 'accounting_event_id') !== false);

echo "\ncore/posting_engine/process.php — chokepoint\n";
$proc = (string) file_get_contents("{$ROOT}/core/posting_engine/process.php");
$assert('parses',                          $lint("{$ROOT}/core/posting_engine/process.php"));
$assert('exposes accountingProcessEvent',  strpos($proc, 'function accountingProcessEvent') !== false);
$assert('dryRun parameter',                strpos($proc, 'bool $dryRun = false') !== false);
$assert('idempotent replay path',          strpos($proc, "'idempotent_replay' => true") !== false);
$assert('writes subledger_links on post',  strpos($proc, 'INSERT IGNORE INTO accounting_subledger_links') !== false);
$assert('uses formula evaluator',          strpos($proc, 'formulaEvaluate') !== false);
$assert('uses formula interpolation',      strpos($proc, 'formulaInterpolate') !== false);
$assert('account selector grammar: system:',
                                           strpos($proc, "str_starts_with(\$selector, 'system:')") !== false);
$assert('account selector grammar: code:', strpos($proc, "str_starts_with(\$selector, 'code:')") !== false);
$assert('account selector grammar: id:',   strpos($proc, "str_starts_with(\$selector, 'id:')") !== false);
$assert('payload-ref account fallback',    preg_match('#formulaResolveRef\(\s*(?:\(string\)\s*)?\$selector#', $proc) === 1);
$assert('rule selection ordered priority desc',
                                           strpos($proc, 'ORDER BY priority DESC') !== false);
$assert('rule conditions support gt/gte/lt/lte/eq/ne/in',
    strpos($proc, "'gt'") !== false && strpos($proc, "'gte'") !== false
    && strpos($proc, "'lt'") !== false && strpos($proc, "'lte'") !== false
    && strpos($proc, "'eq'") !== false && strpos($proc, "'ne'") !== false
    && strpos($proc, "'in'") !== false);
$assert('balanced-line guard (no double dr+cr)',
                                           strpos($proc, 'cannot have both debit and credit') !== false);
$assert('negative-amount guard',           strpos($proc, 'produced negative amount') !== false);
$assert('unique-key idempotency replay',
    strpos($proc, "errorInfo[1]") !== false && strpos($proc, '1062') !== false);
$assert('marks status=ignored on no rule',
                                           strpos($proc, "no posting rule matched") !== false);
$assert('marks status=failed on render error',
                                           strpos($proc, 'status="failed"') !== false);
$assert('stamps status=posted + posted_at + clears error_message',
    strpos($proc, 'status="posted"') !== false
    && strpos($proc, 'posted_at=NOW()') !== false
    && strpos($proc, 'error_message=NULL') !== false);

echo "\napi/accounting_events.php — HTTP surface\n";
$api = (string) file_get_contents("{$ROOT}/api/accounting_events.php");
$assert('parses',                          $lint("{$ROOT}/api/accounting_events.php"));
$assert('GET requires accounting.coa.view',
    strpos($api, "RBAC::requirePermission(\$user, 'accounting.coa.view')") !== false);
$assert('POST requires accounting.create_entry',
    strpos($api, "RBAC::requirePermission(\$user, 'accounting.create_entry')") !== false);
$assert('sandbox requires accounting.manage_posting_rules',
    strpos($api, "RBAC::requirePermission(\$user, 'accounting.manage_posting_rules')") !== false);
$assert('GET filters: status/event_type/entity_id/from/to',
    strpos($api, "api_query('status')") !== false
    && strpos($api, "api_query('event_type')") !== false
    && strpos($api, "api_query('entity_id')") !== false
    && strpos($api, "api_query('from')") !== false
    && strpos($api, "api_query('to')") !== false);
$assert('GET pagination clamped to [1,500]', strpos($api, 'min(500, (int)') !== false);
$assert('parses /events/:id/post path',    strpos($api, '/events/(\\d+)(?:/(\\w+))?') !== false);
$assert('post action whitelist guard',     strpos($api, "['received', 'failed', 'ignored']") !== false);
$assert('dry_run honoured on create',      strpos($api, "api_query('dry_run')") !== false);
$assert('sandbox returns failed-on-throwable, not 500',
    strpos($api, "'status' => 'failed'") !== false
    && strpos($api, '$e->getMessage()') !== false);
$assert('payload normalised to array',     strpos($api, "is_array(\$body['payload']) ? \$body['payload'] : []") !== false);

echo "\nTreasury bank-feed slice — subledger_links exercised\n";
$tx = (string) file_get_contents("{$ROOT}/modules/treasury/api/account_transactions.php");
$assert('subledger_links insert on categorize_and_post',
    strpos($tx, 'INSERT IGNORE INTO accounting_subledger_links') !== false);
$assert("source_module = 'treasury_feed'",
    strpos($tx, "'sm' => 'treasury_feed'") !== false);
$assert('source_record_id namespaced by line type',
    strpos($tx, "(\$type === 'deposit' ? 'bank_line:' : 'liab_line:') . \$lineId") !== false);
$assert("link_kind = 'primary'",
    strpos($tx, '"primary"') !== false || strpos($tx, "'primary'") !== false);
$assert('non-fatal if 7b table missing',
    strpos($tx, "/* table absent in pre-7b tenants — non-fatal */") !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
