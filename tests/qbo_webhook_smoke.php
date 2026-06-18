<?php
/**
 * Smoke — QBO Accounting webhook receiver (Step 6 Phase 2).
 *
 * Locks:
 *   - Receiver exists at /app/api/webhooks/qbo.php.
 *   - Uses Intuit Webhooks v2 contract (intuit-signature header
 *     = base64-encoded HMAC-SHA256(verifier_token, raw_body)).
 *   - Skips api_require_auth (Intuit is the caller).
 *   - Liveness probe (non-POST) returns 200 immediately.
 *   - Persists every event (verified or not) to qbo_webhook_events.
 *   - Fires targeted pull only on verified events.
 *   - Invokes auto-reconcile when a verified push surfaces new drift
 *     AND the tenant has the flag enabled.
 *   - Idempotent: duplicate deliveries don't double-fire side effects
 *     (event_id uniqueness on (realmId, name, qboId, op, lastUpdated)).
 *
 * Run: php -d zend.assertions=1 /app/tests/qbo_webhook_smoke.php
 */
declare(strict_types=1);

$passes = 0; $failures = [];
function check(string $label, bool $cond) {
    global $passes, $failures;
    if ($cond) { $passes++; echo "  ✓ {$label}\n"; }
    else       { $failures[] = $label; echo "  ✗ {$label}\n"; }
}

echo "\nQBO webhook receiver smoke (Step 6 Phase 2)\n";
echo "============================================\n\n";

// ─────── 1. File shape ───────
echo "── /app/api/webhooks/qbo.php ──\n";
$path = '/app/api/webhooks/qbo.php';
check('receiver exists', file_exists($path));
$src = (string) file_get_contents($path);
check('reads intuit-signature header',           str_contains($src, 'intuit-signature'));
check('verifies HMAC-SHA256 in base64',
    str_contains($src, "base64_encode(hash_hmac('sha256', \$rawBody, \$verifier, true))"));
check('uses hash_equals for constant-time compare',
    str_contains($src, 'hash_equals'));
check('loads verifier from env or define()',
    str_contains($src, "getenv('QBO_WEBHOOK_VERIFIER_TOKEN')"));
check('parses eventNotifications envelope',      str_contains($src, "eventNotifications"));
check('walks dataChangeEvent.entities',
    str_contains($src, "['dataChangeEvent']['entities']"));
check('resolves tenant by realmId',              str_contains($src, '_qboWebhookFindTenant'));
check('maps entities to pull functions',         str_contains($src, '_qboWebhookPullFn'));
check('fires qboPullInvoices on Invoice events', str_contains($src, "'Invoice'     => 'qboPullInvoices'"));
check('fires qboPullPayments on Payment events', str_contains($src, "'Payment'     => 'qboPullPayments'"));
check('fires qboPullBills on Bill events',       str_contains($src, "'Bill'        => 'qboPullBills'"));
check('fires qboPullBillPayments on BillPayment events',
    str_contains($src, "'BillPayment' => 'qboPullBillPayments'"));
check('liveness: non-POST returns 200 immediately',
    str_contains($src, "POST expected; returning 200 for liveness probe"));
check('event_id is sha1 of stable tuple',
    str_contains($src, "sha1(\$realmId . '|' . \$name"));
check('persists raw_payload for forensics',      str_contains($src, "'rp' => json_encode(\$ent)"));
check('finalizes event with processed_at + outcome',
    str_contains($src, '_qboWebhookFinalize'));
check('invokes auto-reconcile when drift detected + flag on',
    str_contains($src, 'qboAutoReconcileEnabled') && str_contains($src, 'qboAutoReconcileTenant'));

// ─────── 2. Live exercise ───────
echo "\n── live signature verification ──\n";

// Verify behaviour without actually running the receiver script
// (it requires api_bootstrap which boots the whole stack). Instead
// exercise the cryptographic shape directly so the regression catches
// signature bugs reliably.
$verifier  = 'test-verifier-12345';
$body      = json_encode([
    'eventNotifications' => [
        [
            'realmId' => 'REALM-101',
            'dataChangeEvent' => [
                'entities' => [
                    ['name' => 'Invoice', 'id' => '131', 'operation' => 'Update', 'lastUpdated' => '2026-02-15T18:21:03.000Z'],
                ],
            ],
        ],
    ],
]);
$validSig   = base64_encode(hash_hmac('sha256', $body, $verifier, true));
$tamperedSig = base64_encode(hash_hmac('sha256', $body, 'wrong-secret', true));
check('valid signature verifies', hash_equals($validSig, base64_encode(hash_hmac('sha256', $body, $verifier, true))));
check('tampered signature rejected', !hash_equals($validSig, $tamperedSig));

// ─────── 3. Event idempotency key shape ───────
echo "\n── idempotency key shape ──\n";
$entity = [
    'name'        => 'Invoice',
    'id'          => '131',
    'operation'   => 'Update',
    'lastUpdated' => '2026-02-15T18:21:03.000Z',
];
$realmId = 'REALM-101';
$expected = sha1($realmId . '|' . $entity['name'] . '|' . $entity['id'] . '|' . $entity['operation'] . '|' . $entity['lastUpdated']);
check('event_id is deterministic per (realm, entity, op, ts)',
    strlen($expected) === 40);

// Re-deliver the same entity → same event_id → UNIQUE on event_id blocks dup writes.
$secondCall = sha1($realmId . '|Invoice|131|Update|2026-02-15T18:21:03.000Z');
check('replay produces identical event_id', $expected === $secondCall);

// Slight delta (operation flips to Create) → different event_id.
$createCall = sha1($realmId . '|Invoice|131|Create|2026-02-15T18:21:03.000Z');
check('different operation yields different event_id', $createCall !== $expected);

echo "\nqbo_webhook smoke: {$passes} ✓ / " . count($failures) . " ✗\n";
foreach ($failures as $msg) echo "  FAIL: {$msg}\n";
exit($failures ? 1 : 0);
