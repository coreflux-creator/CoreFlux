<?php
/**
 * Smoke — Charter primitive #5 (post-push verification).
 *
 * Drives the full chain:
 *   1. AccountingProviderAdapter declares a concrete verifyCreate().
 *   2. Jaz adapter overrides verifyCreate to assert downstream status.
 *   3. Command Service calls verifyCreate after every successful
 *      create_* and stamps `posted` OR `posted_unverified` accordingly.
 *   4. Migration 102 widens the outbox status ENUM.
 *   5. Worker treats `posted_unverified` as success-with-warning
 *      (no retry).
 *
 * Run: php -d zend.assertions=1 /app/tests/charter_verify_after_create_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nCharter #5 — post-push verification smoke\n";
echo "=========================================\n\n";

$adapterBase = (string) file_get_contents('/app/core/accounting/provider_adapter.php');
$jazAdapter  = (string) file_get_contents('/app/core/accounting/jaz_adapter.php');
$cmdService  = (string) file_get_contents('/app/core/accounting/command_service.php');
$worker      = (string) file_get_contents('/app/cron/accounting_outbox_worker.php');
$migration   = (string) file_get_contents('/app/core/migrations/102_outbox_posted_unverified.sql');

echo "── base class contract ──\n";
check('AccountingProviderAdapter declares verifyCreate',
    str_contains($adapterBase, 'public function verifyCreate('));
check('verifyCreate signature includes expectedStatus param with default',
    preg_match('/verifyCreate\(.*?string \$expectedStatus = \'active\'\)/s', $adapterBase) === 1);
check('default implementation returns the 5-key stable shape',
    str_contains($adapterBase, "'verified'") &&
    str_contains($adapterBase, "'downstream_status'") &&
    str_contains($adapterBase, "'expected_status'") &&
    str_contains($adapterBase, "'reason'") &&
    str_contains($adapterBase, "'fetched_at'"));
check('default impl uses getObject() under the hood',
    preg_match('/\$this->getObject\(.*?providerObjectType.*?providerObjectId\)/s', $adapterBase) === 1);
check('GET-failure path returns verified=false + reason',
    preg_match('/GET after create failed/', $adapterBase) === 1 &&
    preg_match("/'verified'\s*=>\s*false/", $adapterBase) === 1);

echo "\n── Jaz adapter override ──\n";
check('Jaz adapter overrides verifyCreate', str_contains($jazAdapter, 'public function verifyCreate('));
check('Jaz override normalises Jaz status aliases',
    str_contains($jazAdapter, "'recorded' => 'active'") &&
    str_contains($jazAdapter, "'posted' => 'active'") &&
    str_contains($jazAdapter, "'finalized' => 'active'"));
check('Jaz override checks status / lifecycleStatus / recordingStatus',
    str_contains($jazAdapter, "'status'") &&
    str_contains($jazAdapter, "'lifecycleStatus'") &&
    str_contains($jazAdapter, "'recordingStatus'"));
check('Jaz override returns verified=true only on exact status match',
    str_contains($jazAdapter, '$downstream === strtolower($expectedStatus)'));

echo "\n── Command Service wiring ──\n";
check('cmdService maps per-command expected downstream',
    str_contains($cmdService, "'create_draft_journal' => 'active'") &&
    str_contains($cmdService, "'create_draft_bill'    => 'draft'") &&
    str_contains($cmdService, "'create_draft_invoice' => 'draft'") &&
    str_contains($cmdService, "'post_object'          => 'active'"));
check('cmdService invokes adapter->verifyCreate after success',
    str_contains($cmdService, '$adapter->verifyCreate('));
check('cmdService stamps posted_unverified when verified=false',
    str_contains($cmdService, "\$finalStatus = 'posted_unverified'"));
check('cmdService swallows verify exceptions (does NOT re-queue)',
    str_contains($cmdService, '$verifyErr'));
check('cmdService writes the verify payload alongside the result',
    str_contains($cmdService, "json_encode(['result' => \$res, 'verify' => \$verify]"));

echo "\n── migration 102 ──\n";
check("migration adds 'posted_unverified' to status ENUM",
    str_contains($migration, "'posted_unverified'"));
check('migration uses MODIFY COLUMN (idempotent re-runs are fine)',
    str_contains($migration, 'MODIFY COLUMN status'));

echo "\n── worker treats posted_unverified as success-with-warning ──\n";
check('worker has a branch for posted_unverified',
    str_contains($worker, "elseif (\$statusAfter === 'posted_unverified')"));
check('worker increments $ok counter (not $err) for posted_unverified',
    preg_match("/'posted_unverified'.*?\\\$ok\\+\\+/s", $worker) === 1);
check('worker logs the verify.reason for the operator',
    str_contains($worker, 'verify'));

echo "\n── live exercise: Jaz override against a stubbed jazCall ──\n";

// Stub minimum.
if (!class_exists('AccountingProviderAdapter')) {
    abstract class AccountingProviderAdapter {
        abstract public function getObject(int $t, int $st, string $type, string $id): array;
        public function verifyCreate(int $t, int $st, string $type, string $id, string $expectedStatus = 'active'): array {
            return ['verified' => true, 'downstream_status' => 'fetched', 'expected_status' => $expectedStatus, 'reason' => null, 'fetched_at' => date('Y-m-d H:i:s')];
        }
    }
}
if (!class_exists('AccountingAdapterValidationException')) {
    class AccountingAdapterValidationException extends \RuntimeException {}
}
if (!class_exists('JazApiException')) {
    class JazApiException extends \RuntimeException { public int $httpStatus = 0; }
}
if (!function_exists('jazCall')) {
    function jazCall(array $key, string $verb, string $path, array $payload = []) {
        // Look up by id from a globally seeded fixture set.
        $rid = basename($path);
        $fixt = $GLOBALS['__jaz_fixt'][$rid] ?? null;
        if (!$fixt) throw new JazApiException("not found: {$path}");
        return ['data' => $fixt];
    }
}

// Pull just the verifyCreate override (and the helpers it needs) from the file.
preg_match('/public function verifyCreate\(.*?\n    \}\n/s', $jazAdapter, $m);
$shim = <<<'PHP'
class JazVerifyShim extends AccountingProviderAdapter {
    public function getObject(int $t, int $st, string $type, string $id): array {
        return ['provider_object_type' => $type, 'provider_object_id' => $id,
                'jaz_payload' => jazCall([], 'GET', "{$type}s/{$id}")];
    }
PHP;
eval($shim . "\n" . ($m[0] ?? '') . "\n}");

$adapter = new JazVerifyShim();

$GLOBALS['__jaz_fixt'] = [
    'je-active' => ['resourceId' => 'je-active', 'status' => 'active'],
    'je-draft'  => ['resourceId' => 'je-draft',  'status' => 'draft'],
    'je-recorded' => ['resourceId' => 'je-recorded', 'status' => 'recorded'],
];

$v1 = $adapter->verifyCreate(1, 1, 'journal', 'je-active',  'active');
check('expected active + got active → verified=true',  $v1['verified'] === true);

$v2 = $adapter->verifyCreate(1, 1, 'journal', 'je-draft',   'active');
check('expected active + got draft → verified=false',  $v2['verified'] === false);
check('mismatch reason mentions both states',          str_contains((string) ($v2['reason'] ?? ''), "expected 'active', got 'draft'"));

$v3 = $adapter->verifyCreate(1, 1, 'journal', 'je-recorded', 'active');
check("Jaz 'recorded' aliased to 'active' (still verified)", $v3['verified'] === true);

$v4 = $adapter->verifyCreate(1, 1, 'journal', 'nonexistent-rid', 'active');
check('GET-failure produces verified=false + fetch_failed',
    $v4['verified'] === false && $v4['downstream_status'] === 'fetch_failed');

$total = $passes + count($failures);
echo "\n=========================================\n";
echo "charter_verify_after_create smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
echo "=========================================\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
