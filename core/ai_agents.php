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
        'domain'       => ['accounting'],
        'modules'      => ['accounting'],
        'system'       => 'You are an experienced staff accountant reviewing a small-business set of books. Speak in plain English to the operator. Highlight what looks healthy, what is behind, and what to check first. NEVER restate raw dollar amounts, balances, or formulas — refer to them only qualitatively (e.g. "your A/R aging is concentrated in the 60+ bucket"). Keep it 2–3 short paragraphs.',
        'context_fn'   => 'aiAgentContextBookkeeper',
    ],
    'reconciliation' => [
        'label'        => 'AI Reconciliation',
        'description'  => 'Spots reconciliation drift and recommends a focus order.',
        'feature_class'=> 'narrative',
        'feature_key'  => 'agent.reconciliation.review',
        'kind'         => 'summary',
        'domain'       => ['accounting'],
        'modules'      => ['accounting'],
        'system'       => 'You are a senior bookkeeper triaging reconciliation work. Given the bank-connection summary and the uncategorized-transaction queue, summarize the readiness in 3–5 short bullets. Do NOT include amounts. If the queue is large or stale, surface that as risk. Recommend an order of operations the human should follow.',
        'context_fn'   => 'aiAgentContextReconciliation',
    ],
    'treasury_analyst' => [
        'label'        => 'AI Treasury Analyst',
        'description'  => 'Narrates cash-position trends and pending payment risk.',
        'feature_class'=> 'narrative',
        'feature_key'  => 'agent.treasury.review',
        'kind'         => 'narrative',
        'domain'       => ['treasury'],
        'modules'      => ['treasury'],
        'system'       => 'You are a treasury analyst. Given cash-position signals plus pending payments and transfers, narrate the liquidity picture and any near-term risks. Reference balances QUALITATIVELY (concentrated/thin/cushioned) — never as numbers. Two short paragraphs maximum.',
        'context_fn'   => 'aiAgentContextTreasury',
    ],
    'cfo' => [
        'label'        => 'AI CFO',
        'description'  => 'Strategic narrative across P&L trends, books health, and treasury.',
        'feature_class'=> 'narrative',
        'feature_key'  => 'agent.cfo.review',
        'kind'         => 'narrative',
        'domain'       => ['strategy'],
        'modules'      => ['accounting', 'treasury'],
        'system'       => 'You are a fractional CFO advising the operator. Synthesize the P&L trend, books-health score, and treasury cushion into a concise strategic note: where the business is improving, where there is drift, and one or two questions the operator should bring to the next leadership conversation. Do NOT restate numbers — speak in directional terms. 2–3 short paragraphs.',
        'context_fn'   => 'aiAgentContextCFO',
    ],
    // Phase A.5 — split: CFO Variance is the period-over-period delta voice
    // (separate from the strategic CFO above which is a snapshot voice). It
    // calls out which qualitative buckets moved week-over-week so the
    // operator hears variance signals first, narrative second.
    'cfo_variance' => [
        'label'        => 'AI CFO Variance',
        'description'  => 'Period-over-period variance commentary across books + treasury.',
        'feature_class'=> 'narrative',
        'feature_key'  => 'agent.cfo_variance.review',
        'kind'         => 'summary',
        'domain'       => ['strategy'],
        'modules'      => ['accounting', 'treasury'],
        'system'       => 'You are a CFO focused on variance analysis. Given qualitative bucket signals across books health, treasury cushion, and reconciliation cadence, summarize what HAS CHANGED versus the prior period and what direction the trend is moving in 3–5 bullets. Do NOT restate numbers. Surface both improvements and regressions. If you cannot identify a meaningful delta, say so explicitly.',
        'context_fn'   => 'aiAgentContextCFOVariance',
    ],
    // Phase A.5 — split: Treasury Payments is the queue-health voice
    // (distinct from Treasury Analyst above which is a cash-position voice).
    'treasury_payments' => [
        'label'        => 'AI Treasury Payments',
        'description'  => 'Reviews the pending-payment queue health and approval bottlenecks.',
        'feature_class'=> 'narrative',
        'feature_key'  => 'agent.treasury_payments.review',
        'kind'         => 'summary',
        'domain'       => ['treasury'],
        'modules'      => ['treasury'],
        'system'       => 'You are a treasury operations lead. Given qualitative signals about the pending-payment queue (counts in draft / pending_approval / approved / scheduled buckets) and the count of disputed AP bills, summarize queue health in 3–5 bullets. Flag approval bottlenecks (lots of pending_approval but few moving to approved) and any sign that disputed bills are accumulating. Do NOT propose specific payments to release.',
        'context_fn'   => 'aiAgentContextTreasuryPayments',
    ],
    // Phase A.1 — Tax split into 4 honest sub-agents. The original `tax`
    // agent's narrow scope (tax-form mapping coverage) is renamed to
    // `tax_mapping` for truth-in-advertising. Three new agents cover the
    // domains the previous monolithic name implied: sales tax, payroll tax,
    // and partner distributions / income-tax-perspective decisioning.
    'tax_mapping' => [
        'label'        => 'AI Tax Mapping',
        'description'  => 'Reviews tax-form mapping coverage of your chart of accounts.',
        'feature_class'=> 'narrative',
        'feature_key'  => 'agent.tax_mapping.review',
        'kind'         => 'summary',
        'domain'       => ['tax'],
        'modules'      => ['accounting'],
        'system'       => 'You are a tax accountant reviewing the tenant\'s chart-of-accounts coverage for tax-form mapping. Given the count of unmapped accounts and recent posted activity in those accounts, summarize the year-end readiness in 3–5 bullets. Highlight any account types that are systematically unmapped. Do NOT propose specific form lines — that\'s a separate AI feature.',
        'context_fn'   => 'aiAgentContextTaxMapping',
    ],
    'sales_tax' => [
        'label'        => 'AI Sales Tax',
        'description'  => 'Reviews sales-tax filing readiness across the period.',
        'feature_class'=> 'narrative',
        'feature_key'  => 'agent.sales_tax.review',
        'kind'         => 'summary',
        'domain'       => ['tax'],
        'modules'      => ['accounting', 'billing'],
        'system'       => 'You are a sales-tax practitioner. Given qualitative signals about sales-tax-flagged invoices in the current quarter and the count of sales-tax accounts present in the chart of accounts, summarize the filing readiness in 3–5 bullets. Flag risk areas — multi-state activity, rate drift, missing nexus tracking. Do NOT propose specific dollar amounts or rates.',
        'context_fn'   => 'aiAgentContextSalesTax',
    ],
    'payroll_tax' => [
        'label'        => 'AI Payroll Tax',
        'description'  => 'Reviews payroll-tax accrual cadence and federal/state coverage.',
        'feature_class'=> 'narrative',
        'feature_key'  => 'agent.payroll_tax.review',
        'kind'         => 'summary',
        'domain'       => ['tax', 'payroll'],
        'modules'      => ['accounting'],
        'system'       => 'You are a payroll-tax practitioner reviewing the bookkeeping side. Given the count of payroll-tax-flagged accounts and recent posting cadence in those accounts, summarize accrual coverage and likely deposit-schedule risk in 3–5 bullets. Highlight if any expected liability accounts (federal income tax, FICA, FUTA, SUTA) appear unposted in the current period. Do NOT propose specific deposit amounts.',
        'context_fn'   => 'aiAgentContextPayrollTax',
    ],
    'partner_distributions' => [
        'label'        => 'AI Partner Distributions',
        'description'  => 'Reviews equity-distribution cadence from an income-tax-decision perspective.',
        'feature_class'=> 'narrative',
        'feature_key'  => 'agent.partner_distributions.review',
        'kind'         => 'narrative',
        'domain'       => ['tax', 'equity'],
        'modules'      => ['accounting'],
        'system'       => 'You are a tax accountant advising a partnership or S-corp owner. Given qualitative signals about distribution activity and the cadence of equity-account postings, narrate the income-tax decision picture: distribution pacing relative to expected K-1 / quarterly estimate timing, balance between owner draws and retained earnings, and red flags worth the operator\'s attention before year-end. Do NOT restate dollar amounts. 2 short paragraphs.',
        'context_fn'   => 'aiAgentContextPartnerDistributions',
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

/**
 * Phase A.5 — CFO Variance context. The variance voice doesn't need a new
 * SQL surface — it leans on the same composite signals as the CFO agent,
 * but the bucket-diff renderer in the digest pipeline does the actual
 * variance work for it. Reconciliation is added here so the variance
 * commentary can also call out reconciliation drift, which the strategic
 * CFO agent does not surface directly.
 */
function aiAgentContextCFOVariance(int $tenantId): array
{
    return [
        'books'          => aiAgentContextBookkeeper($tenantId),
        'treasury'       => aiAgentContextTreasury($tenantId),
        'reconciliation' => aiAgentContextReconciliation($tenantId),
    ];
}

/**
 * Phase A.5 — Treasury Payments context. Looks at the treasury_payments
 * queue + disputed AP bill count for queue-health commentary. Every
 * signal is emitted as a qualitative bucket so the agent can never
 * launder raw counts back into the narrative.
 */
function aiAgentContextTreasuryPayments(int $tenantId): array
{
    $pdo = getDB();
    $ctx = [];
    if ($pdo->query("SHOW TABLES LIKE 'treasury_payments'")->fetchColumn()) {
        foreach (['draft','pending_approval','approved','scheduled'] as $status) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM treasury_payments
                  WHERE tenant_id = :t AND status = :s"
            );
            $stmt->execute(['t' => $tenantId, 's' => $status]);
            $ctx[$status . '_payment_count_bucket'] = aiAgentBucketCount((int) $stmt->fetchColumn());
        }
    }
    if ($pdo->query("SHOW TABLES LIKE 'ap_bills'")->fetchColumn()) {
        $d = $pdo->prepare(
            "SELECT COUNT(*) FROM ap_bills
              WHERE tenant_id = :t AND status = 'disputed'"
        );
        $d->execute(['t' => $tenantId]);
        $ctx['disputed_bill_count_bucket'] = aiAgentBucketCount((int) $d->fetchColumn());
    }
    return $ctx;
}

