<?php
/** Apply only customer-deposit migrations to the isolated synthetic staging database. */
declare(strict_types=1);

if (getenv('COREFLUX_ENV') !== 'staging' || PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is restricted to the staging CLI.\n");
    exit(2);
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';

$pdo = getDB();
$tenant = $pdo->query('SELECT name FROM tenants WHERE id = 999')->fetchColumn();
if ($tenant !== 'CoreFlux CI Simulation') {
    throw new RuntimeException('The synthetic staging tenant was not found; refusing the migration.');
}

foreach (['032_customer_deposits.sql', '033_customer_deposit_refunds.sql',
          '034_customer_deposit_corrections.sql', '035_receipt_request_fingerprint.sql'] as $migrationName) {
    $relativePath = 'modules/accounting/migrations/' . $migrationName;
    $file = dirname(__DIR__) . '/' . $relativePath;
    $sql = file_get_contents($file);
    if ($sql === false) throw new RuntimeException('Customer-deposit migration file is missing: ' . $migrationName);
    $checksum = hash('sha256', $sql);
    $stmt = $pdo->prepare('SELECT checksum_sha FROM coreflux_migrations WHERE file_path = :p');
    $stmt->execute(['p' => $relativePath]);
    $prior = $stmt->fetchColumn();
    if ($prior !== false) {
        if ($prior !== $checksum) throw new RuntimeException('Migration file changed after it was recorded.');
        continue;
    }
    $pdo->exec($sql);
    $pdo->prepare('INSERT INTO coreflux_migrations (file_path, checksum_sha) VALUES (:p, :c)')
        ->execute(['p' => $relativePath, 'c' => $checksum]);
    echo "Applied $migrationName.\n";
}

$columns = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
       AND table_name = 'billing_payment_allocations' AND column_name = 'application_je_id'"
)->fetchColumn();
$account = $pdo->query(
    "SELECT COUNT(*) FROM accounting_accounts WHERE tenant_id = 999 AND code = '2300'"
)->fetchColumn();
$table = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
       AND table_name = 'billing_deposit_applications'"
)->fetchColumn();
$refundTable = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
       AND table_name = 'billing_deposit_refunds'"
)->fetchColumn();
$correctionColumns = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
       AND table_name IN ('billing_deposit_applications', 'billing_deposit_refunds')
       AND column_name IN ('reversed_at', 'reversal_je_id', 'reversal_reason', 'reversed_by_user_id')"
)->fetchColumn();
$requestHashColumn = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
       AND table_name = 'billing_payments' AND column_name = 'receipt_request_hash'"
)->fetchColumn();
if ((int) $columns !== 1 || (int) $account !== 1 || (int) $table !== 1
    || (int) $refundTable !== 1 || (int) $correctionColumns !== 8
    || (int) $requestHashColumn !== 1) {
    throw new RuntimeException('The customer-deposit schema did not verify after migration.');
}
echo "Customer-deposit staging schema verified.\n";
