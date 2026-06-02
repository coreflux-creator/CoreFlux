<?php
/**
 * AI Categorization Service.
 *
 * One central entry point that suggests an `accounting_accounts.id` for a
 * given bank or liability statement line, with a calibrated confidence score
 * (0.0 – 1.0). Compounds a per-tenant moat as users accept/override:
 *
 *   1.  HISTORY      — exact merchant_name match, ≥3 prior accepts → 0.97
 *                       1-2 prior accepts                          → 0.85
 *                      Lower-cardinality fuzzy match               → 0.60
 *   2.  PFC MAPPING  — Plaid `personal_finance_category.primary` →
 *                      well-known GL account_type filter           → 0.55-0.75
 *                      (e.g. FOOD_AND_DRINK → expense, recent restaurant a/c)
 *   3.  LLM FALLBACK — GPT-4o-mini, capped to expense+asset accounts,
 *                      explains its reasoning                       → 0.30-0.55
 *
 * Always records into:
 *   - ai_interactions (audit, status='ok'|'error')
 *   - ai_suggestions  (status='draft', confidence_score, suggested_value,
 *                      suggestion_source, prompt_version, model_version)
 *
 * On accept / override (called by account_transactions.php POST handlers):
 *   - ai_suggestions row updates → status='approved', accepted_as_is=0|1, final_value
 *   - ai_categorization_history upserts (signal=merchant|pfcategory)
 *
 * Versioning: PROMPT_VERSION + MODEL_VERSION constants here. Bump them when
 * the prompt or model changes so historical accept-rates aren't conflated.
 */
declare(strict_types=1);

const AI_CATEGORIZATION_PROMPT_VERSION = 'v1.0';
const AI_CATEGORIZATION_MODEL          = 'gpt-4o-mini';
const AI_CATEGORIZATION_MODEL_VERSION  = 'gpt-4o-mini-2024-07-18';
const AI_CATEGORIZATION_AUTO_ACCEPT    = 0.90;   // confidence threshold for one-click auto-post
const AI_CATEGORIZATION_FEATURE_KEY    = 'treasury.categorize.expense_account';

require_once __DIR__ . '/ai_service.php';

/**
 * Suggest a counterpart account for a statement line.
 *
 * @param int    $tenantId
 * @param array  $line       statement line row (must have: amount, posted_date,
 *                           description, merchant_name?, category?)
 * @param string $type       'deposit' | 'liability'
 * @param int    $sideAccountId  the bank/card account_id (so we never suggest itself)
 * @param array  $allAccounts    chart of accounts rows (id, code, name, account_type, is_postable)
 * @return array {
 *   suggestion_id:       int,
 *   suggested_account_id:int|null,
 *   confidence:          float,  // 0..1
 *   source:              'history'|'rules'|'llm'|'hybrid',
 *   reasoning:           string,
 *   auto_accept:         bool,   // confidence >= AI_CATEGORIZATION_AUTO_ACCEPT
 * }
 */