function aiAgentContextTaxMapping(int $tenantId): array
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

function aiAgentContextSalesTax(int $tenantId): array
{
    $pdo = getDB();
    $ctx = [];
    // Sales-tax-flagged accounts from the chart of accounts. Heuristic — name
    // contains "sales tax" or account_subtype = 'sales_tax_payable'. Tenants
    // can refine via the future per-agent context-filter feature.
    if ($pdo->query("SHOW TABLES LIKE 'accounting_accounts'")->fetchColumn()) {
        $a = $pdo->prepare(
            "SELECT COUNT(*) FROM accounting_accounts
              WHERE tenant_id = :t
                AND (LOWER(account_name) LIKE '%sales tax%'
                     OR LOWER(account_name) LIKE '%use tax%')"
        );
        $a->execute(['t' => $tenantId]);
        $ctx['sales_tax_account_count_bucket'] = aiAgentBucketCount((int) $a->fetchColumn());
    }
    if ($pdo->query("SHOW TABLES LIKE 'billing_invoices'")->fetchColumn()) {
        $i = $pdo->prepare(
            "SELECT COUNT(*) FROM billing_invoices
              WHERE tenant_id = :t
                AND invoice_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                AND tax_total > 0"
        );
        try {
            $i->execute(['t' => $tenantId]);
            $ctx['recent_taxed_invoice_count_bucket'] = aiAgentBucketCount((int) $i->fetchColumn());
        } catch (\Throwable $e) {
            // Column may not exist on older billing schemas; degrade silently.
        }
    }
    return $ctx;
}

