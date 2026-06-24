<?php
/**
 * Tax-mapping AI suggester (Sprint 7f.2a).
 *
 *   POST /api/tax_mapping_ai_suggest.php
 *   Body: {
 *     tax_form_code: 'US-1040-SCH-C',
 *     account_ids?:  [12, 17, 21],     // optional — defaults to all unmapped
 *   }
 *
 *   → {
 *       tax_form_code, tax_form_label,
 *       suggestions: [
 *         { account_id, account_code, account_name, account_type,
 *           line, label, confidence, reasoning, model? }
 *       ],
 *       skipped: [{ account_id, reason }]
 *     }
 *
 * Strategy: one focused `aiAsk()` call carrying ALL unmapped accounts
 * for the form at once — the model is asked to return an array of
 * mapping suggestions. Cheaper + more consistent than per-account calls.
 *
 * Form-line catalogue is hard-coded per form code below — gives the
 * model a tight allowed-line list so it can't hallucinate "line 99".
 *
 * RBAC: `accounting.je.create` (write-class action).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/ai_service.php';

const TAX_FORM_LINES = [
    'US-1040-SCH-C' => [
        ['line' => '1',   'label' => 'Gross receipts or sales'],
        ['line' => '2',   'label' => 'Returns and allowances'],
        ['line' => '8',   'label' => 'Advertising'],
        ['line' => '9',   'label' => 'Car and truck expenses'],
        ['line' => '10',  'label' => 'Commissions and fees'],
        ['line' => '11',  'label' => 'Contract labor'],
        ['line' => '12',  'label' => 'Depletion'],
        ['line' => '13',  'label' => 'Depreciation'],
        ['line' => '14',  'label' => 'Employee benefit programs'],
        ['line' => '15',  'label' => 'Insurance (other than health)'],
        ['line' => '16a', 'label' => 'Mortgage interest'],
        ['line' => '16b', 'label' => 'Other interest'],
        ['line' => '17',  'label' => 'Legal and professional services'],
        ['line' => '18',  'label' => 'Office expense'],
        ['line' => '19',  'label' => 'Pension and profit-sharing plans'],
        ['line' => '20a', 'label' => 'Rent — vehicles, machinery, equipment'],
        ['line' => '20b', 'label' => 'Rent — other business property'],
        ['line' => '21',  'label' => 'Repairs and maintenance'],
        ['line' => '22',  'label' => 'Supplies'],
        ['line' => '23',  'label' => 'Taxes and licenses'],
        ['line' => '24a', 'label' => 'Travel'],
        ['line' => '24b', 'label' => 'Deductible meals'],
        ['line' => '25',  'label' => 'Utilities'],
        ['line' => '26',  'label' => 'Wages'],
        ['line' => '27a', 'label' => 'Other expenses'],
    ],
    'US-1120' => [
        ['line' => '1a',  'label' => 'Gross receipts or sales'],
        ['line' => '2',   'label' => 'Cost of goods sold'],
        ['line' => '12',  'label' => 'Compensation of officers'],
        ['line' => '13',  'label' => 'Salaries and wages'],
        ['line' => '14',  'label' => 'Repairs and maintenance'],
        ['line' => '15',  'label' => 'Bad debts'],
        ['line' => '16',  'label' => 'Rents'],
        ['line' => '17',  'label' => 'Taxes and licenses'],
        ['line' => '18',  'label' => 'Interest'],
        ['line' => '19',  'label' => 'Charitable contributions'],
        ['line' => '20',  'label' => 'Depreciation'],
        ['line' => '22',  'label' => 'Advertising'],
        ['line' => '24',  'label' => 'Employee benefit programs'],
        ['line' => '26',  'label' => 'Other deductions'],
    ],
    'US-1120-S' => [
        ['line' => '1a',  'label' => 'Gross receipts or sales'],
        ['line' => '2',   'label' => 'Cost of goods sold'],
        ['line' => '7',   'label' => 'Compensation of officers'],
        ['line' => '8',   'label' => 'Salaries and wages'],
        ['line' => '9',   'label' => 'Repairs and maintenance'],
        ['line' => '11',  'label' => 'Rents'],
        ['line' => '12',  'label' => 'Taxes and licenses'],
        ['line' => '13',  'label' => 'Interest'],
        ['line' => '14',  'label' => 'Depreciation'],
        ['line' => '16',  'label' => 'Advertising'],
        ['line' => '17',  'label' => 'Pension, profit-sharing, etc.'],
        ['line' => '18',  'label' => 'Employee benefit programs'],
        ['line' => '19',  'label' => 'Other deductions'],
    ],
    'US-1065' => [
        ['line' => '1a',  'label' => 'Gross receipts or sales'],
        ['line' => '2',   'label' => 'Cost of goods sold'],
        ['line' => '9',   'label' => 'Salaries and wages'],
        ['line' => '10',  'label' => 'Guaranteed payments to partners'],
        ['line' => '11',  'label' => 'Repairs and maintenance'],
        ['line' => '12',  'label' => 'Bad debts'],
        ['line' => '13',  'label' => 'Rent'],
        ['line' => '14',  'label' => 'Taxes and licenses'],
        ['line' => '15',  'label' => 'Interest'],
        ['line' => '16a', 'label' => 'Depreciation'],
        ['line' => '17',  'label' => 'Depletion'],
        ['line' => '18',  'label' => 'Retirement plans'],
        ['line' => '19',  'label' => 'Employee benefit programs'],
        ['line' => '20',  'label' => 'Other deductions'],
    ],
    'US-990' => [
        ['line' => '1a',  'label' => 'Contributions and grants'],
        ['line' => '2',   'label' => 'Program service revenue'],
        ['line' => '3',   'label' => 'Investment income'],
        ['line' => '5',   'label' => 'Salaries, other compensation, employee benefits'],
        ['line' => '13',  'label' => 'Grants and similar amounts paid'],
        ['line' => '15',  'label' => 'Compensation of officers, directors, trustees'],
        ['line' => '16a', 'label' => 'Professional fundraising fees'],
        ['line' => '17',  'label' => 'Other expenses'],
    ],
];

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'POST') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'accounting.je.create');
rbac_legacy_require($user, 'ai.use');

$body = api_json_body();
$form = trim((string) ($body['tax_form_code'] ?? ''));
if (!isset(TAX_FORM_LINES[$form])) api_error('Unknown tax_form_code', 422);

$pdo = getDB();

// Resolve the candidate accounts: explicit IDs, else all unmapped revenue/expense.
$ids  = isset($body['account_ids']) && is_array($body['account_ids']) ? array_values(array_filter(array_map('intval', $body['account_ids']))) : [];
if ($ids) {
    $place  = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$tid], $ids);
    $stmt   = $pdo->prepare(
        "SELECT id, code, name, account_type
           FROM accounting_accounts
          WHERE tenant_id = ? AND id IN ({$place})
            AND active = 1 AND is_postable = 1"
    );
    $stmt->execute($params);
} else {
    $stmt = $pdo->prepare(
        "SELECT a.id, a.code, a.name, a.account_type
           FROM accounting_accounts a
          WHERE a.tenant_id = :t
            AND a.active = 1 AND a.is_postable = 1
            AND a.account_type IN ('revenue','expense','cost_of_goods_sold','other_income','other_expense','contra_revenue')
            AND a.id NOT IN (
                SELECT account_id FROM accounting_tax_mappings
                 WHERE tenant_id = :t2 AND tax_form_code = :f
            )
       ORDER BY a.code ASC"
    );
    $stmt->execute(['t' => $tid, 't2' => $tid, 'f' => $form]);
}
$accounts = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

if (!$accounts) {
    api_ok([
        'tax_form_code' => $form,
        'suggestions'   => [],
        'skipped'       => [],
        'note'          => 'No candidate accounts to suggest mappings for.',
    ]);
}

$lines = TAX_FORM_LINES[$form];

$systemPrompt =
    "You are a senior US tax accountant. Map each chart-of-accounts entry to a single line on " . $form . "."
    . " For each input account, output: account_id, line, label, confidence (0..1), reasoning (one sentence)."
    . " The 'line' field MUST be one of the allowed lines. Skip any account that doesn't fit (omit it from the output) — better to skip than to mismap.";

try {
    $res = aiAsk([
        'feature_class'     => 'classification',
        'kind'              => 'classification',
        'feature_key'       => 'accounting.tax.auto_map',
        'system'            => $systemPrompt
            . " Output a single JSON object: { suggestions: [...] }.",
        'prompt'            => 'Allowed lines: ' . json_encode($lines)
            . "\nCandidate accounts: " . json_encode(array_map(static fn($a) => [
                'id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'type' => $a['account_type'],
            ], $accounts))
            . "\nReturn JSON: {\"suggestions\":[{\"account_id\":N,\"line\":\"22\",\"label\":\"Supplies\",\"confidence\":0.91,\"reasoning\":\"…\"}]}",
        'max_output_tokens' => 1500,
    ]);
    $raw = (string) ($res['text'] ?? '');
} catch (AIDisabledException $e) {
    api_error('AI disabled for this tenant', 503);
} catch (\Throwable $e) {
    api_error('AI suggestion failed: ' . $e->getMessage(), 502);
}

// Best-effort JSON extraction.
$parsed = json_decode($raw, true);
if (!is_array($parsed) && preg_match('/\{[\s\S]*\}/', $raw, $m)) {
    $parsed = json_decode($m[0], true);
}
if (!is_array($parsed) || !isset($parsed['suggestions']) || !is_array($parsed['suggestions'])) {
    api_ok([
        'tax_form_code' => $form,
        'suggestions'   => [],
        'skipped'       => array_map(static fn($a) => ['account_id' => (int) $a['id'], 'reason' => 'AI returned no parseable suggestions'], $accounts),
        'raw_ai'        => mb_substr($raw, 0, 600),
    ]);
}

$validLineSet = array_column($lines, 'line');
$labelByLine  = array_column($lines, 'label', 'line');
$accountIndex = [];
foreach ($accounts as $a) $accountIndex[(int) $a['id']] = $a;

$out     = [];
$skipped = [];
$seen    = [];
foreach ($parsed['suggestions'] as $s) {
    if (!is_array($s)) continue;
    $aid  = (int) ($s['account_id'] ?? 0);
    $line = trim((string) ($s['line'] ?? ''));
    $conf = (float) ($s['confidence'] ?? 0.6);
    $reasoning = (string) ($s['reasoning'] ?? '');
    if (!isset($accountIndex[$aid])) { $skipped[] = ['account_id' => $aid, 'reason' => 'Unknown account_id from AI']; continue; }
    if (!in_array($line, $validLineSet, true)) { $skipped[] = ['account_id' => $aid, 'reason' => "AI returned invalid line '{$line}'"]; continue; }
    if (isset($seen[$aid])) continue; // model duplicated an entry, ignore
    $seen[$aid] = true;
    $a = $accountIndex[$aid];
    $out[] = [
        'account_id'   => $aid,
        'account_code' => (string) $a['code'],
        'account_name' => (string) $a['name'],
        'account_type' => (string) $a['account_type'],
        'line'         => $line,
        'label'        => isset($s['label']) && trim((string) $s['label']) !== ''
            ? (string) $s['label']
            : (string) ($labelByLine[$line] ?? ''),
        'confidence'   => max(0.0, min(1.0, $conf)),
        'reasoning'    => $reasoning,
    ];
}
foreach ($accounts as $a) {
    if (!isset($seen[(int) $a['id']]) && !in_array((int) $a['id'], array_column($skipped, 'account_id'), true)) {
        $skipped[] = ['account_id' => (int) $a['id'], 'reason' => 'AI did not return a suggestion for this account'];
    }
}

api_ok([
    'tax_form_code'    => $form,
    'tax_form_lines'   => $lines,
    'suggestions'      => $out,
    'suggestion_count' => count($out),
    'skipped'          => $skipped,
    'model'            => $res['model'] ?? null,
    'interaction_id'   => $res['interaction_id'] ?? null,
]);
