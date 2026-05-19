<?php
/**
 * Per-line AI account suggestion (Sprint 7e.3 — bill/invoice UX polish).
 *
 *   POST /api/line_ai_suggest.php
 *   Body: {
 *     kind: 'ap_bill' | 'billing_invoice',
 *     description: 'Senior Engineer — Acme — week of Jul 1',
 *     item_type:  'labor',                 // optional
 *     unit_price: 125.00,                  // optional
 *     quantity:   40,                      // optional
 *     vendor_name | client_name: '...',    // optional
 *   }
 *
 *   → { suggestion: { account_code, account_name, confidence, reasoning,
 *                     source: 'history'|'llm' },
 *       review_required: bool }
 *
 * Strategy:
 *   1. Hit ai_categorization_history first — if a previous line in the
 *      same merchant + same item_type had an accepted code, return it
 *      with high confidence.
 *   2. Fall back to a focused aiAsk() — picks from the postable expense
 *      (or revenue) COA for the tenant. Confidence-scored.
 *   3. Logs to ai_interactions via aiAsk() chokepoint, so the audit
 *      trail + token spend show up in the Audit panel.
 *
 * The UI gates this with an explicit "Suggest" button — never auto-runs
 * on every keystroke.
 *
 * RBAC: any role with `ap.bill.create` (for ap_bill kind) or
 *       `billing.invoice.create` (for billing_invoice kind).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/ai_service.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body = api_json_body();
$kind = (string) ($body['kind'] ?? 'ap_bill');
if (!in_array($kind, ['ap_bill','billing_invoice'], true)) {
    api_error("kind must be 'ap_bill' or 'billing_invoice'", 422);
}
rbac_legacy_require($user, $kind === 'ap_bill' ? 'ap.bill.create' : 'billing.invoice.create');

$description = trim((string) ($body['description'] ?? ''));
$itemType    = trim((string) ($body['item_type']   ?? ''));
$unitPrice   = (float) ($body['unit_price'] ?? 0);
$quantity    = (float) ($body['quantity']   ?? 0);
$counter     = trim((string) ($body['vendor_name'] ?? $body['client_name'] ?? ''));
if ($description === '' && $counter === '') {
    api_error('description or vendor_name/client_name required for a useful suggestion', 422);
}

$amount = round($unitPrice * $quantity, 2);

$pdo = getDB();

// 1) History — has the same vendor/client + item_type produced an accepted
// account before? If yes, that's a high-confidence local-moat hit.
$historyRow = null;
try {
    $hStmt = $pdo->prepare(
        "SELECT final_account_id, COUNT(*) AS hits
           FROM ai_categorization_history
          WHERE tenant_id = :t
            AND (merchant_name LIKE :m OR description LIKE :d)
            AND final_account_id IS NOT NULL
       GROUP BY final_account_id
       ORDER BY hits DESC, MAX(created_at) DESC
          LIMIT 1"
    );
    $hStmt->execute([
        't' => $tid,
        'm' => '%' . substr($counter ?: $description, 0, 80) . '%',
        'd' => '%' . substr($description ?: $counter, 0, 80) . '%',
    ]);
    $historyRow = $hStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $_) { /* table absent on pre-AI tenants — fine */ }

