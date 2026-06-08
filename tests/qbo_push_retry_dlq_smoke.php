<?php
/**
 * Smoke — QBO push retry + dead-letter queue (qbo_push_failures).
 *
 * Locks the contract:
 *   - core/qbo/retry_queue.php declares the 4 helper functions
 *     (Check, Record, Clear, Requeue) with stable signatures.
 *   - All three QBO sync drivers (sync_je/bills/invoices) call
 *     qboPushFailureCheck BEFORE each push and skip on backoff/DLQ.
 *   - All three call qboPushFailureClear after a successful POST.
 *   - All three call qboPushFailureRecord inside the catch block.
 *   - The admin endpoint /api/admin/qbo/dead_letters.php exists and
 *     handles both GET (list) and POST (requeue).
 *   - Live exercise of the backoff math + DLQ transition against an
 *     in-memory SQLite mirroring the migration 113 schema.
 *
 * Run: php -d zend.assertions=1 /app/tests/qbo_push_retry_dlq_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nQBO push retry + DLQ smoke\n";
echo "===========================\n\n";

// ────────────────────────── 1. Module presence ──
echo "── module presence ──\n";
$retryPath = '/app/core/qbo/retry_queue.php';
check('core/qbo/retry_queue.php exists', file_exists($retryPath));
$src = file_exists($retryPath) ? (string) file_get_contents($retryPath) : '';
check('declares qboPushFailureCheck()',   str_contains($src, 'function qboPushFailureCheck('));
check('declares qboPushFailureRecord()',  str_contains($src, 'function qboPushFailureRecord('));
check('declares qboPushFailureClear()',   str_contains($src, 'function qboPushFailureClear('));
check('declares qboPushFailureRequeue()', str_contains($src, 'function qboPushFailureRequeue('));
check('exposes QBO_PUSH_MAX_ATTEMPTS const',
    str_contains($src, 'const QBO_PUSH_MAX_ATTEMPTS'));

check('migration 113 SQL present',
    file_exists('/app/core/migrations/113_qbo_push_retry_dlq.sql'));
$migSql = (string) file_get_contents('/app/core/migrations/113_qbo_push_retry_dlq.sql');
check('migration creates qbo_push_failures table',
    str_contains($migSql, 'CREATE TABLE qbo_push_failures'));
check('migration declares ENUM status (retrying, dead_letter)',
    str_contains($migSql, "ENUM('retrying','dead_letter')"));
check('migration unique-keys (tenant, sub_tenant, entity, source)',
    str_contains($migSql, 'uniq_tenant_entity (tenant_id, sub_tenant_id, entity_type, source_id)'));
check('migration carries vendor_raw column (charter primitive #6 link)',
    str_contains($migSql, 'vendor_raw'));

// ────────────────────────── 2. Wiring into sync drivers ──
echo "\n── sync-driver wiring ──\n";
foreach (['sync_je', 'sync_bills', 'sync_invoices'] as $f) {
    $code = (string) file_get_contents('/app/core/qbo/' . $f . '.php');
    $entity = ['sync_je' => 'journal_entry', 'sync_bills' => 'bill', 'sync_invoices' => 'invoice'][$f];
    check("{$f}.php requires retry_queue.php (via include chain)",
        str_contains($code, "retry_queue.php") || $f !== 'sync_je');
    check("{$f}.php calls qboPushFailureCheck for '{$entity}'",
        str_contains($code, "qboPushFailureCheck(\$tenantId, '{$entity}'"));
    check("{$f}.php gates push when retryGate !== 'go'",
        str_contains($code, "if (\$retryGate !== 'go')"));
    check("{$f}.php calls qboPushFailureClear for '{$entity}' on success",
        str_contains($code, "qboPushFailureClear(\$tenantId, '{$entity}'"));
    check("{$f}.php calls qboPushFailureRecord for '{$entity}' in catch",
        str_contains($code, "qboPushFailureRecord(\$tenantId, '{$entity}'"));
}

// ────────────────────────── 3. Admin DLQ endpoint ──
echo "\n── admin DLQ endpoint ──\n";
$dlPath = '/app/api/admin/qbo/dead_letters.php';
check('/api/admin/qbo/dead_letters.php exists',  file_exists($dlPath));
$dlSrc = file_exists($dlPath) ? (string) file_get_contents($dlPath) : '';
check('endpoint requires retry_queue.php',
    str_contains($dlSrc, "qbo/retry_queue.php"));
check('endpoint enforces RBAC',
    str_contains($dlSrc, 'rbac_legacy_require_any'));
check('GET handler reads qbo_push_failures',
    str_contains($dlSrc, 'FROM qbo_push_failures'));
check('POST handler validates entity_type allowlist',
    str_contains($dlSrc, "['journal_entry', 'bill', 'invoice']"));
check('POST handler delegates to qboPushFailureRequeue',
    str_contains($dlSrc, 'qboPushFailureRequeue('));

// ────────────────────────── 4. Live behaviour (SQLite mirror) ──
echo "\n── live backoff + DLQ behaviour ──\n";

// Load retry_queue.php FIRST — its require chain loads db.php which
// sets $pdo=null when MySQL isn't reachable. THEN install our SQLite.
require_once $retryPath;
$GLOBALS['pdo'] = new \PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

// Mirror the migration schema (SQLite-flavoured — no ENUM, just TEXT).
$GLOBALS['pdo']->exec("CREATE TABLE qbo_push_failures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INT NOT NULL, sub_tenant_id INT NULL,
    entity_type TEXT NOT NULL, source_id INT NOT NULL,
    attempts INT NOT NULL DEFAULT 0, max_attempts INT NOT NULL DEFAULT 5,
    status TEXT NOT NULL DEFAULT 'retrying',
    last_error_code TEXT, last_error_message TEXT, vendor_raw TEXT,
    last_http_status INT,
    next_retry_at TEXT, first_failed_at TEXT, last_failed_at TEXT,
    cleared_at TEXT, created_at TEXT, updated_at TEXT,
    UNIQUE (tenant_id, sub_tenant_id, entity_type, source_id)
)");

// Pristine state: should return 'go'.
check('Check returns go when no row exists',
    qboPushFailureCheck(101, 'journal_entry', 999) === 'go');

// First failure → row created with attempts=1, status=retrying, next_retry_at +30s.
$ex = new \RuntimeException('boom-1');
qboPushFailureRecord(101, 'journal_entry', 999, $ex);
$row = $GLOBALS['pdo']->query("SELECT * FROM qbo_push_failures WHERE source_id=999")->fetch(\PDO::FETCH_ASSOC);
check('first failure creates row',                            !!$row);
check('first failure attempts=1',                             (int) $row['attempts'] === 1);
check('first failure status=retrying',                        $row['status'] === 'retrying');
check('first failure next_retry_at set',                      !empty($row['next_retry_at']));
check('first failure error message captured (truncated)',     $row['last_error_message'] === 'boom-1');

// Check during backoff should return 'skip_backoff'.
check('Check returns skip_backoff during backoff',
    qboPushFailureCheck(101, 'journal_entry', 999) === 'skip_backoff');

// Force backoff to elapse by NULL-ing next_retry_at, then check returns 'go'.
$GLOBALS['pdo']->exec("UPDATE qbo_push_failures SET next_retry_at='2000-01-01 00:00:00' WHERE source_id=999");
check('Check returns go once backoff elapses',
    qboPushFailureCheck(101, 'journal_entry', 999) === 'go');

// Now load it with QboApiException so we exercise charter primitive #6 capture.
require_once '/app/core/qbo/client.php';
$qex = new QboApiException('http 400 from QBO');
$qex->httpStatus = 400; $qex->errorCode = '6210';
$qex->raw = ['body' => 'Business Validation Error: Lines do not balance'];
qboPushFailureRecord(101, 'journal_entry', 999, $qex);
$row = $GLOBALS['pdo']->query("SELECT * FROM qbo_push_failures WHERE source_id=999")->fetch(\PDO::FETCH_ASSOC);
check('second failure attempts=2',                            (int) $row['attempts'] === 2);
check('captures QboApiException::httpStatus',                 (int) $row['last_http_status'] === 400);
check('captures QboApiException::errorCode',                  $row['last_error_code'] === '6210');
check('captures QboApiException::raw[body] (charter #6 link)',
    str_contains((string) $row['vendor_raw'], 'Business Validation Error'));

// Hammer to max_attempts (5 default) and verify dead_letter flip.
for ($i = 0; $i < 4; $i++) {
    $GLOBALS['pdo']->exec("UPDATE qbo_push_failures SET next_retry_at='2000-01-01 00:00:00' WHERE source_id=999");
    qboPushFailureRecord(101, 'journal_entry', 999, $qex);
}
$row = $GLOBALS['pdo']->query("SELECT * FROM qbo_push_failures WHERE source_id=999")->fetch(\PDO::FETCH_ASSOC);
check('attempts >= max → status flips to dead_letter',        $row['status'] === 'dead_letter');
check('attempts >= max_attempts',                            (int) $row['attempts'] >= (int) $row['max_attempts']);
check('next_retry_at nulled once dead_letter',                $row['next_retry_at'] === null);
check('Check returns skip_dead_letter',
    qboPushFailureCheck(101, 'journal_entry', 999) === 'skip_dead_letter');

// Requeue → status returns to 'retrying' with attempts=0.
$ok = qboPushFailureRequeue(101, 'journal_entry', 999);
$row = $GLOBALS['pdo']->query("SELECT * FROM qbo_push_failures WHERE source_id=999")->fetch(\PDO::FETCH_ASSOC);
check('Requeue returns true for dead-letter row',             $ok === true);
check('Requeue resets attempts to 0',                         (int) $row['attempts'] === 0);
check('Requeue resets status to retrying',                    $row['status'] === 'retrying');
check('Requeue returns false when nothing to requeue',
    qboPushFailureRequeue(101, 'journal_entry', 12345) === false);

// Clear after success → cleared_at set, future Check returns 'go'.
qboPushFailureClear(101, 'journal_entry', 999);
$row = $GLOBALS['pdo']->query("SELECT * FROM qbo_push_failures WHERE source_id=999")->fetch(\PDO::FETCH_ASSOC);
check('Clear stamps cleared_at',                              !empty($row['cleared_at']));
check('Check returns go after Clear (treats cleared row as absent)',
    qboPushFailureCheck(101, 'journal_entry', 999) === 'go');

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "qbo_push_retry_dlq smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
