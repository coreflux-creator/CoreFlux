<?php
/**
 * Accounting — Cross-Tenant Intercompany Posting helper (sub-tenant aware).
 *
 * Distinct from `intercompany.php` (which handles entity-to-entity posting
 * INSIDE a single tenant). This helper posts a balanced *pair* of journal
 * entries across two SUB-TENANTS (different tenant_id), linked by a shared
 * `intercompany_ref` so a master_admin can always reconcile sister tenants.
 *
 * The two JEs are written transactionally: if the second fails, the first
 * is rolled back, and the helper re-throws.
 *
 * Memo and intercompany_ref propagate to both legs. The amount is debited
 * on the FROM tenant (typically reflecting an intercompany receivable or
 * funding) and credited on the TO tenant. The caller picks the GL accounts
 * — defaults are 1700 "Intercompany Receivable" / 2700 "Intercompany
 * Payable" if the chart of accounts contains those codes.
 *
 *   accountingPostCrossTenantIntercompany(
 *     fromTenantId, toTenantId,
 *     amount: 5000.00,
 *     memo: 'Q1 management fee',
 *     opts: ['from_account_code' => '1700', 'to_account_code' => '2700']
 *   );
 *
 * Returns: [
 *   'intercompany_ref' => 'IC-2026-XXXXXX',
 *   'from' => { tenant_id, je_id, je_number },
 *   'to'   => { tenant_id, je_id, je_number },
 * ]
 */

declare(strict_types=1);

require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/../../../core/sub_tenants.php';

function accountingPostCrossTenantIntercompany(
    int $fromTenantId,
    int $toTenantId,
    float $amount,
    string $memo,
    array $opts = [],
    ?int $actorUserId = null
): array {
    if ($fromTenantId === $toTenantId) {
        throw new InvalidArgumentException('from and to tenant must differ');
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('amount must be > 0');
    }

    // Sanity: both tenants must share the same master parent.
    $from = subTenantLookup($fromTenantId);
    $to   = subTenantLookup($toTenantId);
    if (!$from || !$to) {
        throw new InvalidArgumentException('tenant not found');
    }
    $fromMaster = $from['tenant_type'] === 'master' ? (int)$from['id'] : (int)($from['parent_id'] ?? 0);
    $toMaster   = $to['tenant_type']   === 'master' ? (int)$to['id']   : (int)($to['parent_id']   ?? 0);
    if ($fromMaster !== $toMaster || !$fromMaster) {
        throw new InvalidArgumentException('cross-tenant intercompany requires the same master parent');
    }

    $fromAccountCode  = (string) ($opts['from_account_code']  ?? '1700');
    $toAccountCode    = (string) ($opts['to_account_code']    ?? '2700');
    $fromOffsetCode   = (string) ($opts['from_offset_code']   ?? '1000');
    $toOffsetCode     = (string) ($opts['to_offset_code']     ?? '1000');
    $postingDate      = (string) ($opts['posting_date']       ?? date('Y-m-d'));
    $currency         = (string) ($opts['currency']           ?? 'USD');

    $ref = (string) ($opts['intercompany_ref']
        ?? sprintf('IC-%s-%s', date('Y'), strtoupper(bin2hex(random_bytes(3)))));

    $pdo = getDB();
    if (!$pdo) throw new RuntimeException('No database connection');

    $pdo->beginTransaction();
    try {
        $fromJe = accountingPostJe($fromTenantId, [
            'posting_date'    => $postingDate,
            'currency'        => $currency,
            'source_module'   => 'cross_tenant_intercompany',
            'description'     => $memo . " [{$ref}]",
            'idempotency_key' => "cross_intercompany:{$ref}:from",
            'lines' => [
                ['account_code' => $fromAccountCode, 'debit'  => $amount, 'memo' => $memo],
                ['account_code' => $fromOffsetCode,  'credit' => $amount, 'memo' => $memo],
            ],
        ], $actorUserId, true);

        $toJe = accountingPostJe($toTenantId, [
            'posting_date'    => $postingDate,
            'currency'        => $currency,
            'source_module'   => 'cross_tenant_intercompany',
            'description'     => $memo . " [{$ref}]",
            'idempotency_key' => "cross_intercompany:{$ref}:to",
            'lines' => [
                ['account_code' => $toOffsetCode,  'debit'  => $amount, 'memo' => $memo],
                ['account_code' => $toAccountCode, 'credit' => $amount, 'memo' => $memo],
            ],
        ], $actorUserId, true);

        $pdo->commit();

        subTenantAudit($fromMaster, $fromTenantId, $actorUserId, 'cross_tenant.intercompany.posted', [
            'ref'         => $ref,
            'amount'      => $amount,
            'memo'        => $memo,
            'to_tenant'   => $toTenantId,
            'from_je_id'  => $fromJe['je_id'],
            'to_je_id'    => $toJe['je_id'],
        ]);

        return [
            'intercompany_ref' => $ref,
            'amount'           => $amount,
            'memo'             => $memo,
            'from' => [
                'tenant_id' => $fromTenantId,
                'je_id'     => $fromJe['je_id'],
                'je_number' => $fromJe['je_number'],
            ],
            'to' => [
                'tenant_id' => $toTenantId,
                'je_id'     => $toJe['je_id'],
                'je_number' => $toJe['je_number'],
            ],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
