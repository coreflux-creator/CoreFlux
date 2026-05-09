<?php
/**
 * AI agent suite (Sprint 7g).
 *
 * Five purpose-built agents, each one a thin wrapper around `aiAsk()`:
 *
 *   bookkeeper         → Books-health narrative ("here's what's behind, here's
 *                        what to check first"). Reads books_health envelope.
 *   reconciliation     → Reconciliation-readiness narrative. Reads bank
 *                        connections + last reconciliation + uncategorized.
 *   treasury_analyst   → Cash-position + payments/transfers narrative.
 *   cfo                → Strategic narrative across P&L trends + tasks.
 *   tax                → Tax-mapping coverage + posted activity narrative.
 *
 * All agents are STRICTLY ADVISORY (per AI_INTEGRATION_RULES.md). They never
 * emit values, formulas, or actions the app consumes — they produce plain
 * prose for human review. The chokepoint is `aiAsk()`; agents never call
 * OpenAI directly, never bypass tenant gating, never bypass the audit trail.
 */
declare(strict_types=1);

require_once __DIR__ . '/ai_service.php';
require_once __DIR__ . '/db.php';

const AI_AGENTS = [
    'bookkeeper' => [
        'label'        => 'AI Bookkeeper',
        'description'  => 'Reviews books-health signals and tells you where to focus first.',
        'feature_class'=> 'narrative',
        'feature_key'  => 'agent.bookkeeper.health_review',
        'kind'         => 'narrative',
        'system'       => 'You are an experienced staff accountant reviewing a small-business set of books. Speak in plain English to the operator. Highlight what looks healthy, what is behind, and what to check first. NEVER restate raw dollar amounts, balances, or formulas — refer to them only qualitatively (e.g. "your A/R aging is concentrated in the 60+ bucket"). Keep it 2–3 short paragraphs.',
        'context_fn'   => 'aiAgentContextBookkeeper',
    ],
    'reconciliation' => [
        'label'        => 'AI Reconciliation',
        'description'  => 'Spots reconciliation drift and recommends a focus order.',
        'feature_class'=> 'narrative',
        'feature_key'  => 'agent.reconciliation.review',
        'kind'         => 'summary',
        'system'       => 'You are a senior bookkeeper triaging reconciliation work. Given the bank-connection summary and the uncategorized-transaction queue, summarize the readiness in 3–5 short bullets. Do NOT include amounts. If the queue is large or stale, surface that as risk. Recommend an order of operations the human should follow.',
        'context_fn'   => 'aiAgentContextReconciliation',
    ],
    'treasury_analyst' => [
        'label'        => 'AI Treasury Analyst',
        'description'  => 'Narrates cash-position trends and pending payment risk.',
        'feature_class'=> 'narrative',
        'feature_key'  => 'agent.treasury.review',
        'kind'         => 'narrative',
        'system'       => 'You are a treasury analyst. Given cash-position signals plus pending payments and transfers, narrate the liquidity picture and any near-term risks. Reference balances QUALITATIVELY (concentrated/thin/cushioned) — never as numbers. Two short paragraphs maximum.',
        'context_fn'   => 'aiAgentContextTreasury',
    ],
    'cfo' => [
        'label'        => 'AI CFO',
        'description'  => 'Strategic narrative across P&L trends, books health, and treasury.',
        'feature_class'=> 'narrative',
        'feature_key'  => 'agent.cfo.review',
        'kind'         => 'narrative',
        'system'       => 'You are a fractional CFO advising the operator. Synthesize the P&L trend, books-health score, and treasury cushion into a concise strategic note: where the business is improving, where there is drift, and one or two questions the operator should bring to the next leadership conversation. Do NOT restate numbers — speak in directional terms. 2–3 short paragraphs.',
        'context_fn'   => 'aiAgentContextCFO',
    ],
    'tax' => [
        'label'        => 'AI Tax',
        'description'  => 'Reviews tax-mapping coverage and flags drift before year-end.',
        'feature_class'=> 'narrative',
        'feature_key'  => 'agent.tax.review',
        'kind'         => 'summary',
        'system'       => 'You are a tax accountant reviewing the tenant\'s chart-of-accounts coverage for tax-form mapping. Given the count of unmapped accounts and recent posted activity in those accounts, summarize the year-end readiness in 3–5 bullets. Highlight any account types that are systematically unmapped. Do NOT propose specific form lines — that\'s a separate AI feature.',
        'context_fn'   => 'aiAgentContextTax',
    ],
];