function aiAgentContextPayrollTax(int $tenantId): array
{
    $pdo = getDB();
    $ctx = [];
    if ($pdo->query("SHOW TABLES LIKE 'accounting_accounts'")->fetchColumn()) {
        $a = $pdo->prepare(
            "SELECT COUNT(*) FROM accounting_accounts
              WHERE tenant_id = :t
                AND (LOWER(account_name) LIKE '%payroll tax%'
                     OR LOWER(account_name) LIKE '%fica%'
                     OR LOWER(account_name) LIKE '%futa%'
                     OR LOWER(account_name) LIKE '%suta%'
                     OR LOWER(account_name) LIKE '%federal withholding%'
                     OR LOWER(account_name) LIKE '%state withholding%')"
        );
        $a->execute(['t' => $tenantId]);
        $ctx['payroll_tax_account_count_bucket'] = aiAgentBucketCount((int) $a->fetchColumn());
    }
    if ($pdo->query("SHOW TABLES LIKE 'accounting_journal_lines'")->fetchColumn()) {
        $p = $pdo->prepare(
            "SELECT COUNT(DISTINCT jl.account_id)
               FROM accounting_journal_lines jl
               JOIN accounting_accounts a       ON a.id = jl.account_id
               JOIN accounting_journal_entries je ON je.id = jl.journal_entry_id
              WHERE jl.tenant_id = :t
                AND je.status = 'posted'
                AND je.posting_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                AND (LOWER(a.account_name) LIKE '%payroll tax%'
                     OR LOWER(a.account_name) LIKE '%fica%'
                     OR LOWER(a.account_name) LIKE '%futa%')"
        );
        $p->execute(['t' => $tenantId]);
        $ctx['recently_posted_payroll_tax_account_count_bucket'] = aiAgentBucketCount((int) $p->fetchColumn());
    }
    return $ctx;
}

function aiAgentContextPartnerDistributions(int $tenantId): array
{
    $pdo = getDB();
    $ctx = [];
    if ($pdo->query("SHOW TABLES LIKE 'accounting_accounts'")->fetchColumn()) {
        // Equity / distribution / draw accounts.
        $a = $pdo->prepare(
            "SELECT COUNT(*) FROM accounting_accounts
              WHERE tenant_id = :t
                AND (account_type = 'equity'
                     OR LOWER(account_name) LIKE '%distribution%'
                     OR LOWER(account_name) LIKE '%draw%')"
        );
        $a->execute(['t' => $tenantId]);
        $ctx['equity_account_count_bucket'] = aiAgentBucketCount((int) $a->fetchColumn());
    }
    if ($pdo->query("SHOW TABLES LIKE 'accounting_journal_entries'")->fetchColumn()
        && $pdo->query("SHOW TABLES LIKE 'accounting_journal_lines'")->fetchColumn()) {
        $d = $pdo->prepare(
            "SELECT COUNT(*) FROM accounting_journal_entries je
              WHERE je.tenant_id = :t
                AND je.status = 'posted'
                AND je.posting_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                AND EXISTS (
                    SELECT 1 FROM accounting_journal_lines jl
                       JOIN accounting_accounts a ON a.id = jl.account_id
                     WHERE jl.journal_entry_id = je.id
                       AND (a.account_type = 'equity'
                            OR LOWER(a.account_name) LIKE '%distribution%'
                            OR LOWER(a.account_name) LIKE '%draw%')
                )"
        );
        $d->execute(['t' => $tenantId]);
        $ctx['recent_distribution_je_count_bucket'] = aiAgentBucketCount((int) $d->fetchColumn());
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

// =====================================================================
// Slice 2 — Per-agent mode (advisory | auto_log).
// =====================================================================
const AI_AGENT_MODES = ['advisory', 'auto_log'];

function aiAgentModeRead(int $tenantId, string $agentKey): string
{
    if (!isset(AI_AGENTS[$agentKey])) return 'advisory';
    $stmt = getDB()->prepare(
        'SELECT mode FROM ai_agent_settings
          WHERE tenant_id = :t AND agent_key = :k LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'k' => $agentKey]);
    $m = (string) ($stmt->fetchColumn() ?: 'advisory');
    return in_array($m, AI_AGENT_MODES, true) ? $m : 'advisory';
}

function aiAgentModeReadAll(int $tenantId): array
{
    $modes = [];
    foreach (array_keys(AI_AGENTS) as $k) $modes[$k] = 'advisory';
    $stmt = getDB()->prepare('SELECT agent_key, mode FROM ai_agent_settings WHERE tenant_id = :t');
    $stmt->execute(['t' => $tenantId]);
    while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $k = (string) $r['agent_key'];
        if (isset($modes[$k]) && in_array((string) $r['mode'], AI_AGENT_MODES, true)) {
            $modes[$k] = (string) $r['mode'];
        }
    }
    return $modes;
}

function aiAgentModeWrite(int $tenantId, string $agentKey, string $mode): void
{
    if (!isset(AI_AGENTS[$agentKey])) {
        throw new \InvalidArgumentException('Unknown agent: ' . $agentKey);
    }
    if (!in_array($mode, AI_AGENT_MODES, true)) {
        throw new \InvalidArgumentException('Invalid mode: ' . $mode);
    }
    getDB()->prepare(
        'INSERT INTO ai_agent_settings (tenant_id, agent_key, mode)
         VALUES (:t, :k, :m)
         ON DUPLICATE KEY UPDATE mode = VALUES(mode)'
    )->execute(['t' => $tenantId, 'k' => $agentKey, 'm' => $mode]);
}

/**
 * Run an agent honoring the tenant's per-agent mode. When mode=auto_log,
 * the resulting suggestion is auto-marked accepted in `ai_suggestions` so it
 * flows into the passive insights feed without blocking on human review.
 * The narrative itself is unchanged either way; auto_log only changes the
 * downstream review state.
 */
function aiAgentRunWithMode(int $tenantId, ?int $userId, string $agentKey): array
{
    $envelope = aiAgentRun($tenantId, $agentKey);
    $mode = aiAgentModeRead($tenantId, $agentKey);
    $envelope['mode'] = $mode;
    if ($mode === 'auto_log' && !empty($envelope['interaction_id'])) {
        try {
            getDB()->prepare(
                "UPDATE ai_suggestions
                    SET review_status = 'accepted',
                        reviewed_at   = NOW(),
                        reviewed_by   = :u
                  WHERE tenant_id = :t AND interaction_id = :iid"
            )->execute([
                'u'   => $userId,
                't'   => $tenantId,
                'iid' => $envelope['interaction_id'],
            ]);
            $envelope['auto_logged'] = true;
        } catch (\Throwable $e) {
            // Auto-log is best-effort; falling back to advisory display is
            // still correct — the narrative is intact.
            $envelope['auto_logged']       = false;
            $envelope['auto_log_error']    = $e->getMessage();
        }
    }
    return $envelope;
}

// =====================================================================
// Slice 3 — Run-all + digest email (on-demand and scheduled).
// =====================================================================