if ($historyRow && (int) $historyRow['hits'] >= 2) {
    $acct = $pdo->prepare(
        'SELECT id, code, name FROM accounting_accounts
          WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    $acct->execute(['t' => $tid, 'id' => (int) $historyRow['final_account_id']]);
    $a = $acct->fetch(\PDO::FETCH_ASSOC);
    if ($a) {
        api_ok([
            'suggestion' => [
                'account_code'  => (string) $a['code'],
                'account_name'  => (string) $a['name'],
                'confidence'    => 0.92,
                'source'        => 'history',
                'reasoning'     => "Used " . (int) $historyRow['hits'] . " times before for this vendor/description.",
            ],
            'review_required' => false,
        ]);
    }
}

// 2) LLM fallback — pick from the eligible postable accounts.
// AP bills: expense + cost_of_goods_sold + other_expense
// Billing invoices: revenue + other_income + contra_revenue
$accountTypeFilter = $kind === 'ap_bill'
    ? "('expense','cost_of_goods_sold','other_expense')"
    : "('revenue','other_income','contra_revenue')";
$accStmt = $pdo->prepare(
    "SELECT code, name, account_type
       FROM accounting_accounts
      WHERE tenant_id = :t AND active = 1 AND is_postable = 1
        AND account_type IN {$accountTypeFilter}
      ORDER BY code ASC LIMIT 200"
);
$accStmt->execute(['t' => $tid]);
$candidates = $accStmt->fetchAll(\PDO::FETCH_ASSOC);
if (!$candidates) {
    api_ok([
        'suggestion'      => null,
        'review_required' => true,
        'note'            => 'No postable ' . ($kind === 'ap_bill' ? 'expense' : 'revenue') . ' accounts found for this tenant.',
    ]);
}

$systemPrompt = $kind === 'ap_bill'
    ? "You categorize an Accounts Payable bill line. Pick the single best expense / COGS / other-expense account from the candidates."
    : "You categorize a Billing invoice line. Pick the single best revenue / other-income / contra-revenue account from the candidates.";

try {
    $res = aiAsk([
        'feature_class'     => 'classification',
        'kind'              => 'classification',
        'feature_key'       => $kind === 'ap_bill' ? 'ap.bill_line.suggest_account' : 'billing.invoice_line.suggest_account',
        'system'            => $systemPrompt
            . " Return JSON with keys: account_code, confidence (0..1), reasoning (one sentence)."
            . " account_code MUST be one of the provided candidate codes.",
        'prompt'            => 'Line: ' . json_encode([
            'description' => $description,
            'item_type'   => $itemType,
            'amount'      => $amount,
            'counterparty'=> $counter,
        ]) . "\nReturn: {account_code, confidence, reasoning}",
        'context'           => ['candidates' => $candidates],
        'max_output_tokens' => 250,
    ]);
    $raw = (string) ($res['text'] ?? '');
    // Best-effort JSON extraction.
    $parsed = null;
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) $parsed = $j;
        elseif (preg_match('/\{.*\}/s', $raw, $m)) {
            $j2 = json_decode($m[0], true);
            if (is_array($j2)) $parsed = $j2;
        }
    }
    if (!$parsed || empty($parsed['account_code'])) {
        api_ok([
            'suggestion'      => null,
            'ai_response'     => $raw ?: null,
            'review_required' => true,
            'note'            => 'AI did not return a usable suggestion — pick manually.',
        ]);
    }
    $code = (string) $parsed['account_code'];
    $match = null;
    foreach ($candidates as $c) if ((string) $c['code'] === $code) { $match = $c; break; }
    if (!$match) {
        api_ok([
            'suggestion'      => null,
            'review_required' => true,
            'note'            => "AI suggested unknown account code '{$code}' — pick manually.",
        ]);
    }
    api_ok([
        'suggestion' => [
            'account_code'  => (string) $match['code'],
            'account_name'  => (string) $match['name'],
            'confidence'    => (float) ($parsed['confidence'] ?? 0.6),
            'source'        => 'llm',
            'reasoning'     => (string) ($parsed['reasoning'] ?? ''),
            'interaction_id' => $res['interaction_id'] ?? null,
            'model'         => $res['model'] ?? null,
        ],
        'review_required' => true,
    ]);
} catch (AIDisabledException $e) {
    api_ok([
        'suggestion'      => null,
        'ai_unavailable'  => true,
        'note'            => 'AI is off for this tenant — pick manually.',
        'review_required' => true,
    ]);
} catch (\Throwable $e) {
    api_ok([
        'suggestion'      => null,
        'ai_unavailable'  => true,
        'note'            => 'AI suggestion unavailable (' . substr($e->getMessage(), 0, 120) . ')',
        'review_required' => true,
    ]);
}
