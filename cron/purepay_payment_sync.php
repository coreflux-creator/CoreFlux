<?php
/** Poll Pure//Pay as a safety net behind signed webhooks. Suggested: hourly. */

declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/payment_rails.php';

$pdo = getDB();
try {
    $rows = $pdo->query(
        "SELECT tenant_id, core_payment_id, purepay_bill_id, purepay_payment_id
           FROM purepay_payment_links
          WHERE status NOT IN ('settled','paid','completed','cleared','returned','cancelled','canceled','failed')
            AND (purepay_bill_id IS NOT NULL OR purepay_payment_id IS NOT NULL)
       ORDER BY updated_at ASC LIMIT 500"
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    fwrite(STDERR, "purepay_payment_sync: migration 130 not applied; skipping ({$e->getMessage()})\n");
    exit(0);
}
$driver = paymentRailsGetDriver('purepay');
$synced=0; $failed=0;
foreach ($rows as $row) {
    $ref = !empty($row['purepay_bill_id'])
        ? 'purepay:bill:' . $row['purepay_bill_id']
        : 'purepay:payment:' . $row['purepay_payment_id'];
    try {
        $status = $driver->getStatus($ref);
        $settled = $status === 'settled';
        if ((int) ($row['core_payment_id'] ?? 0) > 0) {
            $pdo->prepare(
                'UPDATE ap_payments SET rail_status=:s,
                        status=CASE WHEN :ok=1 AND status="sent" THEN "cleared" ELSE status END,
                        cleared_at=CASE WHEN :ok2=1 AND cleared_at IS NULL THEN NOW() ELSE cleared_at END
                  WHERE tenant_id=:t AND id=:id AND disbursement_rail="purepay"'
            )->execute([
                's'=>$status,'ok'=>$settled?1:0,'ok2'=>$settled?1:0,
                't'=>(int)$row['tenant_id'],'id'=>(int)$row['core_payment_id'],
            ]);
        }
        $synced++;
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDERR, "{$ref}: {$e->getMessage()}\n");
    }
}
fwrite(STDOUT, "purepay_payment_sync done: synced={$synced} failed={$failed}\n");
exit($failed > 0 ? 1 : 0);