/**
 * Run an agent. Returns an aiAsk() envelope (incl. interaction_id) suitable
 * for the <AISuggestion /> component.
 */
function aiAgentRun(int $tenantId, string $agentKey): array
{
    if (!isset(AI_AGENTS[$agentKey])) {
        throw new \InvalidArgumentException("Unknown agent: {$agentKey}");
    }
    $agent = AI_AGENTS[$agentKey];

    // Build context from the relevant existing data sources.
    $contextFn = $agent['context_fn'];
    if (!function_exists($contextFn)) {
        throw new \RuntimeException("Agent context builder missing: {$contextFn}");
    }
    $context = $contextFn($tenantId);

    // The "prompt" is intentionally short — the system message carries the
    // agent's persona, and context is structured JSON the model interprets.
    $prompt = "Please produce your review based on the structured context provided.";

    return aiAsk([
        'feature_class' => $agent['feature_class'],
        'feature_key'   => $agent['feature_key'],
        'kind'          => $agent['kind'],
        'system'        => $agent['system'],
        'prompt'        => $prompt,
        'context'       => $context,
        'max_output_tokens' => 600,
    ]);
}

// ---------------------------------------------------------------------
// Context builders. Each returns a shallow array (≤ 8 keys) of qualitative
// signals — NEVER raw dollar figures the model can launder back into the
// narrative. The forbidden-keys check in aiAsk() also enforces this.
// ---------------------------------------------------------------------

function aiAgentContextBookkeeper(int $tenantId): array
{
    $pdo = getDB();
    $health = ['score' => null, 'reasons' => []];
    if ($pdo->query("SHOW TABLES LIKE 'accounting_journal_entries'")->fetchColumn()) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM accounting_journal_entries
              WHERE tenant_id = :t AND status = 'posted'
                AND posting_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
        );
        $stmt->execute(['t' => $tenantId]);
        $health['posted_je_30d_bucket'] = aiAgentBucketCount((int) $stmt->fetchColumn());
    }
    if ($pdo->query("SHOW TABLES LIKE 'accounting_bank_statement_lines'")->fetchColumn()) {
        $u = $pdo->prepare(
            "SELECT COUNT(*) FROM accounting_bank_statement_lines
              WHERE tenant_id = :t AND (match_status IS NULL OR match_status = 'pending')"
        );
        $u->execute(['t' => $tenantId]);
        $health['uncategorized_bucket'] = aiAgentBucketCount((int) $u->fetchColumn());
    }
    return $health;
}

function aiAgentContextReconciliation(int $tenantId): array
{
    $pdo = getDB();
    $ctx = [];
    if ($pdo->query("SHOW TABLES LIKE 'accounting_reconciliations'")->fetchColumn()) {
        $r = $pdo->prepare(
            "SELECT MAX(reconciled_through_date) FROM accounting_reconciliations WHERE tenant_id = :t"
        );
        $r->execute(['t' => $tenantId]);
        $last = $r->fetchColumn();
        $ctx['days_since_last_reconciliation_bucket'] = $last
            ? aiAgentBucketDays((int) ((time() - strtotime((string) $last)) / 86400))
            : 'never';
    }
    if ($pdo->query("SHOW TABLES LIKE 'accounting_bank_statement_lines'")->fetchColumn()) {
        $u = $pdo->prepare(
            "SELECT COUNT(*) FROM accounting_bank_statement_lines
              WHERE tenant_id = :t AND (match_status IS NULL OR match_status = 'pending')"
        );
        $u->execute(['t' => $tenantId]);
        $ctx['uncategorized_bucket'] = aiAgentBucketCount((int) $u->fetchColumn());
    }
    return $ctx;
}