/** Run every agent in registry order. Returns map keyed by agent.
 *  Phase A.2 — when $onlyKeys is non-null, only those agents are run. */
function aiAgentRunAll(int $tenantId, ?int $userId, ?array $onlyKeys = null): array
{
    $results = [];
    $keys = array_keys(AI_AGENTS);
    if ($onlyKeys !== null) {
        $keys = array_values(array_filter($keys, fn ($k) => in_array($k, $onlyKeys, true)));
    }
    foreach ($keys as $key) {
        try {
            $results[$key] = ['ok' => true, 'envelope' => aiAgentRunWithMode($tenantId, $userId, $key)];
        } catch (\Throwable $e) {
            $results[$key] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    return $results;
}

/**
 * Phase A.4 — persist a per-agent context snapshot so the next digest can
 * compute "last week's bucket → this week's bucket" deltas. Best-effort —
 * a write failure must never block the digest send.
 */
function aiAgentContextSnapshotWrite(int $tenantId, string $agentKey, array $context): void
{
    if (!isset(AI_AGENTS[$agentKey])) return;
    try {
        getDB()->prepare(
            'INSERT INTO ai_agent_context_snapshots (tenant_id, agent_key, context_json)
             VALUES (:t, :k, :c)'
        )->execute([
            't' => $tenantId,
            'k' => $agentKey,
            'c' => json_encode($context, JSON_UNESCAPED_SLASHES),
        ]);
    } catch (\Throwable $e) {
        error_log('[ai_agents] snapshot write failed: ' . $e->getMessage());
    }
}

/**
 * Read the most recent snapshot strictly older than $cutoff (default 6
 * days ago to match the weekly cadence). Returns the decoded context
 * array or null.
 */
function aiAgentContextSnapshotPrior(int $tenantId, string $agentKey, ?string $cutoff = null): ?array
{
    $cutoff = $cutoff ?: date('Y-m-d H:i:s', strtotime('-6 days'));
    try {
        $stmt = getDB()->prepare(
            'SELECT context_json FROM ai_agent_context_snapshots
              WHERE tenant_id = :t AND agent_key = :k AND snapshot_at < :c
              ORDER BY snapshot_at DESC LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId, 'k' => $agentKey, 'c' => $cutoff]);
        $row = $stmt->fetchColumn();
        if (!$row) return null;
        $arr = json_decode((string) $row, true);
        return is_array($arr) ? $arr : null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Phase A.4 — flatten two qualitative-bucket context arrays into a list of
 * "{key}: {prior bucket} → {current bucket}" diff lines. Skips identical
 * values, recurses one level deep into nested arrays (the CFO context
 * stitches books + treasury), and falls back to scalar comparison.
 *
 * @return array<int,string>
 */
function aiAgentBucketDiff(array $prior, array $current, string $prefix = ''): array
{
    $lines = [];
    $keys  = array_unique(array_merge(array_keys($prior), array_keys($current)));
    foreach ($keys as $k) {
        $pv = $prior[$k]   ?? null;
        $cv = $current[$k] ?? null;
        $label = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($pv) || is_array($cv)) {
            $lines = array_merge(
                $lines,
                aiAgentBucketDiff(is_array($pv) ? $pv : [], is_array($cv) ? $cv : [], $label)
            );
            continue;
        }
        if ($pv === $cv) continue;
        $pStr = $pv === null ? '—' : (string) $pv;
        $cStr = $cv === null ? '—' : (string) $cv;
        $lines[] = $label . ': ' . $pStr . ' → ' . $cStr;
    }
    return $lines;
}

/** Map agent_key → in-app deep-link path. Used for one-tap magic-link CTAs. */
function aiAgentDeepLink(string $agentKey): string {
    return [
        'bookkeeper'           => '/modules/accounting',
        'reconciliation'       => '/modules/accounting/bank-rec',
        'treasury_analyst'     => '/modules/treasury',
        'cfo'                  => '/modules/reports/exec',
        'cfo_variance'         => '/modules/reports/exec',
        'treasury_payments'    => '/modules/treasury',
        'tax_mapping'          => '/modules/accounting/tax-mappings',
        'sales_tax'            => '/modules/accounting',
        'payroll_tax'          => '/modules/payroll',
        'partner_distributions'=> '/modules/treasury',
    ][$agentKey] ?? '/';
}

/**
 * Compute per-recipient "what's new for you" queue counts so the digest can
 * open with a personal nudge ("3 AP bills awaiting your approval, 2 timesheet
 * approvals pending") instead of a generic header. Best-effort — any query
 * failure returns a zeroed shape so the digest never breaks because of
 * counts.
 *
 * @return array{
 *   ap_approvals_pending: int,    // ap_bill_approvals rows where state=pending and you're the approver
 *   workflow_pending:     int,    // pending workflow_instances where you're current-step approver
 *   pending_total:        int,    // sum, used to short-circuit the banner
 *   deep_link:            string, // /workflow inbox path
 * }
 */
function aiAgentDigestRecipientCounts(int $tenantId, string $recipientEmail): array {
    $zero = [
        'ap_approvals_pending' => 0,
        'workflow_pending'     => 0,
        'pending_total'        => 0,
        'deep_link'            => '/workflow',
    ];
    $email = strtolower(trim($recipientEmail));
    if ($email === '') return $zero;

    try {
        $pdo = getDB();
        if (!$pdo) return $zero;

        // Resolve recipient → user_id (member of this tenant only).
        $st = $pdo->prepare(
            'SELECT u.id FROM users u
               JOIN user_tenants ut ON ut.user_id = u.id
              WHERE u.email = :e
                AND ut.tenant_id = :t
                AND ut.status = \'active\'
              LIMIT 1'
        );
        $st->execute(['e' => $email, 't' => $tenantId]);
        $row = $st->fetch();
        if (!$row) return $zero;
        $userId = (int) $row['id'];

        $apCount = 0;
        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*) c FROM ap_bill_approvals
                  WHERE tenant_id = :t AND approver_user_id = :u AND state = 'pending'"
            );
            $st->execute(['t' => $tenantId, 'u' => $userId]);
            $apCount = (int) ($st->fetch()['c'] ?? 0);
        } catch (\Throwable $_) { /* table may not exist on legacy tenants */ }

        $wfCount = 0;
        try {
            if (function_exists('workflowGetPendingForUser')) {
                $wfCount = count(workflowGetPendingForUser($tenantId, $userId));
            }
        } catch (\Throwable $_) { /* schema-tolerant */ }

        return [
            'ap_approvals_pending' => $apCount,
            'workflow_pending'     => $wfCount,
            'pending_total'        => $apCount + $wfCount,
            'deep_link'            => '/workflow',
        ];
    } catch (\Throwable $_) {
        return $zero;
    }
}