function aiSuggestCounterpartAccount(
    int $tenantId,
    array $line,
    string $type,
    int $sideAccountId,
    array $allAccounts
): array {
    $startedAt = microtime(true);
    $merchant  = trim((string) ($line['merchant_name'] ?? ''));
    $desc      = trim((string) ($line['description']    ?? ''));
    $pfcat     = trim((string) ($line['category']       ?? ''));
    $amount    = (float) ($line['amount'] ?? 0);

    // Whitelist of postable accounts (excluding the line's own side).
    $eligible = array_values(array_filter($allAccounts, function ($a) use ($sideAccountId) {
        return ((int) ($a['is_postable'] ?? 1)) === 1 && (int) $a['id'] !== $sideAccountId;
    }));

    $suggestion = aiCategorizationFromInterAccountTransfer($tenantId, $line, $type, $sideAccountId, $eligible);
    $source     = $suggestion ? 'transfer' : null;

    if (!$suggestion) {
        $suggestion = aiCategorizationFromHistory($tenantId, $merchant, $pfcat, $eligible);
        $source     = $suggestion ? 'history' : null;
    }

    if (!$suggestion) {
        $suggestion = aiCategorizationFromPfcRules($pfcat, $amount, $type, $eligible);
        $source     = $suggestion ? 'rules' : $source;
    }

    if (!$suggestion) {
        try {
            $suggestion = aiCategorizationFromLlm($tenantId, $merchant, $desc, $pfcat, $amount, $type, $eligible);
            $source     = $suggestion ? 'llm' : null;
        } catch (\Throwable $e) {
            error_log('[ai_categorization] LLM fallback failed: ' . $e->getMessage());
            $suggestion = null;
            $source     = null;
        }
    }

    $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);

    // Audit: record into ai_interactions even when we couldn't suggest.
    $interactionId = aiAuditWrite([
        'tenant_id'     => $tenantId,
        'feature_class' => 'classification',
        'feature_key'   => AI_CATEGORIZATION_FEATURE_KEY,
        'kind'          => 'classification',
        'status'        => $suggestion ? 'ok' : 'error',
        'model'         => $source === 'llm' ? AI_CATEGORIZATION_MODEL_VERSION : ($source ?: 'none'),
        'latency_ms'    => $latencyMs,
        'prompt'        => json_encode([
            'merchant' => $merchant, 'desc' => $desc, 'pfc' => $pfcat,
            'amount'   => $amount,   'type' => $type,
        ]),
        'response'      => $suggestion ? json_encode($suggestion) : null,
        'error'         => $suggestion ? null : 'no_suggestion',
    ]);

    // Persist a draft ai_suggestions row so we can track accept/override later.
    $suggestionId = aiInsertSuggestion($tenantId, [
        'interaction_id'   => $interactionId,
        'module'           => 'treasury',
        'feature_key'      => AI_CATEGORIZATION_FEATURE_KEY,
        'subject_type'     => $type === 'deposit' ? 'bank_statement_line' : 'liability_statement_line',
        'subject_id'       => (int) ($line['id'] ?? 0),
        'draft_content'    => $suggestion ? ($suggestion['reasoning'] ?? '') : 'No suggestion',
        'confidence_score' => $suggestion ? (float) $suggestion['confidence'] : null,
        'prompt_version'   => AI_CATEGORIZATION_PROMPT_VERSION,
        'model_version'    => $source === 'llm' ? AI_CATEGORIZATION_MODEL_VERSION : $source,
        'suggested_value'  => $suggestion ? (string) $suggestion['account_id'] : null,
        'suggestion_source'=> $source,
    ]);

    if (!$suggestion) {
        return [
            'suggestion_id'        => $suggestionId,
            'suggested_account_id' => null,
            'confidence'           => 0.0,
            'source'               => 'none',
            'reasoning'            => 'No confident suggestion',
            'auto_accept'          => false,
        ];
    }

    return [
        'suggestion_id'        => $suggestionId,
        'suggested_account_id' => (int) $suggestion['account_id'],
        'confidence'           => (float) $suggestion['confidence'],
        'source'               => $source,
        'reasoning'            => (string) ($suggestion['reasoning'] ?? ''),
        'auto_accept'          => $suggestion['confidence'] >= AI_CATEGORIZATION_AUTO_ACCEPT,
    ];
}

/**
 * Record the outcome of a suggestion (accept-as-is OR override). Updates
 * ai_suggestions.status + accepted_as_is + final_value, then upserts the
 * accepted final_value into ai_categorization_history so the next call gets
 * a high-confidence history hit.
 *
 * Safe to call with $suggestionId=0 (user posted a JE without an AI suggestion);
 * we then just upsert the history row keyed on merchant.
 */
