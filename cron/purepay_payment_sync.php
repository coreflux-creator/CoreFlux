<?php
/** Poll Pure//Pay as a safety net behind signed webhooks. Suggested: hourly. */

declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/payment_rails.php';
require_once __DIR__ . '/../core/purepay_webhooks.php';

$pdo = getDB();
try {
    $rows = $pdo->query(
        "SELECT l.tenant_id, l.core_payment_id, l.purepay_bill_id, l.purepay_payment_id
           FROM purepay_payment_links l
           LEFT JOIN ap_payments p ON p.tenant_id=l.tenant_id AND p.id=l.core_payment_id
          WHERE (l.status NOT IN ('settled','paid','completed','cleared','returned','cancelled','canceled','failed','settled_failed_review')
                 OR (p.status='sent' AND l.status IN ('returned','cancelled','canceled','failed')))
            AND (l.purepay_bill_id IS NOT NULL OR l.purepay_payment_id IS NOT NULL)
       ORDER BY l.updated_at ASC LIMIT 500"
    )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    fwrite(STDERR, "purepay_payment_sync: migration 146 not applied; skipping ({$e->getMessage()})\n");
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
        if ((int) ($row['core_payment_id'] ?? 0) > 0) {
            purepayApplyPaymentStatus((int) $row['tenant_id'], (int) $row['core_payment_id'], $status, $pdo);
        }
        $synced++;
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDERR, "{$ref}: {$e->getMessage()}\n");
    }
}
fwrite(STDOUT, "purepay_payment_sync done: synced={$synced} failed={$failed}\n");
exit($failed > 0 ? 1 : 0);
