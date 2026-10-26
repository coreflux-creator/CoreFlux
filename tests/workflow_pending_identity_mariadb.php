<?php
/** Actual MariaDB checks across fresh, retired-118 and already-upgraded workflow layouts. */
declare(strict_types=1);
$ci = getenv('CI') === 'true';
$local = getenv('COREFLUX_STRUCTURE_LOCAL_FIXTURE') === '1';
if (PHP_SAPI !== 'cli' || (!$ci && !$local) || !in_array('--confirm-disposable-fixture', $argv, true)) throw new RuntimeException('Disposable database required.');
require_once __DIR__ . '/../deploy/full_core_upgrade_executor.php';
$password = $ci ? 'root' : (string)getenv('COREFLUX_STRUCTURE_PASSWORD');
$port = $ci ? '3306' : (string)getenv('COREFLUX_STRUCTURE_PORT');
if ($password === '' || !ctype_digit($port)) throw new RuntimeException('Fixture connection missing.');
$db = new PDO('mysql:host=127.0.0.1;port='.$port.';charset=utf8mb4', 'root', $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
if (!str_contains($db->query('SELECT VERSION()')->fetchColumn(), 'MariaDB')) throw new RuntimeException('MariaDB required.');
$name = 'coreflux_upgrade_fixture_workflow_'.gmdate('YmdHis').'_'.getmypid();
$created = false; $checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL '.$message);
    $checks++; echo 'PASS '.$message."\n";
};
$sql = file_get_contents(__DIR__.'/../core/migrations/182_workflow_pending_subject_identity.sql');
$apply = static function () use ($db, $sql): void {
    foreach (fullCoreUpgradeStatements($sql) as $statement) {
        $result = $db->query($statement); if ($result) $result->closeCursor();
    }
};
try {
    $db->exec('CREATE DATABASE `'.$name.'`'); $created = true; $db->exec('USE `'.$name.'`');
    foreach (['fresh_unique', 'retired_118', 'applied_156', 'applied_158'] as $layout) {
        $db->exec('DROP TABLE IF EXISTS workflow_instances');
        $db->exec("CREATE TABLE workflow_instances (
            id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, tenant_id BIGINT UNSIGNED NOT NULL,
            subject_type VARCHAR(80) NOT NULL, subject_id BIGINT UNSIGNED NOT NULL,
            status ENUM('pending','approved','rejected','cancelled','escalated','expired') NOT NULL,
            payload_json TEXT, UNIQUE KEY uq_wfi_subject(tenant_id,subject_type,subject_id)
        ) ENGINE=InnoDB");
        if ($layout === 'retired_118') $db->exec('ALTER TABLE workflow_instances DROP INDEX uq_wfi_subject');
        if (in_array($layout, ['applied_156','applied_158'], true)) {
            foreach (['156_billing_invoice_approval_attempts.sql','158_payroll_workflow_review_attempts.sql'] as $old) {
                foreach (fullCoreUpgradeStatements(file_get_contents(__DIR__.'/fixtures/full-core-migrations/'.$old)) as $statement) $db->exec($statement);
                if ($layout === 'applied_156') break;
            }
        }
        $insert = $db->prepare('INSERT INTO workflow_instances(tenant_id,subject_type,subject_id,status,payload_json) VALUES(?,?,?,?,?)');
        foreach (['billing_invoice','payroll_run','ap_bill','treasury_transfer','placement_rate_version'] as $type) {
            foreach (['approved','rejected','cancelled','escalated','expired'] as $n=>$status) $insert->execute([999,$type,$n+1,$status,'preserve '.$status]);
        }
        if ($layout === 'retired_118') {
            $insert->execute([999,'ap_bill',1,'approved','earlier approved round']);
            $insert->execute([999,'ap_bill',1,'rejected','earlier rejected round']);
        }
        $before = $db->query('SELECT id,tenant_id,subject_type,subject_id,status,payload_json FROM workflow_instances ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $apply();
        $assert($before === $db->query('SELECT id,tenant_id,subject_type,subject_id,status,payload_json FROM workflow_instances ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), $layout.' preserves every original history field and ID');
        foreach (['billing_invoice','payroll_run','ap_bill','treasury_transfer','placement_rate_version'] as $type) {
            foreach (range(1,5) as $subject) $insert->execute([999,$type,$subject,'pending','new round']);
        }
        $assert((int)$db->query("SELECT COUNT(*) FROM workflow_instances WHERE status='pending'")->fetchColumn() === 25, $layout.' permits a new round after every terminal status');
        try { $insert->execute([999,'ap_bill',1,'pending','duplicate']); $duplicateRejected = false; }
        catch (PDOException $error) { $duplicateRejected = ($error->errorInfo[1] ?? null) === 1062; }
        $assert($duplicateRejected, $layout.' rejects a second pending review without discarding the first');
        $insert->execute([1000,'ap_bill',1,'pending','different tenant']);
        $insert->execute([999,'another_subject',1,'pending','different type']);
        $assert((int)$db->query("SELECT COUNT(*) FROM workflow_instances WHERE status='pending'")->fetchColumn() === 27, $layout.' scopes pending identity by tenant and subject type');
        $db->exec("UPDATE workflow_instances SET status='approved' WHERE tenant_id=999 AND subject_type='ap_bill' AND subject_id=1 AND status='pending'");
        $insert->execute([999,'ap_bill',1,'pending','subsequent round']);
        $assert((int)$db->query("SELECT COUNT(*) FROM workflow_instances WHERE tenant_id=999 AND subject_type='ap_bill' AND subject_id=1 AND status='pending'")->fetchColumn() === 1, $layout.' releases the slot when a review completes');
        $replayBefore = $db->query('SELECT * FROM workflow_instances ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $apply();
        $assert($replayBefore === $db->query('SELECT * FROM workflow_instances ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), $layout.' repeat migration preserves all rows');
    }
    $db->exec('ALTER TABLE workflow_instances DROP INDEX uq_wfi_live_subject');
    $insert->execute([999,'ap_bill',1,'pending','conflicting existing pending round']);
    $before = $db->query('SHOW CREATE TABLE workflow_instances')->fetch(PDO::FETCH_NUM)[1];
    $rows = $db->query('SELECT * FROM workflow_instances ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    try { $apply(); $held = false; } catch (PDOException $error) { $held = ($error->errorInfo[1] ?? null) === 4025; }
    $assert($held, 'ambiguous pending reviews fail the named guard before structural changes');
    $assert($before === $db->query('SHOW CREATE TABLE workflow_instances')->fetch(PDO::FETCH_NUM)[1]
        && $rows === $db->query('SELECT * FROM workflow_instances ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'duplicate-review hold preserves schema and every review');
    echo "Workflow pending identity MariaDB: $checks checks passed.\n";
} finally {
    if ($created && $db->query('SELECT DATABASE()')->fetchColumn() === $name) $db->exec('DROP DATABASE `'.$name.'`');
}