function aiRecordCategorizationOutcome(
    int $tenantId,
    ?int $suggestionId,
    int $finalAccountId,
    array $line,
    int $userId
): void {
    $pdo      = getDB();
    $merchant = trim((string) ($line['merchant_name'] ?? ''));
    $pfcat    = trim((string) ($line['category']       ?? ''));

    if ($suggestionId && $suggestionId > 0) {
        $check = $pdo->prepare(
            'SELECT suggested_value FROM ai_suggestions
              WHERE tenant_id = :t AND id = :id LIMIT 1'
        );
        $check->execute(['t' => $tenantId, 'id' => $suggestionId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $suggestedAcctId = (int) ($row['suggested_value'] ?? 0);
            $acceptedAsIs   = ($suggestedAcctId === $finalAccountId) ? 1 : 0;
            $pdo->prepare(
                'UPDATE ai_suggestions
                    SET status         = "approved",
                        final_content  = :memo,
                        final_value    = :fv,
                        accepted_as_is = :ai,
                        reviewed_by    = :uid,
                        reviewed_at    = NOW(),
                        updated_at     = NOW()
                  WHERE tenant_id = :t AND id = :id'
            )->execute([
                't'   => $tenantId,
                'id'  => $suggestionId,
                'memo'=> 'Accepted ' . ($acceptedAsIs ? 'as-is' : 'with override'),
                'fv'  => (string) $finalAccountId,
                'ai'  => $acceptedAsIs,
                'uid' => $userId ?: null,
            ]);
        }
    }

    aiUpsertCategorizationHistory($tenantId, AI_CATEGORIZATION_FEATURE_KEY, 'merchant',
        strtolower($merchant), 'account_id:' . $finalAccountId);
    if ($pfcat !== '') {
        aiUpsertCategorizationHistory($tenantId, AI_CATEGORIZATION_FEATURE_KEY, 'pfcategory',
            strtolower($pfcat), 'account_id:' . $finalAccountId);
    }
}

// ───────────────────────────── private helpers ─────────────────────────────

/**
 * Detect bank-to-bank transfers between two CoreFlux-tracked accounts.
 *
 * Heuristics in priority order:
 *   1. Merchant/description name match against another bank account
 *      nickname/last4 in this tenant (Plaid enriches merchant_name on
 *      Mercury for inter-account moves: "First Citizens Bank - Checking
 *      ••9793", "Transfer from another bank account", etc.).
 *   2. Mirror-amount match: a row of the OPPOSITE sign + same |amount|
 *      on a different bank account within ±5 days.
 *
 * Returns the *other* bank's GL account (one of the eligible candidates)
 * with confidence 0.92 (merchant match) or 0.85 (mirror match) so the
 * suggestion auto-accepts under the 0.90 threshold for merchant-name
 * hits but stays draft for amount-only matches.
 */
function aiCategorizationFromInterAccountTransfer(
    int $tenantId,
    array $line,
    string $type,
    int $sideAccountId,
    array $eligible
): ?array {
    $merchant = strtolower(trim((string) ($line['merchant_name'] ?? '')));
    $desc     = strtolower(trim((string) ($line['description']    ?? '')));
    $amount   = (float) ($line['amount'] ?? 0);
    $date     = (string) ($line['posted_date'] ?? '');
    if (abs($amount) < 0.005) return null;

    $haystack = $merchant . ' ' . $desc;
    $transferKeywords = ['transfer', 'xfer', 'wire', 'ach in', 'ach out',
                         'internal transfer', 'between accounts',
                         'transfer from another bank', 'auto-routing',
                         'transfer in', 'transfer out'];
    $isTransferLike = false;
    foreach ($transferKeywords as $kw) {
        if (strpos($haystack, $kw) !== false) { $isTransferLike = true; break; }
    }

    $pdo = getDB();
    if (!$pdo) return null;

    // Load this tenant's bank accounts so we can recognise the other side
    // by nickname / last4 (the Plaid merchant_name carries something like
    // "First Citizens Bank - Checking ••9793").
    try {
        $stBanks = $pdo->prepare(
            'SELECT ba.id, ba.name, ba.last4, ba.gl_account_code,
                    aa.id AS gl_account_id, aa.code AS gl_code, aa.name AS gl_name,
                    aa.account_type
               FROM accounting_bank_accounts ba
               LEFT JOIN accounting_accounts aa
                      ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
              WHERE ba.tenant_id = :t AND ba.id != :self'
        );
        $stBanks->execute(['t' => $tenantId, 'self' => $sideAccountId]);
        $otherBanks = $stBanks->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) {
        $otherBanks = [];
    }

    $eligibleById = [];
    foreach ($eligible as $a) { $eligibleById[(int) $a['id']] = $a; }

    foreach ($otherBanks as $bank) {
        $glId = (int) ($bank['gl_account_id'] ?? 0);
        if (!$glId || !isset($eligibleById[$glId])) continue;
        $nick     = strtolower((string) ($bank['name']  ?? ''));
        $last4    = (string) ($bank['last4'] ?? '');
        $hitName  = $nick !== '' && strpos($haystack, $nick) !== false;
        $hitLast4 = $last4 !== '' && (strpos($haystack, $last4) !== false
                                   || strpos($haystack, '••' . $last4) !== false
                                   || strpos($haystack, '****' . $last4) !== false);
        if ($hitName || $hitLast4) {
            $reason = $hitName
                ? "Matches the nickname of your other bank \"{$bank['name']}\" — this looks like a bank-to-bank transfer, not income or an expense."
                : "Last 4 digits \"{$last4}\" match your other bank \"{$bank['name']}\" — transfer between your own accounts.";
            return [
                'account_id' => $glId,
                'confidence' => 0.92,
                'reasoning'  => $reason,
            ];
        }
    }

    // Generic transfer-keyword hit (e.g. "Transfer from another bank account")
    // without a name match. If we only have ONE other bank, we can confidently
    // point to it; otherwise we surface the keyword evidence as 0.75 + the
    // first eligible bank so the operator gets a useful starting point.
    if ($isTransferLike && !empty($otherBanks)) {
        $candidate = null;
        foreach ($otherBanks as $bank) {
            $glId = (int) ($bank['gl_account_id'] ?? 0);
            if ($glId && isset($eligibleById[$glId])) { $candidate = $bank; break; }
        }
        if ($candidate) {
            $glId = (int) $candidate['gl_account_id'];
            return [
                'account_id' => $glId,
                'confidence' => count($otherBanks) === 1 ? 0.88 : 0.75,
                'reasoning'  => 'Description contains a transfer keyword; the other CoreFlux-tracked bank "'
                              . $candidate['name'] . '" is the most likely counterpart.',
            ];
        }
    }

    // Mirror-amount probe — opposite-sign line within ±5 days on a different
    // bank in this tenant. This catches transfers even when the description
    // doesn't carry the other bank's name (e.g. a wire).
    if ($date !== '') {
        try {
            $stMirror = $pdo->prepare(
                "SELECT bsl.id, bsl.bank_account_id, bsl.amount, bsl.posted_date,
                        aa.id AS gl_account_id
                   FROM accounting_bank_statement_lines bsl
                   JOIN accounting_bank_accounts ba ON ba.id = bsl.bank_account_id AND ba.tenant_id = bsl.tenant_id
                   LEFT JOIN accounting_accounts aa ON aa.tenant_id = ba.tenant_id AND aa.code = ba.gl_account_code
                  WHERE bsl.tenant_id = :t
                    AND bsl.bank_account_id != :self
                    AND ABS(bsl.amount + :amt) < 0.005
                    AND bsl.posted_date BETWEEN DATE_SUB(:d, INTERVAL 5 DAY) AND DATE_ADD(:d2, INTERVAL 5 DAY)
                  LIMIT 1"
            );
            $stMirror->execute([
                't'    => $tenantId,
                'self' => $sideAccountId,
                'amt'  => $amount,
                'd'    => $date,
                'd2'   => $date,
            ]);
            $mirror = $stMirror->fetch(PDO::FETCH_ASSOC);
            if ($mirror) {
                $glId = (int) ($mirror['gl_account_id'] ?? 0);
                if ($glId && isset($eligibleById[$glId])) {
                    return [
                        'account_id' => $glId,
                        'confidence' => 0.85,
                        'reasoning'  => 'Mirror transaction (opposite-sign, same amount) found on another bank within 5 days — transfer between your own accounts.',
                    ];
                }
            }
        } catch (\Throwable $_) { /* schema may differ on legacy tenants */ }
    }

    return null;
}

function aiCategorizationFromHistory(int $tenantId, string $merchant, string $pfcat, array $eligible): ?array
{
    if ($merchant === '' && $pfcat === '') return null;
    $pdo = getDB();
    $bestRow    = null;
    $bestSignal = null;

    // Skip disabled rules (UI mute) + rules with more rejects than accepts
    // (the user's been overriding them — clearly the wrong learn). Order
    // by net score (accept - reject) so even a slightly-rejected merchant
    // with 10 accepts still wins over a clean 1-accept entry.
    $whereDisabled = ' AND disabled_at IS NULL ';
    $whereScore    = ' AND (accept_count - COALESCE(reject_count,0)) > 0 ';
    $orderScore    = ' ORDER BY (accept_count - COALESCE(reject_count,0)) DESC ';

    if ($merchant !== '') {
        $stmt = $pdo->prepare(
            "SELECT final_value, accept_count, COALESCE(reject_count,0) AS reject_count
               FROM ai_categorization_history
              WHERE tenant_id = :t AND feature_key = :f
                AND signal_kind = 'merchant' AND signal_value = :v
                $whereDisabled $whereScore
              $orderScore LIMIT 1"
        );
        $stmt->execute(['t' => $tenantId, 'f' => AI_CATEGORIZATION_FEATURE_KEY, 'v' => strtolower($merchant)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) { $bestRow = $row; $bestSignal = 'merchant'; }
    }
    if (!$bestRow && $pfcat !== '') {
        $stmt = $pdo->prepare(
            "SELECT final_value, accept_count, COALESCE(reject_count,0) AS reject_count
               FROM ai_categorization_history
              WHERE tenant_id = :t AND feature_key = :f
                AND signal_kind = 'pfcategory' AND signal_value = :v
                $whereDisabled $whereScore
              $orderScore LIMIT 1"
        );
        $stmt->execute(['t' => $tenantId, 'f' => AI_CATEGORIZATION_FEATURE_KEY, 'v' => strtolower($pfcat)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) { $bestRow = $row; $bestSignal = 'pfcategory'; }
    }
    if (!$bestRow) return null;

    if (!preg_match('/^account_id:(\d+)$/', $bestRow['final_value'], $m)) return null;
    $accountId = (int) $m[1];
    $stillEligible = false;
    foreach ($eligible as $a) { if ((int) $a['id'] === $accountId) { $stillEligible = true; break; } }
    if (!$stillEligible) return null;

    // Net score informs confidence. Rejects shave it back proportionally.
    $accepts = (int) $bestRow['accept_count'];
    $rejects = (int) $bestRow['reject_count'];
    $score   = $accepts - $rejects;
    $penalty = $rejects > 0 ? min(0.20, $rejects * 0.05) : 0.0;

    if ($bestSignal === 'merchant') {
        $base       = $score >= 3 ? 0.97 : ($score >= 1 ? 0.85 : 0.60);
        $confidence = max(0.40, $base - $penalty);
        $reasoning  = "You've categorized \"{$merchant}\" this way " . $accepts . "× before"
                    . ($rejects > 0 ? " (and overridden " . $rejects . "×)" : '') . ".";
    } else {
        $base       = $score >= 3 ? 0.78 : 0.62;
        $confidence = max(0.40, $base - $penalty);
        $reasoning  = "Plaid category \"{$pfcat}\" has been categorized this way " . $accepts . "× before"
                    . ($rejects > 0 ? " (and overridden " . $rejects . "×)" : '') . ".";
    }
    return ['account_id' => $accountId, 'confidence' => $confidence, 'reasoning' => $reasoning];
}

/**
 * Record a rejection — user picked a different account than the AI
 * suggested. Bumps reject_count on any matching history row keyed on
 * the line's merchant or pfcategory, so future suggestions de-rank.
 *
 * Idempotent: if no matching history row exists yet, we insert a
 * placeholder with accept_count=0 / reject_count=1 so the contested
 * pattern is still tracked.
 */
function aiRecordCategorizationReject(int $tenantId, array $line, int $rejectedAccountId): void
{
    $merchant = strtolower(trim((string) ($line['merchant_name'] ?? '')));
    $pfcat    = strtolower(trim((string) ($line['category']      ?? '')));
    if ($merchant === '' && $pfcat === '') return;
    $pdo  = getDB();
    foreach (
        array_filter([
            ['merchant', $merchant],
            ['pfcategory', $pfcat],
        ], fn ($p) => $p[1] !== '')
        as [$kind, $value]
    ) {
        $stmt = $pdo->prepare(
            "UPDATE ai_categorization_history
                SET reject_count     = reject_count + 1,
                    last_rejected_at = NOW()
              WHERE tenant_id = :t AND feature_key = :f
                AND signal_kind = :k AND signal_value = :v
                AND final_value = :fv"
        );
        $stmt->execute([
            't' => $tenantId, 'f' => AI_CATEGORIZATION_FEATURE_KEY,
            'k' => $kind, 'v' => $value, 'fv' => 'account_id:' . $rejectedAccountId,
        ]);
        if ($stmt->rowCount() === 0) {
            // Insert a placeholder (accept_count=0). UNIQUE KEY collision = ignore.
            try {
                $pdo->prepare(
                    "INSERT INTO ai_categorization_history
                        (tenant_id, feature_key, signal_kind, signal_value, final_value,
                         accept_count, reject_count, last_rejected_at, created_at)
                     VALUES (:t, :f, :k, :v, :fv, 0, 1, NOW(), NOW())"
                )->execute([
                    't' => $tenantId, 'f' => AI_CATEGORIZATION_FEATURE_KEY,
                    'k' => $kind, 'v' => $value, 'fv' => 'account_id:' . $rejectedAccountId,
                ]);
            } catch (\Throwable $_) { /* dupe = fine */ }
        }
    }
}

function aiCategorizationFromPfcRules(string $pfcat, float $amount, string $type, array $eligible): ?array
{
    if ($pfcat === '') return null;
    // Plaid → GL account_type + name-keyword preference.
    static $RULES = [
        'FOOD_AND_DRINK'      => ['expense', ['meals','food','restaurant','dining']],
        'GENERAL_MERCHANDISE' => ['expense', ['supplies','office']],
        'GENERAL_SERVICES'    => ['expense', ['services','consulting','contract']],
        'TRANSPORTATION'      => ['expense', ['travel','transport','uber','lyft','gas']],
        'TRAVEL'              => ['expense', ['travel','airfare','hotel','lodging']],
        'PERSONAL_CARE'       => ['expense', ['personal','health']],
        'MEDICAL'             => ['expense', ['medical','health','wellness']],
        'ENTERTAINMENT'       => ['expense', ['entertainment','event']],
        'HOME_IMPROVEMENT'    => ['expense', ['repair','maintenance','building']],
        'RENT_AND_UTILITIES'  => ['expense', ['rent','utilities','phone','internet']],
        'BANK_FEES'           => ['expense', ['fee','bank','charge']],
        'GOVERNMENT_AND_NON_PROFIT' => ['expense', ['tax','government','license']],
        'INCOME'              => ['revenue', ['revenue','sales','income']],
        'TRANSFER_IN'         => ['asset',   ['cash','bank','transfer']],
        'TRANSFER_OUT'        => ['asset',   ['cash','bank','transfer']],
        'LOAN_PAYMENTS'       => ['liability',['loan','note','payable']],
    ];
    $primary = strtoupper(preg_replace('/\s.*$/', '', $pfcat)); // first token if "FOOD_AND_DRINK / FAST_FOOD"
    if (!isset($RULES[$primary])) return null;
    [$wantType, $keywords] = $RULES[$primary];

    $candidates = array_values(array_filter($eligible, fn($a) => ($a['account_type'] ?? '') === $wantType));
    if (!$candidates) return null;

    $best = null; $bestScore = -1;
    foreach ($candidates as $a) {
        $score = 0;
        $haystack = strtolower(($a['name'] ?? '') . ' ' . ($a['code'] ?? ''));
        foreach ($keywords as $k) {
            if (strpos($haystack, $k) !== false) $score++;
        }
        if ($score > $bestScore) { $bestScore = $score; $best = $a; }
    }
    if (!$best) return null;

    $confidence = $bestScore >= 1 ? 0.72 : 0.55;
    return [
        'account_id' => (int) $best['id'],
        'confidence' => $confidence,
        'reasoning'  => "Plaid category \"{$pfcat}\" maps to {$wantType} accounts; \"{$best['code']} {$best['name']}\" matched {$bestScore} keyword(s).",
    ];
}

function aiCategorizationFromLlm(int $tenantId, string $merchant, string $desc, string $pfcat, float $amount, string $type, array $eligible): ?array
{
    $gate = aiGateForTenant($tenantId, 'classification');
    if (empty($gate['tenant_enabled']) || empty($gate['feature_enabled'])) return null;
    $cands = array_slice($eligible, 0, 50);
    if (!$cands) return null;

    $list = [];
    foreach ($cands as $a) {
        $list[] = sprintf("- id=%d code=%s type=%s name=%s",
            (int) $a['id'], $a['code'], $a['account_type'], $a['name']);
    }
    $accountText = implode("\n", $list);

    $prompt = <<<PROMPT
You are a bookkeeping assistant. Pick the MOST LIKELY counterpart GL account for a statement line.

Statement line:
  type: {$type}
  amount: {$amount}   (negative = charge/outflow, positive = payment/inflow)
  merchant: {$merchant}
  description: {$desc}
  plaid_category: {$pfcat}

Candidate accounts (id, code, type, name):
{$accountText}

Reply with STRICT JSON:
{"account_id": <int>, "confidence": <0.0-1.0>, "reasoning": "<1 sentence>"}

Rules:
- account_id MUST be one of the candidate ids.
- For charges (negative amount), prefer expense accounts.
- For payments (positive amount), prefer asset accounts (e.g. cash, bank).
- Confidence: 0.50 baseline; +0.15 if merchant name strongly suggests a category; -0.15 if the line is ambiguous.
- Keep reasoning under 140 chars.
PROMPT;

    [$content, $latencyMs, $modelUsed, $http, $error] = aiCallOpenAI([
        'model'    => AI_CATEGORIZATION_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a precise bookkeeping classifier. Reply with STRICT JSON only.'],
            ['role' => 'user',   'content' => $prompt],
        ],
        'response_format' => ['type' => 'json_object'],
        'temperature'     => 0.1,
        'max_tokens'      => 200,
    ]);
    if (!$content || $http < 200 || $http >= 300) return null;
    $j = json_decode($content, true);
    if (!is_array($j) || !isset($j['account_id'])) return null;

    $accId = (int) $j['account_id'];
    $eligibleIds = array_map(fn($a) => (int) $a['id'], $cands);
    if (!in_array($accId, $eligibleIds, true)) return null;

    $conf = max(0.30, min(0.85, (float) ($j['confidence'] ?? 0.5)));
    return [
        'account_id' => $accId,
        'confidence' => $conf,
        'reasoning'  => substr((string) ($j['reasoning'] ?? 'LLM suggestion'), 0, 200),
    ];
}

function aiInsertSuggestion(int $tenantId, array $row): int
{
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO ai_suggestions
            (tenant_id, interaction_id, module, feature_key, subject_type, subject_id,
             draft_content, status, confidence_score, prompt_version, model_version,
             suggested_value, suggestion_source, created_at)
         VALUES
            (:t, :iid, :m, :fk, :st, :si, :dc, "draft", :cs, :pv, :mv, :sv, :ss, NOW())'
    )->execute([
        't'   => $tenantId,
        'iid' => $row['interaction_id'] ?: null,
        'm'   => $row['module'],
        'fk'  => $row['feature_key'],
        'st'  => $row['subject_type'],
        'si'  => $row['subject_id'] ?: null,
        'dc'  => $row['draft_content'] ?? '',
        'cs'  => $row['confidence_score'],
        'pv'  => $row['prompt_version'],
        'mv'  => $row['model_version'],
        'sv'  => $row['suggested_value'],
        'ss'  => $row['suggestion_source'],
    ]);
    return (int) $pdo->lastInsertId();
}

function aiUpsertCategorizationHistory(int $tenantId, string $featureKey, string $signalKind, string $signalValue, string $finalValue): void
{
    if ($signalValue === '') return;
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO ai_categorization_history
            (tenant_id, feature_key, signal_kind, signal_value, final_value, accept_count, last_accepted_at)
         VALUES (:t, :fk, :sk, :sv, :fv, 1, NOW())
         ON DUPLICATE KEY UPDATE
            accept_count     = accept_count + 1,
            last_accepted_at = NOW()'
    )->execute([
        't'  => $tenantId, 'fk' => $featureKey, 'sk' => $signalKind,
        'sv' => $signalValue, 'fv' => $finalValue,
    ]);
}

