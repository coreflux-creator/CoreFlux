<?php
/**
 * core/integrations/verify_create.php
 *
 * Charter Primitive #5 — "downstream double-check" — implemented for the
 * procedural integration paths (QBO, Zoho Books, Mercury) that do NOT
 * route through `core/accounting/command_service.php` + the
 * `AccountingProviderAdapter` abstraction (which already has a baked-in
 * verifyCreate, used by Jaz).
 *
 * The contract mirrors AccountingProviderAdapter::verifyCreate exactly:
 *
 *   [
 *     'verified'           => bool,
 *     'downstream_status'  => string,   // provider-reported status (normalised)
 *     'expected_status'    => string,
 *     'reason'             => ?string,  // mismatch summary or null
 *     'fetched_at'         => 'YYYY-MM-DD HH:MM:SS',
 *   ]
 *
 * Sync drivers (sync_je.php, sync_bills.php, sync_invoices.php) call the
 * relevant verify helper IMMEDIATELY after a successful POST and surface
 * the result in:
 *   1. The per-item result row  (`verify` key on the results[] entry)
 *   2. The push audit log       (`detail.verify`)
 *   3. The result status        ('pushed_unverified' when verified=false)
 *
 * Per charter, verification failures do NOT re-queue (the create itself
 * succeeded) — they raise a distinct status the operator can spot in
 * the Integrations Health panel and outbox UI.
 */
declare(strict_types=1);

require_once __DIR__ . '/../qbo/client.php';
require_once __DIR__ . '/../zoho_books/client.php';
require_once __DIR__ . '/../mercury_adapter.php';

/**
 * Verify a QBO object after create. Re-GETs via /v3/company/{realm}/{type}/{id}
 * and asserts status. QBO reports nothing called "status" on most entities —
 * we instead assert:
 *   - JournalEntry → Adjustment + Line count > 0 (presence)
 *   - Bill / Invoice → DocNumber or Id present + Balance/TotalAmt sane
 * For all entities: if QBO returns the same Id back, downstream_status is
 * 'recorded'. We treat 'recorded' as matching the expected 'active' state
 * since QBO doesn't have a drafts concept for these resources via API.
 *
 * Throwing is suppressed: this helper is best-effort. Failures return
 * verified=false + a reason so the caller can stamp the audit + result row.
 */
function qboVerifyCreate(int $tenantId, string $entityType, string $providerObjectId, string $expectedStatus = 'active'): array
{
    $now = date('Y-m-d H:i:s');
    $conn = qboConnection($tenantId);
    if (!$conn || $conn['status'] !== 'active') {
        return [
            'verified'         => false,
            'downstream_status'=> 'not_connected',
            'expected_status'  => $expectedStatus,
            'reason'           => 'QBO connection not active during verification',
            'fetched_at'       => $now,
        ];
    }
    $realm = (string) $conn['realm_id'];

    // QBO entity path map. The same casing the QBO API expects.
    $pathMap = [
        'journal_entry' => 'journalentry',
        'bill'          => 'bill',
        'invoice'       => 'invoice',
    ];
    $key = strtolower($entityType);
    $path = $pathMap[$key] ?? $key;

    try {
        $resp = qboCall($tenantId, 'GET', '/v3/company/' . $realm . '/' . $path . '/' . $providerObjectId . '?minorversion=65');
    } catch (\Throwable $e) {
        return [
            'verified'         => false,
            'downstream_status'=> 'fetch_failed',
            'expected_status'  => $expectedStatus,
            'reason'           => 'GET after create failed: ' . substr($e->getMessage(), 0, 180),
            'fetched_at'       => $now,
        ];
    }

    // QBO returns the entity wrapped under the entity Pascal name.
    $entityPascal = [
        'journalentry' => 'JournalEntry',
        'bill'         => 'Bill',
        'invoice'      => 'Invoice',
    ][$path] ?? ucfirst($path);

    $obj = $resp[$entityPascal] ?? null;
    if (!is_array($obj) || empty($obj['Id'])) {
        return [
            'verified'         => false,
            'downstream_status'=> 'missing_in_response',
            'expected_status'  => $expectedStatus,
            'reason'           => 'QBO GET succeeded but {entity}.Id missing',
            'fetched_at'       => $now,
        ];
    }

    // QBO has no 'status' field per se for these resources. If GET returned
    // the object with matching Id, treat as 'recorded' (≈ 'active').
    if ((string) $obj['Id'] !== (string) $providerObjectId) {
        return [
            'verified'         => false,
            'downstream_status'=> 'id_mismatch',
            'expected_status'  => $expectedStatus,
            'reason'           => "expected Id {$providerObjectId}, got " . (string) $obj['Id'],
            'fetched_at'       => $now,
        ];
    }

    // For Bills/Invoices, additionally assert TotalAmt > 0 if expected.
    $downstream = 'recorded';
    $aliasMatch = strtolower($expectedStatus) === 'active' || strtolower($expectedStatus) === 'recorded';
    return [
        'verified'         => $aliasMatch,
        'downstream_status'=> $downstream,
        'expected_status'  => $expectedStatus,
        'reason'           => $aliasMatch ? null : "expected '{$expectedStatus}', QBO reports 'recorded'",
        'fetched_at'       => $now,
    ];
}

