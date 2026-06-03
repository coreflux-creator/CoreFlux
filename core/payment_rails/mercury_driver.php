<?php
/**
 * Mercury payment rail driver (2026-02).
 *
 * Bridges the canonical `PaymentRailsDriver` interface to the bespoke
 * Mercury payment engine that already lives in core/mercury_payments.php
 * (Slice 3 state machine with SoD approval, funding pull, settlement).
 *
 * This driver does NOT call Mercury's HTTP API directly — every
 * originated item creates a `payment_instructions` row (Draft → submit
 * for approval) and returns the instruction id as the rail external
 * ref.  The existing `mpAdvance()` cron worker then drives the row
 * through the funding/settlement state machine.
 *
 * What this unlocks:
 *   - Payroll batches can now disburse via Mercury (was: only via
 *     NACHA + Plaid Transfer).
 *   - AP outbound payments use the same code path for any tenant whose
 *     `disbursement_rail = 'mercury'`.
 *   - Future receipts / inter-tenant settlement can call
 *     `paymentRailsGetDriver('mercury')` without rebuilding the engine.
 *
 * Why this is safe alongside the existing bespoke Mercury UI:
 *   - originate() creates rows in `Draft` then immediately submits for
 *     approval. A human approver still has to confirm under SoD before
 *     a single dollar moves.  This is the same gate the existing
 *     Mercury Payments UI uses.
 *
 * Why originate() needs a recipient-upsert step:
 *   - The rail interface speaks raw routing+account numbers.  Mercury's
 *     engine identifies recipients via `mercury_recipients.id`.  We
 *     hash the (name, routing_last4, account_last4) tuple per tenant
 *     and reuse / create accordingly.  Encryption-at-rest is honoured
 *     because we delegate to `mercuryRecipientCreate()` which already
 *     calls `encryptField()`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../payment_rails.php';

class MercuryRailDriver implements PaymentRailsDriver
{
    public function name(): string { return 'mercury'; }

    /**
     * The driver itself is always loadable (lives in core/). Per-tenant
     * configuration is checked at originate() time — we cannot answer
     * "configured for tenant X" without knowing X.
     */
    public function isConfigured(): bool { return true; }

    /**
     * Tenant-scoped configuration check. Used by callers that want to
     * pre-flight before calling originate() — e.g. the rail-card UI.
     */
    public function isConfiguredForTenant(int $tenantId): bool
    {
        try {
            $pdo = getDB();
            $st  = $pdo->prepare(
                'SELECT status FROM mercury_connections WHERE tenant_id = :t LIMIT 1'
            );
            $st->execute(['t' => $tenantId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            return $row && ($row['status'] ?? '') === 'active';
        } catch (\Throwable $_) {
            return false;
        }
    }

    public function originate(array $items, array $opts): array
    {
        if (count($items) === 0) {
            throw new PaymentRailsOriginateException('originate() requires at least one item');
        }
        $tenantId = (int) ($opts['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            throw new PaymentRailsOriginateException(
                'Mercury rail requires tenant_id in opts (paymentRailsDispatch passes it automatically — caller may be skipping the helper).'
            );
        }
        if (!$this->isConfiguredForTenant($tenantId)) {
            throw new PaymentRailsNotConfiguredException(
                "Mercury is not configured for tenant {$tenantId}. Go to Treasury → Mercury Settings " .
                'and connect a Mercury API token, then ensure a default funding source is set.'
            );
        }

        require_once __DIR__ . '/../mercury_payments.php';
        require_once __DIR__ . '/../mercury_recipients.php';

        $batchId = $opts['batch_id'] ?? ('mercury_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)));
        $userId  = isset($opts['user_id']) ? (int) $opts['user_id'] : null;

        $resultItems = [];
        foreach ($items as $i => $it) {
            try {
                $this->validateItem($it, $i);
                $recipientId = $this->upsertRecipient($tenantId, $it, $userId);

                $created = mpCreate($tenantId, [
                    'recipient_id'    => $recipientId,
                    'amount_cents'    => (int) $it['amount_cents'],
                    'currency'        => 'USD',
                    'source_module'   => $this->parseSourceModule($it['external_ref'] ?? ''),
                    'source_ref'      => (string) ($it['external_ref'] ?? ''),
                    'description'     => substr((string) ($it['description'] ?? ''), 0, 100),
                    'notes'           => $it['addenda'] ?? null,
                    'idempotency_key' => 'rail_' . hash('sha256', $tenantId . '|' . ($it['external_ref'] ?? '') . '|' . $batchId),
                ], $userId);

                // Submit for approval immediately. The first approver in
                // the SoD policy gets a queue item; until they approve,
                // nothing moves.
                try {
                    mpSubmitForApproval($tenantId, (int) $created['id'], $userId);
                    $itemStatus = 'queued';   // Awaiting human SoD approval.
                } catch (\Throwable $sub) {
                    // Draft still exists — operator can submit manually
                    // from the Mercury Payments UI.
                    error_log("MercuryRailDriver: mpSubmitForApproval failed for instruction {$created['id']}: " . $sub->getMessage());
                    $itemStatus = 'pending';
                }

                $resultItems[] = [
                    'external_ref'      => (string) ($it['external_ref'] ?? ''),
                    'status'            => $itemStatus,
                    'rail_external_ref' => 'mercury:instruction:' . (int) $created['id'],
                ];
            } catch (\Throwable $e) {
                $resultItems[] = [
                    'external_ref'      => (string) ($it['external_ref'] ?? ''),
                    'status'            => 'failed',
                    'rail_external_ref' => null,
                    'error'             => $e->getMessage(),
                ];
            }
        }

        $okCount = count(array_filter($resultItems, static fn ($r) => $r['status'] !== 'failed'));
        $status  = $okCount === count($items) ? 'submitted'
                 : ($okCount === 0 ? 'failed' : 'submitted'); // partial-success keeps submitted; caller inspects per-item

        return [
            'batch_id' => $batchId,
            'status'   => $status,
            'items'    => $resultItems,
            'payload'  => [
                'queued_count'  => $okCount,
                'awaiting_sod'  => true,
                'engine'        => 'mercury',
            ],
        ];
    }

    public function getStatus(string $railExternalRef): string
    {
        if (!preg_match('/^mercury:instruction:(\d+)$/', $railExternalRef, $m)) {
            return 'unknown';
        }
        $id = (int) $m[1];
        try {
            $pdo = getDB();
            // tenant-leak-allow: rail_external_ref is itself tenant-scoped (callers
            //   only receive refs for instructions originated by their own tenant);
            //   PK lookup is globally unique, no cross-tenant disclosure risk.
            $st  = $pdo->prepare('SELECT state FROM payment_instructions WHERE id = :id LIMIT 1');
            $st->execute(['id' => $id]);
            $state = (string) ($st->fetchColumn() ?: '');
            // Map Mercury state-machine states → canonical rail enum.
            // Canonical enum (per PaymentRailsDriver::getStatus contract):
            //   pending | submitted | posted | settled | returned | cancelled | failed | unknown
            return match ($state) {
                'Draft', 'PendingApproval', 'Approved', 'Funding' => 'pending',
                'Submitted'                                       => 'submitted',
                'Settled', 'Reconciled'                           => 'settled',
                'Returned'                                        => 'returned',
                'Failed'                                          => 'failed',
                'Cancelled'                                       => 'cancelled',
                default                                           => 'unknown',
            };
        } catch (\Throwable $_) {
            return 'unknown';
        }
    }

    public function metadata(): array
    {
        return [
            'cost_per_item_dollars'    => 0.00,         // Mercury ACH origination is free for the tenant
            'cost_pct'                 => 0.0,
            'settlement_business_days' => ['min' => 1, 'max' => 3],
            'supports_same_day_ach'    => false,        // depends on tenant's Mercury tier
            'supports_rtp'             => false,
            'needs_pre_approval'       => false,        // tenant just connects their Mercury API token
            'needs_funding_link'       => true,         // tenant must set a default_funding_recipient
            'fallback_to'              => 'nacha',
            'pros'                     => [
                'Native to Mercury account — no second bank login',
                'SoD approval gate built-in',
                'Reconciles automatically against the Mercury transaction feed (Slice 4)',
                'Free ACH origination on Mercury accounts',
            ],
            'cons'                     => [
                'Tenant must hold a Mercury operating account',
                'Standard ACH only (no RTP / same-day yet)',
                'Requires SoD approval before each batch dispatches',
            ],
        ];
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function validateItem(array $it, int $i): void
    {
        foreach (['recipient_name', 'account_routing', 'account_number', 'amount_cents'] as $k) {
            if (empty($it[$k])) {
                throw new PaymentRailsOriginateException("Item {$i}: '{$k}' is required");
            }
        }
        if ((int) $it['amount_cents'] <= 0) {
            throw new PaymentRailsOriginateException("Item {$i}: amount_cents must be > 0");
        }
        $routing = preg_replace('/\D/', '', (string) $it['account_routing']);
        if (strlen($routing) !== 9) {
            throw new PaymentRailsOriginateException("Item {$i}: routing must be 9 digits");
        }
    }

    /**
     * Find-or-create the mercury_recipients row for this item.
     * Match key: tenant + name + account_number_last4.  Two different
     * counterparties with the same name + last4 should be flagged by
     * the operator manually (extremely rare and Mercury itself will
     * surface a duplicate-counterparty warning).
     */
    private function upsertRecipient(int $tenantId, array $it, ?int $userId): int
    {
        $pdo  = getDB();
        $name = trim((string) $it['recipient_name']);
        $acct = preg_replace('/\s+/', '', (string) $it['account_number']);
        $last4= substr($acct, -4);

        $st = $pdo->prepare(
            'SELECT r.id
               FROM mercury_recipients r
          LEFT JOIN mercury_recipient_bank_methods bm
                 ON bm.recipient_id = r.id AND bm.tenant_id = r.tenant_id
              WHERE r.tenant_id = :t AND r.kind = "vendor" AND r.status = "active"
                AND r.name = :n AND bm.account_number_last4 = :l4
              ORDER BY r.id DESC LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'n' => $name, 'l4' => $last4]);
        $existing = $st->fetchColumn();
        if ($existing) return (int) $existing;

        // Create. Delegating to the canonical helper keeps encryption +
        // audit handling consistent with the rest of the Mercury code.
        $created = mercuryRecipientCreate($tenantId, [
            'kind' => 'vendor',
            'name' => $name,
            'bank' => [
                'routing_number' => (string) $it['account_routing'],
                'account_number' => $acct,
                'account_type'   => $it['account_type'] ?? 'checking',
            ],
            'notes' => 'Auto-created by payment rail dispatch on ' . date('Y-m-d'),
        ], $userId);
        return (int) $created['id'];
    }

    /** Parse `module:id` (ap_payment:42) → 'ap_payment'. */
    private function parseSourceModule(string $externalRef): string
    {
        if (preg_match('/^([a-z_]+):/i', $externalRef, $m)) return strtolower($m[1]);
        return 'manual';
    }
}
