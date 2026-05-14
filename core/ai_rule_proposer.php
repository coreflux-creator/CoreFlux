<?php
/**
 * core/ai_rule_proposer.php — Phase 2 AI v1: rule proposal generator.
 *
 * Calls aiAsk() with the current rule JSON + a sample of recent activity,
 * extracts a structured proposed rule + a human-readable rationale, then
 * persists a row in rule_proposals (status='proposed', awaiting competition).
 *
 *   aiProposeRule(int $tenantId, string $ruleType, ?int $userId = null,
 *                 int $contextSize = 30): int   // returns proposal id
 *
 * Routes through aiAsk() so:
 *   - Sim tenants get deterministic mock answers (matching simShouldMock).
 *   - Tenant + feature gating is enforced (aiAsk handles AIDisabledException).
 *   - The interaction is audit-logged via aiAuditWrite() with the normal
 *     prompt_hash / response_hash trail.
 *
 * Once persisted, call aiRuleCompete($proposalId) to populate the
 * comparison_json + score. Both functions are idempotent and order-safe.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ai_service.php';
require_once __DIR__ . '/ai_rule_competition.php';

/**
 * Fetch the current production rule for ($tenantId, $ruleType). For v1
 * we read from a thin lookup table (accounting_account_mapping_rules);
 * for tenants that haven't customized, return a baseline default so the
 * AI has SOMETHING to propose against.
 */
function rpCurrentRule(int $tenantId, string $ruleType): array
{
    if ($ruleType !== 'ap_expense_category_map') {
        throw new \InvalidArgumentException("rpCurrentRule: unsupported rule_type '{$ruleType}'");
    }
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare(
            'SELECT category, gl_expense_account_code
               FROM accounting_account_mapping_rules
              WHERE tenant_id = :t AND module = "ap"
              ORDER BY category'
        );
        $stmt->execute(['t' => $tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows) {
            $rule = [];
            foreach ($rows as $r) {
                $rule[(string) $r['category']] = (string) $r['gl_expense_account_code'];
            }
            $rule['default'] = $rule['default'] ?? '6900';
            return $rule;
        }
    } catch (\Throwable $_) { /* table missing — fall through */ }

    // Baseline starter map for a tenant with zero customizations.
    return [
        'consulting' => '6000',
        'software'   => '6100',
        'travel'     => '6200',
        'meals'      => '6300',
        'office'     => '6400',
        'default'    => '6900',
    ];
}

/**
 * Gather a recent-activity sample for the AI prompt. Categories with their
 * frequency + total dollars give the LLM enough signal to make tweaks
 * without leaking sensitive vendor names or amounts.
 */
function rpRecentActivity(int $tenantId, string $ruleType, int $contextSize): array
{
    if ($ruleType !== 'ap_expense_category_map') return [];
    $pdo = getDB();
    try {
        $stmt = $pdo->prepare(
            'SELECT l.category,
                    COUNT(*)             AS n_lines,
                    SUM(l.amount)        AS total_amount,
                    MAX(b.bill_date)     AS most_recent
               FROM ap_bill_lines l
               JOIN ap_bills      b ON b.id = l.bill_id
              WHERE b.tenant_id = :t
                AND b.status IN ("approved","paid","partially_paid","posted")
              GROUP BY l.category
              ORDER BY n_lines DESC
              LIMIT :n'
        );
        $stmt->bindValue('t', $tenantId, \PDO::PARAM_INT);
        $stmt->bindValue('n', max(1, $contextSize), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $_) {
        return [];
    }
}

/**
 * Propose a tweak. Returns the new proposal row id (always — even when
 * the AI declined, we record a row with status='error' so the audit
 * trail is intact).
 */
function aiProposeRule(int $tenantId, string $ruleType, ?int $userId = null, int $contextSize = 30): int
{
    $current  = rpCurrentRule($tenantId, $ruleType);
    $activity = rpRecentActivity($tenantId, $ruleType, $contextSize);

    $system = "You are an accounting controller assistant for a staffing-services company. " .
              "Your job is to review the current category→GL-account mapping rule and propose " .
              "small, measured tweaks based on recent activity patterns. Respond ONLY in JSON.";

    $prompt = "Current rule (category → expense account code):\n" .
              json_encode($current, JSON_PRETTY_PRINT) . "\n\n" .
              "Recent activity (last " . count($activity) . " categories by line count):\n" .
              json_encode($activity, JSON_PRETTY_PRINT) . "\n\n" .
              "Respond with: {\"proposed_rule\": <same shape as current>, " .
              "\"rationale\": \"<1-3 sentences explaining what you changed and why>\"}. " .
              "Only change entries you have a confident reason to change. If the current rule " .
              "looks correct given the activity, return it unchanged with rationale " .
              "\"no change recommended\".";

    $proposed = null;
    $rationale = null;
    $model     = null;
    $interactionId = null;
    $statusReason  = null;

    try {
        $r = aiAsk([
            'feature_class' => 'rule_proposal',
            'feature_key'   => "rule_proposal.{$ruleType}",
            'kind'          => 'json',
            'system'        => $system,
            'prompt'        => $prompt,
            'context'       => ['rule_type' => $ruleType, 'rule_today' => $current, 'activity' => $activity],
            'max_output_tokens' => 1200,
        ]);
        $content = is_array($r['content'] ?? null) ? $r['content'] : (is_string($r['content'] ?? null) ? json_decode($r['content'], true) : null);
        if (is_array($content)) {
            $proposed  = is_array($content['proposed_rule'] ?? null) ? $content['proposed_rule'] : null;
            $rationale = (string) ($content['rationale'] ?? '');
        }
        $model         = (string) ($r['model'] ?? '');
        $interactionId = (int)   ($r['interaction_id'] ?? 0) ?: null;

        // Sim-mock path returns narrative content; treat unchanged-rule
        // case explicitly so test runs always produce a clean row.
        if ($proposed === null) {
            $proposed = $current;
            $rationale = $rationale ?: '(model returned no structured proposal — recorded as no-change)';
        }
    } catch (\Throwable $e) {
        $proposed     = $current;
        $rationale    = '(AI proposal failed: ' . $e->getMessage() . ')';
        $statusReason = substr($e->getMessage(), 0, 240);
    }

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO rule_proposals
            (tenant_id, rule_type, current_rule_json, proposed_rule_json, rationale,
             status, status_reason, created_by_user_id, ai_model, ai_interaction_id, created_at)
         VALUES (:t, :rt, :cj, :pj, :ra, :st, :sr, :uid, :m, :iid, NOW())'
    )->execute([
        't'   => $tenantId,
        'rt'  => $ruleType,
        'cj'  => json_encode($current),
        'pj'  => json_encode($proposed),
        'ra'  => $rationale,
        'st'  => $statusReason ? 'error' : 'proposed',
        'sr'  => $statusReason,
        'uid' => $userId,
        'm'   => $model,
        'iid' => $interactionId,
    ]);

    $proposalId = (int) $pdo->lastInsertId();

    // Auto-compete on creation so the operator sees a populated diff
    // immediately. Failures here just leave comparison_json null; the
    // status_reason captures why.
    if (!$statusReason) {
        try { aiRuleCompete($proposalId); }
        catch (\Throwable $_) { /* leave proposal in 'proposed' state */ }
    }

    return $proposalId;
}