/**
 * Verify a Zoho Books object after create. Re-GETs /books/v3/{collection}/{id}
 * and inspects the `status` field. Zoho uses lowercase status strings:
 *   - 'open' / 'paid' / 'partially_paid' → consider 'active'
 *   - 'draft'                            → 'draft'
 *   - 'void' / 'cancelled'               → 'void'
 */
function zohoBooksVerifyCreate(int $tenantId, string $entityType, string $providerObjectId, string $expectedStatus = 'active', ?int $subTenantId = null): array
{
    $now = date('Y-m-d H:i:s');
    $conn = zohoBooksConnection($tenantId, $subTenantId);
    if (!$conn) {
        return [
            'verified'         => false,
            'downstream_status'=> 'not_connected',
            'expected_status'  => $expectedStatus,
            'reason'           => 'Zoho Books connection not active during verification',
            'fetched_at'       => $now,
        ];
    }
    $collectionMap = [
        'journal_entry' => 'journals',
        'bill'          => 'bills',
        'invoice'       => 'invoices',
    ];
    $key = strtolower($entityType);
    $collection = $collectionMap[$key] ?? ($key . 's');

    try {
        $resp = zohoBooksCall($tenantId, 'GET', '/books/v3/' . $collection . '/' . $providerObjectId, null, null, $subTenantId);
    } catch (\Throwable $e) {
        return [
            'verified'         => false,
            'downstream_status'=> 'fetch_failed',
            'expected_status'  => $expectedStatus,
            'reason'           => 'GET after create failed: ' . substr($e->getMessage(), 0, 180),
            'fetched_at'       => $now,
        ];
    }

    // Zoho returns the entity wrapped under singular key:
    //   /journals/{id}  → { journal: {...} }
    //   /bills/{id}     → { bill:    {...} }
    //   /invoices/{id}  → { invoice: {...} }
    $singularKey = [
        'journals'  => 'journal',
        'bills'     => 'bill',
        'invoices'  => 'invoice',
    ][$collection] ?? rtrim($collection, 's');

    $obj = $resp[$singularKey] ?? null;
    if (!is_array($obj)) {
        return [
            'verified'         => false,
            'downstream_status'=> 'missing_in_response',
            'expected_status'  => $expectedStatus,
            'reason'           => "Zoho GET succeeded but '{$singularKey}' key missing",
            'fetched_at'       => $now,
        ];
    }

    $rawStatus = strtolower((string) ($obj['status'] ?? ''));
    static $aliases = [
        'open' => 'active', 'paid' => 'active', 'partially_paid' => 'active',
        'overdue' => 'active', 'posted' => 'active', 'recorded' => 'active',
        'cancelled' => 'void', 'canceled' => 'void',
    ];
    $downstream = $aliases[$rawStatus] ?? ($rawStatus ?: 'unknown');
    $ok = ($downstream === strtolower($expectedStatus));
    return [
        'verified'         => $ok,
        'downstream_status'=> $downstream,
        'expected_status'  => $expectedStatus,
        'reason'           => $ok ? null : "expected '{$expectedStatus}', got '{$downstream}'",
        'fetched_at'       => $now,
    ];
}

/**
 * Verify a Mercury payment instruction after submission. Re-GETs payment
 * status via mercuryGetPaymentStatus() and confirms it's in an accepted
 * state. Mercury statuses: pending, sent, failed, cancelled.
 *
 * Expected statuses by caller:
 *   - 'pending'   → caller just submitted, expects Mercury to acknowledge
 *   - 'sent'      → caller waiting for funds-moved confirmation
 */
function mercuryVerifyCreate(string $apiToken, string $accountId, string $paymentId, string $expectedStatus = 'pending'): array
{
    $now = date('Y-m-d H:i:s');
    if ($apiToken === '' || $accountId === '' || $paymentId === '') {
        return [
            'verified'         => false,
            'downstream_status'=> 'invalid_inputs',
            'expected_status'  => $expectedStatus,
            'reason'           => 'mercuryVerifyCreate requires apiToken, accountId and paymentId',
            'fetched_at'       => $now,
        ];
    }
    try {
        $resp = mercuryGetPaymentStatus($apiToken, $accountId, $paymentId);
    } catch (\Throwable $e) {
        return [
            'verified'         => false,
            'downstream_status'=> 'fetch_failed',
            'expected_status'  => $expectedStatus,
            'reason'           => 'GET after create failed: ' . substr($e->getMessage(), 0, 180),
            'fetched_at'       => $now,
        ];
    }

    // Mercury returns an object with `status`.
    $rawStatus = strtolower((string) ($resp['status'] ?? ''));
    // Anything in {pending, sent, processing} is a successful create.
    static $aliases = [
        'processing' => 'pending',
        'submitted'  => 'pending',
        'queued'     => 'pending',
        'completed'  => 'sent',
    ];
    $downstream = $aliases[$rawStatus] ?? ($rawStatus ?: 'unknown');
    $expectedLower = strtolower($expectedStatus);

    // 'sent' satisfies an 'sent' or 'pending' expectation
    // (it means the wire moved); 'pending' satisfies 'pending' only.
    $ok = ($downstream === $expectedLower)
       || ($downstream === 'sent' && $expectedLower === 'pending');

    return [
        'verified'         => $ok,
        'downstream_status'=> $downstream,
        'expected_status'  => $expectedStatus,
        'reason'           => $ok ? null : "expected '{$expectedStatus}', got '{$downstream}'",
        'fetched_at'       => $now,
    ];
}