function aiAgentContextTreasury(int $tenantId): array
{
    $pdo = getDB();
    $ctx = [];
    if ($pdo->query("SHOW TABLES LIKE 'treasury_payments'")->fetchColumn()) {
        $p = $pdo->prepare(
            "SELECT COUNT(*) FROM treasury_payments
              WHERE tenant_id = :t AND status IN ('draft','pending_approval','approved','scheduled')"
        );
        $p->execute(['t' => $tenantId]);
        $ctx['payments_pending_bucket'] = aiAgentBucketCount((int) $p->fetchColumn());
    }
    if ($pdo->query("SHOW TABLES LIKE 'treasury_transfers'")->fetchColumn()) {
        $t = $pdo->prepare(
            "SELECT COUNT(*) FROM treasury_transfers
              WHERE tenant_id = :t AND status IN ('draft','pending_approval','approved','scheduled')"
        );
        $t->execute(['t' => $tenantId]);
        $ctx['transfers_pending_bucket'] = aiAgentBucketCount((int) $t->fetchColumn());
    }
    if ($pdo->query("SHOW TABLES LIKE 'plaid_accounts'")->fetchColumn()) {
        $b = $pdo->prepare("SELECT COUNT(*) FROM plaid_accounts WHERE tenant_id = :t AND active = 1");
        $b->execute(['t' => $tenantId]);
        $ctx['active_bank_count_bucket'] = aiAgentBucketCount((int) $b->fetchColumn());
    }
    return $ctx;
}

function aiAgentContextCFO(int $tenantId): array
{
    // Re-uses bookkeeper + treasury qualitative signals; CFO does pattern
    // recognition rather than fresh measurement.
    return [
        'books'    => aiAgentContextBookkeeper($tenantId),
        'treasury' => aiAgentContextTreasury($tenantId),
    ];
}

function aiAgentContextTax(int $tenantId): array
{
    $pdo = getDB();
    $ctx = [];
    if ($pdo->query("SHOW TABLES LIKE 'accounting_tax_mappings'")->fetchColumn()) {
        $m = $pdo->prepare(
            "SELECT COUNT(*) FROM accounting_tax_mappings WHERE tenant_id = :t"
        );
        $m->execute(['t' => $tenantId]);
        $ctx['mapped_account_count_bucket'] = aiAgentBucketCount((int) $m->fetchColumn());
    }
    if ($pdo->query("SHOW TABLES LIKE 'accounting_accounts'")->fetchColumn()) {
        $u = $pdo->prepare(
            "SELECT COUNT(*) FROM accounting_accounts a
              WHERE a.tenant_id = :t
                AND a.account_type IN ('revenue','expense','cost_of_goods_sold','other_income','other_expense','contra_revenue')
                AND NOT EXISTS (
                    SELECT 1 FROM accounting_tax_mappings m
                     WHERE m.tenant_id = a.tenant_id AND m.account_id = a.id)"
        );
        $u->execute(['t' => $tenantId]);
        $ctx['unmapped_revenue_expense_account_count_bucket'] = aiAgentBucketCount((int) $u->fetchColumn());
    }
    return $ctx;
}

/**
 * Coarse bucket helper — emits qualitative size labels instead of numbers,
 * to keep the model from launderning raw values back into the narrative.
 */
function aiAgentBucketCount(int $n): string
{
    if ($n === 0)   return 'none';
    if ($n < 5)     return 'very_few';
    if ($n < 25)    return 'small';
    if ($n < 100)   return 'moderate';
    if ($n < 500)   return 'large';
    return 'very_large';
}

function aiAgentBucketDays(int $d): string
{
    if ($d <= 7)   return 'within_a_week';
    if ($d <= 30)  return 'within_a_month';
    if ($d <= 90)  return 'one_to_three_months';
    if ($d <= 180) return 'three_to_six_months';
    return 'over_six_months';
}