/** Build a tenant-safe HTML digest from a run-all result map.
 *
 *  Phase A.3 — accepts $intro override (sanitised on render).
 *  Phase A.4 — accepts $diffsByAgent (map of agent_key → array of diff
 *              line strings) so each section can show what's changed
 *              versus last week before the narrative.
 *  2026-02   — accepts $ctaContext = ['tenant_id' => int, 'recipient_email' => string]
 *              to mint per-recipient one-tap magic-link CTAs (zero-click
 *              compliance: clicking the email both authenticates AND
 *              deep-links to the relevant agent's module).
 */
function aiAgentBuildDigestHtml(array $runAllResults, ?string $intro = null, array $diffsByAgent = [], ?array $ctaContext = null): array
{
    // Lazy-load magic_link so legacy tenants without the migration don't
    // explode here — we just skip the CTAs.
    $canMintLinks = false;
    if ($ctaContext && !empty($ctaContext['tenant_id']) && !empty($ctaContext['recipient_email'])) {
        $fn = __DIR__ . '/magic_link.php';
        if (is_file($fn)) {
            require_once $fn;
            $canMintLinks = function_exists('magicLinkIssue');
        }
    }

    $mintCta = function (string $path) use (&$canMintLinks, $ctaContext): ?string {
        if (!$canMintLinks) return null;
        try {
            // 3-day TTL — recipients often read digest emails 1-3 days late.
            $issued = magicLinkIssue(
                (string) $ctaContext['recipient_email'],
                (int) $ctaContext['tenant_id'],
                $path,
                $_SERVER['REMOTE_ADDR'] ?? null,
                'CoreFlux/digest-cta',
                /* ttlMinutes */ 60 * 24 * 3
            );
            return magicLinkUrl($issued['raw_token']);
        } catch (\Throwable $e) {
            error_log('[ai_agents] CTA mint failed: ' . $e->getMessage());
            return null;
        }
    };

    $sections = [];
    $textParts = [];
    foreach ($runAllResults as $key => $row) {
        if (!isset(AI_AGENTS[$key])) continue;
        $agent = AI_AGENTS[$key];
        $title = htmlspecialchars($agent['label'], ENT_QUOTES, 'UTF-8');
        $diffHtml = '';
        $diffText = '';
        $diffs = $diffsByAgent[$key] ?? [];
        if ($diffs) {
            $items = '';
            $textBullets = '';
            foreach ($diffs as $line) {
                $safe = htmlspecialchars((string) $line, ENT_QUOTES, 'UTF-8');
                $items       .= "<li style=\"color:#475569;\">{$safe}</li>";
                $textBullets .= "  - {$line}\n";
            }
            $diffHtml = "<div style=\"margin:6px 0 8px;padding:8px 12px;background:#f5f3ff;border-left:3px solid #7c3aed;border-radius:4px;\">"
                      . "<div style=\"font-size:11px;color:#5b21b6;text-transform:uppercase;letter-spacing:0.04em;font-weight:600;margin-bottom:4px;\">Changed since last week</div>"
                      . "<ul style=\"margin:0;padding-left:18px;font-size:12px;font-family:system-ui;\">{$items}</ul>"
                      . "</div>";
            $diffText = "Changed since last week:\n{$textBullets}\n";
        }
        if (!empty($row['ok']) && !empty($row['envelope']['content'])) {
            $body = (string) $row['envelope']['content'];
            $ctaUrl  = $mintCta(aiAgentDeepLink($key));
            $ctaHtml = '';
            $ctaText = '';
            if ($ctaUrl) {
                $safeUrl = htmlspecialchars($ctaUrl, ENT_QUOTES, 'UTF-8');
                $ctaHtml = "<div style=\"margin-top:10px\"><a href=\"{$safeUrl}\" "
                         . "style=\"display:inline-block;background:#7c3aed;color:#fff;text-decoration:none;"
                         . "padding:8px 14px;border-radius:6px;font-family:system-ui;font-size:12px;font-weight:600\">"
                         . "Open " . $title . " in CoreFlux →</a></div>";
                $ctaText = "\nOpen this view: {$ctaUrl}";
            }
            $sections[] = "<h3 style=\"margin:24px 0 6px;font-family:system-ui;\">{$title}</h3>"
                       . $diffHtml
                       . "<div style=\"font-family:system-ui;line-height:1.55;color:#1e293b;font-size:14px;\">"
                       . nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'))
                       . "</div>"
                       . $ctaHtml;
            $textParts[] = "## {$agent['label']}\n\n{$diffText}{$body}{$ctaText}\n";
        } else {
            $err = htmlspecialchars((string) ($row['error'] ?? 'unknown'), ENT_QUOTES, 'UTF-8');
            $sections[] = "<h3 style=\"margin:24px 0 6px;font-family:system-ui;color:#94a3b8;\">{$title}</h3>"
                       . "<p style=\"color:#94a3b8;font-family:system-ui;font-size:13px;\">Skipped: {$err}</p>";
            $textParts[] = "## {$agent['label']}\n\n(skipped: {$err})\n";
        }
    }
    $defaultIntro = 'Five advisory perspectives on your books, treasury, and tax position.';
    $introHtml = htmlspecialchars($intro !== null && $intro !== '' ? $intro : $defaultIntro, ENT_QUOTES, 'UTF-8');

    // Master one-tap CTA at the top.
    $masterCta = '';
    $masterCtaText = '';
    $masterUrl = $mintCta('/dashboard');
    if ($masterUrl) {
        $safe = htmlspecialchars($masterUrl, ENT_QUOTES, 'UTF-8');
        $masterCta = "<div style=\"margin:8px 0 24px\"><a href=\"{$safe}\" "
                   . "style=\"display:inline-block;background:#0ea5e9;color:#fff;text-decoration:none;"
                   . "padding:10px 18px;border-radius:8px;font-family:system-ui;font-size:13px;font-weight:600\">"
                   . "Open CoreFlux Dashboard →</a></div>";
        $masterCtaText = "\nOpen the dashboard: {$masterUrl}\n";
    }

    // Personalized "what's new for you" nudge — hides itself when the
    // recipient has nothing pending, so the digest doesn't shout at people
    // with empty queues.
    $personalNudge = '';
    $personalText  = '';
    if ($ctaContext && !empty($ctaContext['tenant_id']) && !empty($ctaContext['recipient_email'])) {
        $counts = aiAgentDigestRecipientCounts(
            (int) $ctaContext['tenant_id'],
            (string) $ctaContext['recipient_email']
        );
        if ($counts['pending_total'] > 0) {
            $parts = [];
            if ($counts['ap_approvals_pending'] > 0) {
                $n = (int) $counts['ap_approvals_pending'];
                $parts[] = "<strong>{$n}</strong> AP bill" . ($n === 1 ? '' : 's') . " awaiting your approval";
            }
            if ($counts['workflow_pending'] > 0) {
                $n = (int) $counts['workflow_pending'];
                $parts[] = "<strong>{$n}</strong> workflow task" . ($n === 1 ? '' : 's') . " in your inbox";
            }
            $inboxUrl = $mintCta($counts['deep_link']);
            $linkBit = $inboxUrl
                ? ' &nbsp;<a href="' . htmlspecialchars($inboxUrl, ENT_QUOTES, 'UTF-8') . '"'
                  . ' style="color:#92400e;font-weight:600;text-decoration:underline">Review now →</a>'
                : '';
            $personalNudge = '<div style="margin:0 0 20px;padding:12px 16px;background:#fef3c7;'
                           . 'border-left:4px solid #f59e0b;border-radius:6px;'
                           . 'font-family:system-ui;font-size:13px;color:#78350f">'
                           . '<strong style="color:#92400e">Pending for you:</strong> '
                           . implode(' &middot; ', $parts) . $linkBit
                           . '</div>';
            $personalText = "Pending for you: " . strip_tags(implode(' · ', $parts))
                          . ($inboxUrl ? "\nReview now: {$inboxUrl}" : '') . "\n\n";
        }
    }

    $html = "<div style=\"max-width:640px;margin:auto;padding:20px;\">"
          . "<h2 style=\"font-family:system-ui;color:#5b21b6;margin:0 0 4px;\">Your weekly AI Agent digest</h2>"
          . "<p style=\"font-family:system-ui;color:#64748b;font-size:13px;margin:0 0 16px;\">" . nl2br($introHtml) . "</p>"
          . $personalNudge
          . $masterCta
          . aiAgentPwpReleasedBlurbHtml($ctaContext['tenant_id'] ?? null)
          . implode('', $sections)
          . "<hr style=\"margin:24px 0;border:0;border-top:1px solid #e2e8f0;\" />"
          . "<p style=\"font-family:system-ui;color:#94a3b8;font-size:11px;\">Generated by CoreFlux AI Agents. Reply to this email to reach your tenant administrator.<br>"
          . "Sign-in links are personal, single-use, and expire in 3 days.</p>"
          . "</div>";
    $textOut = $personalText . $masterCtaText . aiAgentPwpReleasedBlurbText($ctaContext['tenant_id'] ?? null) . implode("\n---\n", $textParts);
    return ['html' => $html, 'text' => $textOut];
}

