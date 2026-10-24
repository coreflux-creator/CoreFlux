<?php
declare(strict_types=1);

require_once __DIR__ . '/../../modules/billing/lib/billing.php';
require_once __DIR__ . '/../../modules/ap/lib/ap.php';

/** Posted source-document balances at the same entity and date as the GL snapshot. */
function simSnapshotDocumentDue(int $tenantId, int $entityId, string $asOf): array
{
    $total = static function (array $rows): float {
        return round(array_sum(array_map(
            static fn (array $row): float => (float) $row['total_due'], $rows
        )), 2);
    };

    return [
        'ar_due' => $total(billingComputeAging($tenantId, $asOf, $entityId)),
        'ap_due' => $total(apComputeAging($tenantId, $asOf, $entityId)),
    ];
}
