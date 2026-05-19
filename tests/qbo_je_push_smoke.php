<?php
/**
 * QuickBooks Online — Slice 2 (Journal Entry push) smoke.
 *
 * Validates:
 *   - core/qbo/sync_je.php exposes the documented public surface
 *   - qboBuildJournalEntryPayload produces a QBO-spec-correct payload
 *     (TxnDate, DocNumber, PrivateNote, Line with PostingType, Amount,
 *      AccountRef)
 *   - api/qbo.php dispatches `sync_je`
 *   - cron/qbo_sync_outbound.php exists, references sync_je, and is
 *     syntactically valid
 *   - UI exposes the manual "Push now" + "Dry run" buttons
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ----------------------------------------------------------------- sync_je.php surface
echo "core/qbo/sync_je.php — public surface\n";
$path = $ROOT . '/core/qbo/sync_je.php';
$src  = (string) file_get_contents($path);
$a('file exists',                                $src !== '');
$a('strict types',                               $c($src, 'declare(strict_types=1);'));
$a('declares QBO_SOURCE',                        $c($src, "const QBO_SOURCE = 'quickbooks_online'"));
foreach (['qboSyncJournalEntries', 'qboBuildJournalEntryPayload', 'qboResolveAccountRef'] as $fn) {
    $a("declares $fn()",                         $c($src, "function $fn"));
}
$a('refuses when direction is not push/two_way', $c($src, "in_array(\$config['journal_entries'] ?? 'off', ['push', 'two_way']"));
$a('opt-in support for dry_run',                 $c($src, "'dry_run'"));
$a('opt-in support for je_ids restriction',     $c($src, 'je_ids'));
$a('uses mappingUpsert for idempotency',         $c($src, 'mappingUpsert(') && $c($src, "'journal_entry'"));
$a('uses mappingFindExternal for accounts',     $c($src, "mappingFindExternal(") && $c($src, "'account'"));
$a('auto-discovers account by AcctNum',          $c($src, 'AcctNum') && $c($src, 'qboCall'));
$a('LEFT JOIN excludes already-mapped JEs',      $c($src, 'LEFT JOIN external_entity_mappings'));
$a('audits sync_je with counts',                 $c($src, "qboAudit") && $c($src, "'sync_je'"));

// ----------------------------------------------------------------- syntax sanity
echo "\nSyntax sanity (php -l)\n";
foreach (['core/qbo/sync_je.php', 'api/qbo.php', 'api/qbo/sync_je.php', 'cron/qbo_sync_outbound.php'] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($ROOT . '/' . $f) . ' 2>&1', $out, $rc);
    $a("php -l $f",                              $rc === 0);
}

// ----------------------------------------------------------------- API wiring
echo "\napi/qbo.php — sync_je dispatch\n";
$api = (string) file_get_contents($ROOT . '/api/qbo.php');
$a('requires sync_je.php',                       $c($api, "require_once __DIR__ . '/../core/qbo/sync_je.php'"));
$a('dispatches case sync_je',                    $c($api, "case 'sync_je'"));
$a('requires manage permission on sync_je',      $c($api, "RBAC::requirePermission(\$user, 'integrations.qbo.manage')"));
$a('forwards dry_run + limit + je_ids',          $c($api, 'dry_run') && $c($api, 'je_ids') && $c($api, "'limit'"));
$a('shim file present',                          file_exists($ROOT . '/api/qbo/sync_je.php'));

// ----------------------------------------------------------------- Cron
echo "\ncron/qbo_sync_outbound.php\n";
$cron = (string) file_get_contents($ROOT . '/cron/qbo_sync_outbound.php');
$a('file exists',                                $cron !== '');
$a('requires sync_je.php',                       $c($cron, "require_once __DIR__ . '/../core/qbo/sync_je.php'"));
$a('iterates active connections',                $c($cron, "WHERE status = 'active'"));
$a('skips off / pull tenants',                   $c($cron, "in_array(\$dir, ['push', 'two_way']"));
$a('calls qboSyncJournalEntries',                $c($cron, 'qboSyncJournalEntries'));
$a('migration-not-applied bail-out',             $c($cron, 'migration 052 not applied yet'));

// ----------------------------------------------------------------- UI
echo "\nUI — QboSettings sync actions\n";
$ui = (string) file_get_contents($ROOT . '/dashboard/src/pages/QboSettings.jsx');
$a('push-now button testid',                     $c($ui, 'data-testid="qbo-sync-je-btn"'));
$a('dry-run button testid',                      $c($ui, 'data-testid="qbo-sync-je-dry-run-btn"'));
$a('sync actions container testid',              $c($ui, 'data-testid="qbo-sync-actions"'));
$a('only renders when direction in push/two_way',
    $c($ui, "['push', 'two_way'].includes(jeDir)"));
$a('endpoint POST to sync_je',                   $c($ui, '/api/qbo/sync_je.php?action=sync_je'));

// ----------------------------------------------------------------- Functional — payload shape
echo "\nFunctional — qboBuildJournalEntryPayload payload shape\n";
require_once $path; // safe — pure functions
$je = [
    'id' => 123, 'je_number' => 'JE-1001',
    'posting_date' => '2026-02-15', 'memo' => 'Test memo',
];
$lines = [
    ['line_no' => 1, 'account_id' => 11, 'debit' => '100.00', 'credit' => '0.00', 'memo' => 'cash receipt'],
    ['line_no' => 2, 'account_id' => 22, 'debit' => '0.00',   'credit' => '100.00', 'memo' => 'revenue'],
];
$resolver = function (int $acctId) {
    if ($acctId === 11) return ['value' => 'QBO_1', 'name' => 'Cash'];
    if ($acctId === 22) return ['value' => 'QBO_2', 'name' => 'Revenue'];
    return null;
};
$payload = qboBuildJournalEntryPayload($je, $lines, $resolver);
$a('TxnDate set',                                ($payload['TxnDate'] ?? '') === '2026-02-15');
$a('DocNumber set',                              ($payload['DocNumber'] ?? '') === 'JE-1001');
$a('PrivateNote set',                            ($payload['PrivateNote'] ?? '') === 'Test memo');
$a('two lines',                                  is_array($payload['Line']) && count($payload['Line']) === 2);
$a('line 1 is Debit',                            ($payload['Line'][0]['JournalEntryLineDetail']['PostingType'] ?? '') === 'Debit');
$a('line 1 AccountRef value',                    ($payload['Line'][0]['JournalEntryLineDetail']['AccountRef']['value'] ?? '') === 'QBO_1');
$a('line 1 Amount 100.00',                       abs((float)($payload['Line'][0]['Amount'] ?? 0) - 100.00) < 0.001);
$a('line 2 is Credit',                           ($payload['Line'][1]['JournalEntryLineDetail']['PostingType'] ?? '') === 'Credit');
$a('line 2 AccountRef value',                    ($payload['Line'][1]['JournalEntryLineDetail']['AccountRef']['value'] ?? '') === 'QBO_2');
$a('DetailType is JournalEntryLineDetail',       ($payload['Line'][0]['DetailType'] ?? '') === 'JournalEntryLineDetail');

// Unresolved account → caller-visible marker
$unresolved = qboBuildJournalEntryPayload($je, [['account_id' => 99, 'debit' => 10, 'credit' => 0]], static fn ($id) => null);
$a('unresolved account flagged via _unresolved_account_id',
    isset($unresolved['Line'][0]['_unresolved_account_id']) && (int) $unresolved['Line'][0]['_unresolved_account_id'] === 99);

echo "\n=========================================\n";
echo "QBO JE Push smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