/**
 * Aggregate a daily snapshot into ai_accuracy_daily for a date range.
 * Idempotent (REPLACE INTO). Cheap enough to call on dashboard load
 * for the current day; the dashboard backfills the last 30 days lazily.
 */
function aiRollupAccuracyDaily(int $tenantId, string $fromDate, string $toDate): int
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "REPLACE INTO ai_accuracy_daily
            (tenant_id, feature_key, snapshot_date,
             suggestions_count, accepted_count, overridden_count, rejected_count,
             avg_confidence, avg_accepted_conf, avg_overridden_conf)
         SELECT
            tenant_id,
            feature_key,
            DATE(COALESCE(reviewed_at, created_at)) AS d,
            COUNT(*) AS sc,
            SUM(CASE WHEN status='approved'  AND accepted_as_is = 1 THEN 1 ELSE 0 END),
            SUM(CASE WHEN status='approved'  AND accepted_as_is = 0 THEN 1 ELSE 0 END),
            SUM(CASE WHEN status='rejected'                          THEN 1 ELSE 0 END),
            AVG(confidence_score),
            AVG(CASE WHEN status='approved' AND accepted_as_is = 1 THEN confidence_score END),
            AVG(CASE WHEN status='approved' AND accepted_as_is = 0 THEN confidence_score END)
           FROM ai_suggestions
          WHERE tenant_id = :t
            AND DATE(COALESCE(reviewed_at, created_at)) BETWEEN :a AND :b
            AND confidence_score IS NOT NULL
          GROUP BY tenant_id, feature_key, d"
    );
    $stmt->execute(['t' => $tenantId, 'a' => $fromDate, 'b' => $toDate]);
    return $stmt->rowCount();
}