/**
 * Roll up the last 7 days of `ap.bill.pwp.released` audit events into a
 * single "Last week we released $X across N PWP bills because invoices
 * X, Y cleared" blurb. Returns '' (empty) when nothing was released so
 * the digest doesn't shout at quiet tenants.
 */
function aiAgentPwpReleasedBlurb(?int $tenantId): ?array {
    if (!$tenantId) return null;
    try {
        $pdo = getDB();
        if (!$pdo) return null;
        $stmt = $pdo->prepare(
            "SELECT meta_json
               FROM audit_log
              WHERE tenant_id = :t
                AND event = 'ap.bill.pwp.released'
                AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              ORDER BY created_at DESC LIMIT 200"
        );
        $stmt->execute(['t' => $tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) {
        return null;
    }
    if (empty($rows)) return null;

    $arInvoices = [];
    $billIds    = [];
    foreach ($rows as $r) {
        $m = json_decode((string) ($r['meta_json'] ?? ''), true) ?: [];
        if (!empty($m['ar_invoice_id'])) $arInvoices[(int) $m['ar_invoice_id']] = true;
        if (!empty($m['bill_id']))       $billIds[(int) $m['bill_id']] = true;
    }
    if (empty($billIds)) return null;

    // Sum the released amounts off the actual bills.
    try {
        $placeholders = [];
        $params = ['t' => $tenantId];
        foreach (array_keys($billIds) as $i => $bid) {
            $k = 'b' . $i;
            $placeholders[] = ':' . $k;
            $params[$k] = $bid;
        }
        $sumStmt = $pdo->prepare(
            'SELECT SUM(total) AS s FROM ap_bills
              WHERE tenant_id = :t AND id IN (' . implode(',', $placeholders) . ')'
        );
        $sumStmt->execute($params);
        $totalAmt = (float) ($sumStmt->fetchColumn() ?: 0);
    } catch (\Throwable $_) {
        $totalAmt = 0.0;
    }

    // Pull invoice numbers for at most 3 — anything more becomes "…and N others".
    $invNumbers = [];
    if (!empty($arInvoices)) {
        try {
            $iph = []; $iparams = ['t' => $tenantId];
            foreach (array_keys($arInvoices) as $i => $iid) {
                $k = 'i' . $i;
                $iph[] = ':' . $k;
                $iparams[$k] = $iid;
            }
            $invStmt = $pdo->prepare(
                'SELECT invoice_number FROM billing_invoices
                  WHERE tenant_id = :t AND id IN (' . implode(',', $iph) . ')
                  ORDER BY issue_date DESC LIMIT 10'
            );
            $invStmt->execute($iparams);
            $invNumbers = array_map('strval', $invStmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        } catch (\Throwable $_) { /* schema absence — leave invoice list empty */ }
    }
    return [
        'bill_count'      => count($billIds),
        'ar_invoice_count'=> count($arInvoices),
        'total_amount'    => round($totalAmt, 2),
        'invoice_numbers' => $invNumbers,
    ];
}

function aiAgentPwpReleasedBlurbHtml(?int $tenantId): string {
    $d = aiAgentPwpReleasedBlurb($tenantId);
    if (!$d) return '';
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $invList = $d['invoice_numbers'] ? array_slice($d['invoice_numbers'], 0, 3) : [];
    $tail    = count($d['invoice_numbers']) > 3 ? ' and ' . (count($d['invoice_numbers']) - 3) . ' other(s)' : '';
    $invStr  = $invList ? ' because invoice(s) ' . $h(implode(', ', $invList)) . $h($tail) . ' cleared' : '';
    $amt     = '$' . number_format($d['total_amount'], 2);
    return '<div style="margin:0 0 20px;padding:12px 16px;background:#ecfdf5;'
         . 'border-left:4px solid #10b981;border-radius:6px;'
         . 'font-family:system-ui;font-size:13px;color:#065f46">'
         . '<strong>Last week:</strong> released ' . $amt
         . ' across <strong>' . $d['bill_count'] . '</strong> Pay-When-Paid contractor bill'
         . ($d['bill_count'] === 1 ? '' : 's')
         . $invStr . '.'
         . '</div>';
}

function aiAgentPwpReleasedBlurbText(?int $tenantId): string {
    $d = aiAgentPwpReleasedBlurb($tenantId);
    if (!$d) return '';
    $invList = $d['invoice_numbers'] ? array_slice($d['invoice_numbers'], 0, 3) : [];
    $tail    = count($d['invoice_numbers']) > 3 ? ' and ' . (count($d['invoice_numbers']) - 3) . ' other(s)' : '';
    $invStr  = $invList ? ' because invoices ' . implode(', ', $invList) . $tail . ' cleared' : '';
    $amt     = '$' . number_format($d['total_amount'], 2);
    return "Last week: released {$amt} across {$d['bill_count']} Pay-When-Paid contractor bill"
         . ($d['bill_count'] === 1 ? '' : 's') . $invStr . ".\n\n";
}

/** Read tenant digest settings; defaults applied. */
function aiAgentDigestRead(int $tenantId): array
{
    $stmt = getDB()->prepare(
        'SELECT enabled, recipients, send_dow, last_sent_at, last_send_error,
                included_agents, subject_override, intro_override
           FROM ai_agent_digest_settings WHERE tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) {
        return [
            'enabled' => false, 'recipients' => null, 'send_dow' => 1,
            'last_sent_at' => null, 'last_send_error' => null,
            'included_agents'  => null,
            'subject_override' => null,
            'intro_override'   => null,
        ];
    }
    $included = null;
    if (!empty($row['included_agents'])) {
        $decoded = json_decode((string) $row['included_agents'], true);
        if (is_array($decoded)) {
            // Filter to known agent keys only — defends against stale rows
            // when an agent is removed from the registry.
            $included = array_values(array_filter($decoded, fn ($k) => isset(AI_AGENTS[(string) $k])));
            if (!$included) $included = null;
        }
    }
    return [
        'enabled'          => (bool) (int) $row['enabled'],
        'recipients'       => $row['recipients'] ?: null,
        'send_dow'         => (int) $row['send_dow'],
        'last_sent_at'     => $row['last_sent_at'],
        'last_send_error'  => $row['last_send_error'],
        'included_agents'  => $included,
        'subject_override' => $row['subject_override'] ?: null,
        'intro_override'   => $row['intro_override'] ?: null,
    ];
}

function aiAgentDigestWrite(int $tenantId, array $patch): array
{
    $current = aiAgentDigestRead($tenantId);
    $enabled    = (bool) ($patch['enabled'] ?? $current['enabled']);
    $recipients = isset($patch['recipients']) ? (string) $patch['recipients'] : ($current['recipients'] ?? '');
    $recipients = trim($recipients);
    if ($recipients !== '') {
        // Validate every comma-separated email.
        foreach (preg_split('/\s*,\s*/', $recipients) as $email) {
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Invalid recipient: ' . $email);
            }
        }
    }
    $sendDow = (int) ($patch['send_dow'] ?? $current['send_dow']);
    if ($sendDow < 1 || $sendDow > 7) {
        throw new \InvalidArgumentException('send_dow must be 1..7 (Mon..Sun)');
    }

    // Phase A.2 — included_agents whitelist. Empty/missing → null = "all".
    $includedJson = null;
    if (array_key_exists('included_agents', $patch)) {
        $raw = $patch['included_agents'];
        if ($raw === null || $raw === '' || $raw === []) {
            $includedJson = null;
        } else {
            if (!is_array($raw)) throw new \InvalidArgumentException('included_agents must be an array');
            $clean = [];
            foreach ($raw as $k) {
                $k = (string) $k;
                if (!isset(AI_AGENTS[$k])) {
                    throw new \InvalidArgumentException('Unknown agent in included_agents: ' . $k);
                }
                $clean[$k] = true; // dedupe
            }
            $clean = array_keys($clean);
            $includedJson = $clean ? json_encode($clean, JSON_UNESCAPED_SLASHES) : null;
        }
    } else {
        $includedJson = $current['included_agents'] ? json_encode($current['included_agents']) : null;
    }

    // Phase A.3 — subject + intro overrides. Trimmed, length-capped, and
    // header-injection-guarded for the subject line.
    $subjectOverride = array_key_exists('subject_override', $patch)
        ? trim((string) $patch['subject_override']) : ($current['subject_override'] ?? '');
    if ($subjectOverride !== '') {
        if (strlen($subjectOverride) > 200) {
            throw new \InvalidArgumentException('subject_override max 200 chars');
        }
        if (preg_match("/[\r\n]/", $subjectOverride)) {
            throw new \InvalidArgumentException('subject_override cannot contain line breaks');
        }
    }
    $introOverride = array_key_exists('intro_override', $patch)
        ? trim((string) $patch['intro_override']) : ($current['intro_override'] ?? '');
    if ($introOverride !== '' && strlen($introOverride) > 1000) {
        throw new \InvalidArgumentException('intro_override max 1000 chars');
    }

    getDB()->prepare(
        'INSERT INTO ai_agent_digest_settings
             (tenant_id, enabled, recipients, send_dow, included_agents, subject_override, intro_override)
         VALUES (:t, :e, :r, :d, :inc, :sub, :int)
         ON DUPLICATE KEY UPDATE
             enabled          = VALUES(enabled),
             recipients       = VALUES(recipients),
             send_dow         = VALUES(send_dow),
             included_agents  = VALUES(included_agents),
             subject_override = VALUES(subject_override),
             intro_override   = VALUES(intro_override)'
    )->execute([
        't'   => $tenantId,
        'e'   => $enabled ? 1 : 0,
        'r'   => $recipients !== '' ? $recipients : null,
        'd'   => $sendDow,
        'inc' => $includedJson,
        'sub' => $subjectOverride !== '' ? $subjectOverride : null,
        'int' => $introOverride   !== '' ? $introOverride   : null,
    ]);
    return aiAgentDigestRead($tenantId);
}

function aiAgentDigestRecipients(int $tenantId): array
{
    $cfg = aiAgentDigestRead($tenantId);
    if (!empty($cfg['recipients'])) {
        $list = array_filter(array_map('trim', preg_split('/\s*,\s*/', (string) $cfg['recipients'])));
        if ($list) return array_values($list);
    }
    // Fallback: tenant master_admin email.
    $stmt = getDB()->prepare(
        "SELECT u.email
           FROM users u
           JOIN user_tenants ut ON ut.user_id = u.id
          WHERE ut.tenant_id = :t AND ut.role = 'master_admin'
          ORDER BY u.id ASC LIMIT 1"
    );
    $stmt->execute(['t' => $tenantId]);
    $email = (string) ($stmt->fetchColumn() ?: '');
    return $email ? [$email] : [];
}

/**
 * Send a digest email RIGHT NOW. Used by both the on-demand button and the
 * weekly cron. Updates ai_agent_digest_settings.last_sent_at on success.
 *
 * Phase A.2 — when `included_agents` is set on the digest config, only
 *             those agents run.
 * Phase A.3 — subject + intro can be tenant-overridden.
 * Phase A.4 — each agent's prior-week context snapshot is loaded BEFORE
 *             the run; after the run the new context is snapshotted and
 *             the diff is rendered into each section.
 */
function aiAgentDigestSend(int $tenantId, ?int $userId): array
{
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/tenant_mail.php';

    $cfg = aiAgentDigestRead($tenantId);
    $recipients = aiAgentDigestRecipients($tenantId);
    if (!$recipients) {
        throw new \RuntimeException('No digest recipients configured and no master_admin email found.');
    }

    $onlyKeys = $cfg['included_agents']; // null → run all
    $keysToRun = $onlyKeys ?? array_keys(AI_AGENTS);

    // Phase A.4 — read the prior-week snapshot for each agent BEFORE we
    // overwrite it with this week's context.
    $priorContexts = [];
    foreach ($keysToRun as $k) {
        $priorContexts[$k] = aiAgentContextSnapshotPrior($tenantId, $k) ?? [];
    }

    $results = aiAgentRunAll($tenantId, $userId, $onlyKeys);

    // Snapshot this week's context AFTER the run + compute diffs for the
    // template. Snapshot writes are best-effort and never throw.
    $diffs = [];
    foreach ($keysToRun as $k) {
        if (!isset(AI_AGENTS[$k])) continue;
        try {
            $contextFn = AI_AGENTS[$k]['context_fn'];
            if (function_exists($contextFn)) {
                $current = $contextFn($tenantId);
                aiAgentContextSnapshotWrite($tenantId, $k, $current);
                if (!empty($priorContexts[$k])) {
                    $diffs[$k] = aiAgentBucketDiff($priorContexts[$k], $current);
                }
            }
        } catch (\Throwable $e) {
            // Diffing is supplementary — never block the send.
            error_log('[ai_agents] context diff failed for ' . $k . ': ' . $e->getMessage());
        }
    }

    // Render + send PER recipient so each gets their own single-use,
    // identity-bound magic-link CTAs. The agent results + diffs are reused
    // (they're tenant-scoped, not per-user).
    $sender   = cf_tenant_mail_sender($tenantId, 'ai_agents');
    $messageIds = [];
    $sendErrors = [];
    foreach ($recipients as $email) {
        $rendered = aiAgentBuildDigestHtml(
            $results,
            $cfg['intro_override'],
            $diffs,
            ['tenant_id' => $tenantId, 'recipient_email' => $email]
        );
        try {
            $resp = sendEmail([
                'to'         => [$email],
                'subject'    => $cfg['subject_override'] ?: 'Your weekly AI Agent digest',
                'body_html'  => $rendered['html'],
                'body_text'  => $rendered['text'],
                'from_email' => $sender['from']      ?? null,
                'from_name'  => $sender['from_name'] ?? null,
                'reply_to'   => $sender['reply_to']  ?? null,
            ]);
            $messageIds[$email] = $resp['message_id'] ?? null;
        } catch (\Throwable $e) {
            $sendErrors[$email] = $e->getMessage();
            error_log('[ai_agents] send to ' . $email . ' failed: ' . $e->getMessage());
        }
    }
    $subject = $cfg['subject_override'] ?: 'Your weekly AI Agent digest';

    getDB()->prepare(
        'INSERT INTO ai_agent_digest_settings (tenant_id, last_sent_at, last_send_error)
         VALUES (:t, NOW(), :err)
         ON DUPLICATE KEY UPDATE last_sent_at = NOW(), last_send_error = VALUES(last_send_error)'
    )->execute([
        't'   => $tenantId,
        'err' => $sendErrors ? substr(json_encode($sendErrors), 0, 500) : null,
    ]);

    return [
        'ok'          => count($sendErrors) === 0,
        'recipients'  => $recipients,
        'subject'     => $subject,
        'message_ids' => $messageIds,
        'message_id'  => $messageIds ? array_values($messageIds)[0] : null, // back-compat
        'send_errors' => $sendErrors ?: null,
        'agent_results' => array_map(static function ($r) {
            return ['ok' => (bool) ($r['ok'] ?? false)];
        }, $results),
    ];
}
