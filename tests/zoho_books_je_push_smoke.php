<?php
/**
 * Zoho Books — Slice 2 (JE push) smoke.
 *
 * Validates:
 *   - core/zoho_books/sync_je.php exposes the documented surface
 *   - zohoBooksBuildJournalPayload() builds the right Zoho payload
 *     shape (journal_date, reference_number, notes, line_items[])
 *   - Skip-the-JE-on-unmapped-accounts behaviour is honoured
 *   - api/zoho_books.php dispatches the sync_je action
 *   - dispatcher shim exists
 *   - cron/zoho_books_sync_outbound.php is wired correctly
 *   - reconcile endpoint registers the Zoho JE worker
 *   - ZohoBooksSettings.jsx surfaces manual-sync buttons
 *
 * Run via: php -d zend.assertions=1 tests/zoho_books_je_push_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ok  $msg\n"; $pass++; }
    else       { echo "FAIL  $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// --------------------------------------------------------- sync_je surface
echo "core/zoho_books/sync_je.php — surface\n";
$syncPath = $ROOT . '/core/zoho_books/sync_je.php';
$sync = file_exists($syncPath) ? (string) file_get_contents($syncPath) : '';
$a('file exists',                                $sync !== '');
$a('declares ZOHO_BOOKS_SOURCE constant',        $c($sync, "const ZOHO_BOOKS_SOURCE = 'zoho_books'"));
foreach ([
    'zohoBooksResolveAccountRef',
    'zohoBooksBuildJournalPayload',
    'zohoBooksSyncJournalEntries',
] as $fn) {
    $a("declares $fn()",                         $c($sync, "function $fn"));
}
$a('uses mappingFindExternal for idempotency',   $c($sync, "mappingFindExternal(\$tenantId, ZOHO_BOOKS_SOURCE, 'account'"));
$a('uses mappingUpsert on push',                 $c($sync, "mappingUpsert(\$tenantId, ZOHO_BOOKS_SOURCE, 'journal_entry'"));
$a('uses chartofaccounts endpoint',              $c($sync, '/books/v3/chartofaccounts'));
$a('POSTs to /books/v3/journals',                $c($sync, '/books/v3/journals'));
$a('reads journal.journal_id from response',     $c($sync, "\$resp['journal']['journal_id']"));
$a('select gates on status = posted',            $c($sync, "AND je.status = 'posted'"));
$a('honours push + two_way directions',          $c($sync, "['push', 'two_way']"));
$a('emits sync_je audit row',                    $c($sync, "zohoBooksAudit(\$tenantId, 'sync_je'"));
$a('emits skip audit row',                       $c($sync, "zohoBooksAudit(\$tenantId, 'sync_je_skip'"));
$a('emits push audit row',                       $c($sync, "zohoBooksAudit(\$tenantId, 'sync_je_push'"));
$a('supports dry_run opt',                       $c($sync, "!empty(\$opts['dry_run'])"));
$a('supports je_ids opt',                        $c($sync, "isset(\$opts['je_ids'])"));
$a('supports limit opt + cap at 500',            $c($sync, 'min(500'));

// php -l
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg($syncPath) . ' 2>&1', $out, $rc);
$a('php -l core/zoho_books/sync_je.php',         $rc === 0);

// --------------------------------------------------------- pure-function payload tests
echo "\nFunctional — zohoBooksBuildJournalPayload()\n";
require_once $syncPath;

$je = [
    'id'           => 99,
    'je_number'    => 'JE-99',
    'posting_date' => '2026-02-15',
    'memo'         => 'Smoke test memo',
];
$lines = [
    ['line_no' => 1, 'account_id' => 11, 'debit'  => 100.0, 'credit' => 0.0,   'memo' => 'Debit line'],
    ['line_no' => 2, 'account_id' => 22, 'debit'  => 0.0,   'credit' => 100.0, 'memo' => 'Credit line'],
];
$resolveOk = static fn (int $id) => ['value' => 'zo-acct-' . $id, 'name' => 'Acct ' . $id];
$payload = zohoBooksBuildJournalPayload($je, $lines, $resolveOk);

$a('payload has journal_date',                   ($payload['journal_date'] ?? '') === '2026-02-15');
$a('payload has reference_number',               ($payload['reference_number'] ?? '') === 'JE-99');
$a('payload has notes',                          ($payload['notes'] ?? '') === 'Smoke test memo');
$a('payload has 2 line_items',                   count($payload['line_items'] ?? []) === 2);
$a('line 1 is debit',                            ($payload['line_items'][0]['debit_or_credit'] ?? '') === 'debit');
$a('line 1 amount 100',                          (float) ($payload['line_items'][0]['amount'] ?? 0) === 100.0);
$a('line 1 account_id is zo-acct-11',            ($payload['line_items'][0]['account_id'] ?? '') === 'zo-acct-11');
$a('line 2 is credit',                           ($payload['line_items'][1]['debit_or_credit'] ?? '') === 'credit');
$a('line 2 account_id is zo-acct-22',            ($payload['line_items'][1]['account_id'] ?? '') === 'zo-acct-22');

// Resolver returning null on one line → JE marked with _unresolved sentinel.
$resolveSkip = static function (int $id) {
    return $id === 22 ? null : ['value' => 'zo-acct-' . $id, 'name' => 'OK'];
};
$payloadSkip = zohoBooksBuildJournalPayload($je, $lines, $resolveSkip);
$unresolvedCount = 0;
foreach ($payloadSkip['line_items'] as $l) {
    if (isset($l['_unresolved_account_id'])) $unresolvedCount++;
}
$a('unresolved line surfaces _unresolved_account_id', $unresolvedCount === 1);

// Zero-amount line is dropped silently.
$lines2 = [['line_no' => 1, 'account_id' => 11, 'debit' => 0, 'credit' => 0, 'memo' => '']];
$payload2 = zohoBooksBuildJournalPayload($je, $lines2, $resolveOk);
$a('zero-amount line is dropped',                count($payload2['line_items']) === 0);

// --------------------------------------------------------- API dispatch
echo "\napi/zoho_books.php — dispatches sync_je\n";
$api = (string) file_get_contents($ROOT . '/api/zoho_books.php');
$a("handles action: sync_je",                    $c($api, "case 'sync_je'"));
$a('requires zoho_books sync_je module',         $c($api, "require_once __DIR__ . '/../core/zoho_books/sync_je.php'"));
$a('rbac integrations.zoho_books.manage',        $c($api, "rbac_legacy_require(\$user, 'integrations.zoho_books.manage')"));
$a('shim api/zoho_books/sync_je.php exists',     file_exists($ROOT . '/api/zoho_books/sync_je.php'));

// --------------------------------------------------------- reconcile registration
echo "\napi/admin/accounting_sync_reconcile.php — Zoho JE registered\n";
$rec = (string) file_get_contents($ROOT . '/api/admin/accounting_sync_reconcile.php');
$a('reconcile requires zoho sync_je',            $c($rec, "require_once __DIR__ . '/../../core/zoho_books/sync_je.php'"));
$a('reconcile registers zoho_runner for JE',     $c($rec, "zohoBooksSyncJournalEntries(\$t, \$u, ['limit' => 50])"));
$a('reconcile gates worker behind direction',    $c($rec, "in_array(\$zohoDir, \$spec['zoho_runs_on']"));
$a('reconcile keeps worker_pending fallback',    $c($rec, "'worker_pending'"));

// --------------------------------------------------------- cron
echo "\ncron/zoho_books_sync_outbound.php\n";
$cron = file_exists($ROOT . '/cron/zoho_books_sync_outbound.php')
    ? (string) file_get_contents($ROOT . '/cron/zoho_books_sync_outbound.php')
    : '';
$a('cron file exists',                           $cron !== '');
$a('cron requires zoho client',                  $c($cron, "require_once __DIR__ . '/../core/zoho_books/client.php'"));
$a('cron requires sync_je',                      $c($cron, "require_once __DIR__ . '/../core/zoho_books/sync_je.php'"));
$a('cron selects only active connections',       $c($cron, "WHERE status = 'active'"));
$a('cron skips pending org rows',                $c($cron, "AND organization_id <> 'pending'"));
$a('cron calls zohoBooksSyncJournalEntries',     $c($cron, 'zohoBooksSyncJournalEntries'));
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg($ROOT . '/cron/zoho_books_sync_outbound.php') . ' 2>&1', $out, $rc);
$a('php -l cron/zoho_books_sync_outbound.php',   $rc === 0);

// --------------------------------------------------------- UI manual sync card
echo "\nUI — ZohoBooksSettings.jsx manual sync\n";
$ui = (string) file_get_contents($ROOT . '/dashboard/src/pages/ZohoBooksSettings.jsx');
$a('manual sync card testid',                    $c($ui, 'data-testid="zoho-books-manual-sync"'));
$a('sync je button testid',                      $c($ui, "'zoho-books-sync-je-btn'") || $c($ui, 'data-testid="zoho-books-sync-je-btn"'));
$a('dry-run button testid',                      $c($ui, "'zoho-books-sync-je-dryrun-btn'") || $c($ui, 'data-testid="zoho-books-sync-je-dryrun-btn"'));
$a('POSTs to /api/zoho_books/sync_je',           $c($ui, '/api/zoho_books/sync_je.php'));
$a('disables when JE direction is off',          $c($ui, "jeDir === 'push'") || $c($ui, "jeDir   === 'push'"));

echo "\n=========================================\n";
echo "Zoho Books JE Push (Slice 2) smoke: {$pass} ok / {$fail} fail\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
