<?php
/** Two actual database sessions exercise the native AP allocation service. No provider IO. */
declare(strict_types=1);
$inCi = getenv('GITHUB_ACTIONS') === 'true' && getenv('CI') === 'true';
$local = getenv('COREFLUX_DELIVERY_LOCAL_FIXTURE') === '1'
    && in_array('--confirm-disposable-fixture', $argv, true)
    && (string) getenv('COREFLUX_DELIVERY_TEST_PASSWORD') !== '';
if (!$inCi && !$local) throw new RuntimeException('Explicit disposable fixture configuration required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../modules/ap/lib/ap.php';
require_once __DIR__.'/../core/migrate.php';
$password = $inCi ? 'root' : getenv('COREFLUX_DELIVERY_TEST_PASSWORD');
$connect = static fn() => new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$first = $connect();
$second = $connect();
$database = 'coreflux_ap_reservations_'.($inCi ? 'ci' : gmdate('YmdHis').'_'.getmypid());
$first->exec('CREATE DATABASE '.$database);
foreach ([$first, $second] as $connection) {
    $connection->exec('USE '.$database);
    $connection->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection->exec('SET SESSION innodb_lock_wait_timeout=5');
}
foreach (coreflux_split_sql_statements(file_get_contents(__DIR__.'/../modules/ap/migrations/001_init.sql')) as $sql) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS (ap_bills|ap_payments|ap_payment_allocations)\s*\(/', $sql)) $first->exec($sql);
}
foreach (['ap_bills', 'ap_payments'] as $table) $first->exec("ALTER TABLE $table ADD COLUMN entity_id BIGINT UNSIGNED NULL");
$checks = 0;
$assert = static function(bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
    echo "PASS $label\n";
};
$bill = static function(string $vendor, float $amount = 100, int $tenant = 1, int $entity = 11) use ($first): int {
    $ref = 'RES-'.bin2hex(random_bytes(5));
    $q = $first->prepare('INSERT INTO ap_bills
        (tenant_id,entity_id,bill_number,internal_ref,vendor_name,received_at,bill_date,due_date,total,amount_due,status)
        VALUES (?,?,?,?,?,"2026-10-01","2026-10-01","2026-10-31",?,?,"approved")');
    $q->execute([$tenant,$entity,$ref,$ref,$vendor,$amount,$amount]);
    return (int)$first->lastInsertId();
};
$payment = static function(string $vendor, float $amount = 100, int $tenant = 1, int $entity = 11) use ($first): int {
    $q = $first->prepare('INSERT INTO ap_payments
        (tenant_id,entity_id,vendor_name,pay_date,amount,unallocated_amount,status)
        VALUES (?,?,?,"2026-10-24",?,?,"draft")');
    $q->execute([$tenant,$entity,$vendor,$amount,$amount]);
    return (int)$first->lastInsertId();
};
$allocate = static function(PDO $connection, int $paymentId, array $request): array {
    $GLOBALS['pdo'] = $connection;
    return apAllocatePayment($paymentId, $request);
};
$request = static fn(int $billId, float $amount = 100) => ['allocations' => [['bill_id' => $billId, 'amount' => $amount]]];
$reserved = static function(int $billId) use ($first): float {
    $q = $first->prepare('SELECT COALESCE(SUM(a.amount_applied),0) FROM ap_payment_allocations a
        JOIN ap_payments p ON p.id=a.payment_id WHERE a.bill_id=? AND p.status IN ("draft","queued","sent")');
    $q->execute([$billId]);
    return (float)$q->fetchColumn();
};
try {
    // The first session has an old read view before the second commits a claim.
    // Locking the bill alone must not permit the old reservation snapshot to win.
    foreach (['draft', 'queued', 'sent'] as $state) {
        foreach (['explicit', 'fifo'] as $mode) {
            $vendor = "Synthetic $state $mode";
            $b = $bill($vendor); $p1 = $payment($vendor); $p2 = $payment($vendor);
            $first->beginTransaction();
            $first->query('SELECT COUNT(*) FROM ap_payment_allocations')->fetchColumn();
            $allocate($second, $p1, $request($b));
            $second->exec("UPDATE ap_payments SET status='$state' WHERE id=$p1");
            $result = $allocate($first, $p2, $mode === 'fifo' ? ['auto' => 'fifo'] : $request($b));
            $assert($first->inTransaction(), 'native allocation preserves its caller transaction');
            $first->commit();
            $assert($reserved($b) === 100.0 && $result['unallocated_remaining'] === 100.0,
                "$state reservation survives an older $mode transaction snapshot");
        }
    }
    $vendor = 'Synthetic partial reservation';
    $b = $bill($vendor); $p1 = $payment($vendor,40); $p2 = $payment($vendor);
    $first->beginTransaction();
    $first->query('SELECT COUNT(*) FROM ap_payment_allocations')->fetchColumn();
    $allocate($second,$p1,$request($b,40));
    $result=$allocate($first,$p2,$request($b)); $first->commit();
    $assert($reserved($b)===100.0 && $result['unallocated_remaining']===40.0,
        'partial reservation leaves only the current outstanding capacity');

    $vendor = 'Synthetic FIFO continues';
    $b1=$bill($vendor); $b2=$bill($vendor); $p1=$payment($vendor); $p2=$payment($vendor);
    $first->beginTransaction();
    $first->query('SELECT COUNT(*) FROM ap_payment_allocations')->fetchColumn();
    $allocate($second,$p1,$request($b1));
    $result=$allocate($first,$p2,['auto'=>'fifo']); $first->commit();
    $assert($reserved($b1)===100.0 && $reserved($b2)===100.0
        && array_column($result['applied'],'bill_id')===[$b2],
        'FIFO skips a newly reserved bill and continues to the next payable bill');

    $vendor='Synthetic released reservation';
    $b=$bill($vendor); $p1=$payment($vendor); $p2=$payment($vendor);
    $allocate($first,$p1,$request($b));
    $first->beginTransaction();
    $first->query('SELECT COUNT(*) FROM ap_payment_allocations')->fetchColumn();
    $second->exec("UPDATE ap_payments SET status='void' WHERE id=$p1");
    $result=$allocate($first,$p2,['auto'=>'fifo']); $first->commit();
    $assert($reserved($b)===100.0 && $result['unallocated_remaining']===0.0,
        'FIFO recognizes a reservation released after its original snapshot');

    $vendor='Synthetic duplicate target'; $b=$bill($vendor,80); $p=$payment($vendor);
    $result=$allocate($first,$p,['allocations'=>[['bill_id'=>$b,'amount'=>60],['bill_id'=>$b,'amount'=>40]]]);
    $assert($reserved($b)===80.0 && $result['unallocated_remaining']===20.0,
        'repeated bill targets observe reservations inserted earlier in the same transaction');

    foreach (['tenant','entity'] as $scope) {
        $vendor='Synthetic isolation '.$scope;
        $b=$bill($vendor,100,$scope==='tenant'?2:1,$scope==='entity'?22:11);
        $p=$payment($vendor); $rejected=false;
        try {$allocate($first,$p,$request($b));} catch (RuntimeException $e) {$rejected=true;}
        $assert($rejected && $reserved($b)===0.0 && !$first->inTransaction(), "$scope boundary remains enforced");
    }
    $assert((int)$first->query('SELECT COUNT(*) FROM ap_bills WHERE amount_paid<>0 OR amount_due<>total')->fetchColumn()===0,
        'reserving funds never settles a bill or changes its accounting balance');
    echo "AP payment reservations MariaDB: $checks passed\n";
} finally {
    foreach ([$first,$second] as $connection) if ($connection->inTransaction()) $connection->rollBack();
    $GLOBALS['pdo']=$first;
}
