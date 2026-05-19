<?php
/**
 * Accounting API — Bank rec AI assistants.
 *
 *   POST /api/accounting/bank_ai?action=suggest_match&line_id=N
 *        → returns top N candidate JE matches with confidence + reasoning.
 *
 *   POST /api/accounting/bank_ai?action=suggest_categorize&line_id=N
 *        → returns suggested COA account_code for an unmatched line.
 *
 *   POST /api/accounting/bank_ai?action=suggest_rule&line_id=N
 *        → returns a draft accounting_bank_rules row (pattern + target
 *          account) the user can save. Optionally with is_approved=1.
 *
 * All three use aiAsk() with feature_class='advisory' and return drafts
 * gated by <AISuggestion /> — accept/reject before anything mutates GL.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/ai_service.php';
require_once __DIR__ . '/../lib/accounting.php';
require_once __DIR__ . '/../lib/bank_rec.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

if ($method !== 'POST') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'accounting.je.create');

$lineId = (int) ($_GET['line_id'] ?? 0);
if ($lineId <= 0) api_error('line_id required', 400);

$line = scopedFind(
    'SELECT bsl.*, ba.gl_account_code AS bank_gl_code, ba.name AS bank_name
     FROM accounting_bank_statement_lines bsl
     JOIN accounting_bank_accounts ba ON ba.id = bsl.bank_account_id AND ba.tenant_id = bsl.tenant_id
     WHERE bsl.tenant_id = :tenant_id AND bsl.id = :id',
    ['id' => $lineId]
);
if (!$line) api_error('Line not found', 404);

$tenantId = (int) $ctx['tenant_id'];

if ($action === 'suggest_match') {
    $candidates = bankRecAutoSuggestMatches($tenantId, $line, (int) $line['bank_account_id']);
    if (empty($candidates)) {
        api_ok([
            'candidates'      => [],
            'review_required' => true,
            'note'            => 'No JE candidates within the date / amount window. Try widening the search or use suggest_categorize to draft a new JE instead.',
        ]);
    }
    try {
        $res = aiAsk([
            'feature_class'   => 'advisory',
            'kind'            => 'classification',
            'feature_key'     => 'accounting.bank.suggest_match',
            'system'          => 'Pick the most likely JE that matches this bank-statement line. Score each candidate 0-1 and explain in one sentence why.',
            'prompt'          => 'Bank line: ' . json_encode([
                'date'        => $line['posted_date'],
                'description' => $line['description'],
                'amount'      => (float) $line['amount'],
            ]) . "\nReturn: {best_je_id, confidence, reasoning, top_n: [{je_id, confidence, reasoning}]}",
            'context'         => ['candidates' => $candidates],
            'max_output_tokens' => 600,
        ]);
        api_ok([
            'candidates'       => $candidates,
            'ai_response'      => $res['text'] ?? null,
            'interaction_id'   => $res['interaction_id'] ?? null,
            'model'            => $res['model']          ?? null,
            'review_required'  => true,
        ]);
    } catch (AIDisabledException $e) {
        api_ok([
            'candidates'       => $candidates,
            'ai_response'      => null,
            'ai_unavailable'   => true,
            'note'             => 'AI is off for this tenant — picking the closest match by amount + date. Review and confirm manually below.',
            'review_required'  => true,
        ]);
    } catch (\Throwable $e) {
        api_ok([
            'candidates'       => $candidates,
            'ai_response'      => null,
            'ai_unavailable'   => true,
            'note'             => 'AI service unavailable (' . substr($e->getMessage(), 0, 120) . '). Use the candidates list and pick manually.',
            'review_required'  => true,
        ]);
    }
}

if ($action === 'suggest_categorize') {
    // Use the unified ai_categorization service (history → rules → LLM cascade)
    // so deposits feed the same per-tenant moat as liabilities. Returns a
    // structured suggestion + a confidence score 0-1, persisted as a draft
    // ai_suggestions row keyed on (subject_type='bank_statement_line', subject_id).
    require_once __DIR__ . '/../../../core/ai_categorization.php';
    $accounts = scopedQuery(
        'SELECT id, code, name, account_type, is_postable
           FROM accounting_accounts
          WHERE tenant_id = :tenant_id AND active = 1
          ORDER BY code ASC LIMIT 1000'
    );
    $bankAcct = scopedFind(
        'SELECT aa.id FROM accounting_bank_accounts ba
           JOIN accounting_accounts aa ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
          WHERE ba.tenant_id = :tenant_id AND ba.id = :id LIMIT 1',
        ['id' => (int) $line['bank_account_id']]
    );
    $sideAccountId = (int) ($bankAcct['id'] ?? 0);
    try {
        $sug = aiSuggestCounterpartAccount(
            $tenantId,
            [
                'id'            => $lineId,
                'amount'        => (float) $line['amount'],
                'posted_date'   => $line['posted_date'],
                'description'   => $line['description'],
                'merchant_name' => $line['description'],   // bank-rec lines lack a merchant col
                'category'      => null,                   // ditto pfcategory
            ],
            'deposit',
            $sideAccountId,
            $accounts
        );
        api_ok([
            'suggestion'      => $sug,
            'review_required' => !$sug['auto_accept'],
        ]);
    } catch (\Throwable $e) {
        api_ok([
            'suggestion'      => null,
            'ai_unavailable'  => true,
            'note'            => 'AI suggestion unavailable (' . substr($e->getMessage(), 0, 120) . '). Categorize manually using the COA dropdown.',
            'review_required' => true,
        ]);
    }
}

if ($action === 'suggest_rule') {
    // Generalize the description into a rule pattern and pair it with
    // a target_account_code. The user can save with is_approved=0 (default)
    // or flip to is_approved=1 to auto-apply on future imports.
    $coa = scopedQuery(
        'SELECT code, name, account_type FROM accounting_accounts
         WHERE tenant_id = :tenant_id AND active = 1
         ORDER BY account_type, code'
    );
    $existingRules = scopedQuery(
        'SELECT id, name, pattern_kind, pattern, target_account_code, is_approved
         FROM accounting_bank_rules WHERE tenant_id = :tenant_id AND status = "active"
         LIMIT 50'
    );
    try {
        $res = aiAsk([
            'feature_class'   => 'advisory',
            'kind'            => 'classification',
            'feature_key'     => 'accounting.bank.suggest_rule',
            'system'          => 'Propose a single bank-rec rule. The pattern must be the most generalizable substring '
                                . 'of the bank line description (drop trailing IDs/amounts/dates). Pattern_kind should '
                                . 'be "contains" unless the description is short and stable, in which case "starts_with". '
                                . 'Pick a target_account_code from the provided COA. Do NOT propose a rule that duplicates '
                                . 'an existing one. Suggest is_approved=0 (default — staged for review) unless the merchant '
                                . 'is unambiguous (e.g. "AWS", "Stripe Fee").',
            'prompt'          => 'Bank line: ' . json_encode([
                'date'        => $line['posted_date'],
                'description' => $line['description'],
                'amount'      => (float) $line['amount'],
            ]) . "\nReturn: {name, pattern_kind, pattern, target_account_code, suggested_is_approved, reasoning}",
            'context'         => ['coa' => $coa, 'existing_rules' => $existingRules],
            'max_output_tokens' => 400,
        ]);
        api_ok([
            'ai_response'     => $res['text'] ?? null,
            'interaction_id'  => $res['interaction_id'] ?? null,
            'model'           => $res['model']          ?? null,
            'review_required' => true,
        ]);
    } catch (\Throwable $e) {
        api_ok([
            'ai_response'     => null,
            'ai_unavailable'  => true,
            'note'            => 'AI rule drafting unavailable (' . substr($e->getMessage(), 0, 120) . '). Create a rule manually below.',
            'review_required' => true,
        ]);
    }
}

api_error('Unknown action', 400);
