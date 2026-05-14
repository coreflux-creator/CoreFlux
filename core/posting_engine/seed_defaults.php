<?php
/**
 * Default posting-rule + journal-template seed pack (Sprint 7c.1, spec §13).
 *
 * Idempotent. Populates a tenant with the most common Treasury event
 * mappings so they can hit "Execute" on a payment / record a bank fee /
 * receive interest and have it post correctly without authoring templates
 * by hand.
 *
 * Pre-requisite: accountingSeedSystemAccounts(tenantId) must have been
 * called first — these templates target system accounts by name.
 *
 * What ships:
 *
 *   1. treasury.bank_fee.detected
 *      Template "Bank fee — default":
 *        Dr  system:Bank Fees Expense           payload.amount
 *        Cr  payload.bank_gl_account_id         payload.amount
 *
 *   2. treasury.interest.received
 *      Template "Interest received — default":
 *        Dr  payload.bank_gl_account_id         payload.amount
 *        Cr  system:Interest Income             payload.amount
 *
 *   3. treasury.payment.executed
 *      Template "Payment executed — default":
 *        Dr  payload.counterparty_account_id    payload.amount
 *        Cr  payload.bank_gl_account_id         payload.amount
 *
 *   4. treasury.transfer.completed (internal — single JE)
 *      Template "Internal transfer — default":
 *        Dr  payload.destination_bank_gl_account_id   payload.amount
 *        Cr  payload.source_bank_gl_account_id        payload.amount
 *
 *   5. treasury.intercompany.transfer.completed (source side only;
 *      destination side handled when entity B's books are produced —
 *      Sprint 7e finishes the mirror)
 *      Template "Intercompany transfer (source) — default":
 *        Dr  system:Intercompany Receivable     payload.amount
 *        Cr  payload.source_bank_gl_account_id  payload.amount
 *
 *   6. treasury.bank_transaction.matched (sandbox + manual feed cat)
 *      Template "Bank tx matched — Uncategorized":
 *        Dr  system:Uncategorized Expense       payload.amount
 *        Cr  payload.bank_gl_account_id         payload.amount
 *      (Tenants can clone this and swap accounts during onboarding.)
 */
declare(strict_types=1);

require_once __DIR__ . '/../accounting/system_accounts.php';

const POSTING_RULES_DEFAULT_PACK = [
    [
        'event_type'  => 'treasury.bank_fee.detected',
        'rule_name'   => 'Bank fee — default',
        'template'    => [
            'name'           => 'Bank fee — default',
            'memo_template'  => 'Bank fee on {payload.bank_account_name}',
            'lines' => [
                ['line_no' => 1, 'account_selector' => 'system:Bank Fees Expense', 'debit_formula'  => 'payload.amount', 'credit_formula' => '0', 'description_template' => 'Bank fee'],
                ['line_no' => 2, 'account_selector' => 'payload.bank_gl_account_id', 'debit_formula' => '0', 'credit_formula' => 'payload.amount', 'description_template' => 'Bank fee'],
            ],
        ],
    ],
    [
        'event_type'  => 'treasury.interest.received',
        'rule_name'   => 'Interest received — default',
        'template'    => [
            'name'           => 'Interest received — default',
            'memo_template'  => 'Interest received on {payload.bank_account_name}',
            'lines' => [
                ['line_no' => 1, 'account_selector' => 'payload.bank_gl_account_id', 'debit_formula'  => 'payload.amount', 'credit_formula' => '0', 'description_template' => 'Interest deposit'],
                ['line_no' => 2, 'account_selector' => 'system:Interest Income',     'debit_formula' => '0', 'credit_formula' => 'payload.amount', 'description_template' => 'Interest income'],
            ],
        ],
    ],
    [
        'event_type'  => 'treasury.payment.executed',
        'rule_name'   => 'Payment executed — default',
        'template'    => [
            'name'           => 'Payment executed — default',
            'memo_template'  => 'Payment {payload.payment_number} to {payload.payee_name}',
            'lines' => [
                ['line_no' => 1, 'account_selector' => 'payload.counterparty_account_id', 'debit_formula'  => 'payload.amount', 'credit_formula' => '0', 'description_template' => 'Settle {payload.payee_name}'],
                ['line_no' => 2, 'account_selector' => 'payload.bank_gl_account_id',      'debit_formula' => '0', 'credit_formula' => 'payload.amount', 'description_template' => 'Bank disbursement'],
            ],
        ],
    ],
    [
        'event_type'  => 'treasury.transfer.completed',
        'rule_name'   => 'Internal transfer — default',
        'template'    => [
            'name'           => 'Internal transfer — default',
            'memo_template'  => 'Internal transfer {payload.transfer_number}',
            'lines' => [
                ['line_no' => 1, 'account_selector' => 'payload.destination_bank_gl_account_id', 'debit_formula'  => 'payload.amount', 'credit_formula' => '0', 'description_template' => 'Transfer in'],
                ['line_no' => 2, 'account_selector' => 'payload.source_bank_gl_account_id',      'debit_formula' => '0', 'credit_formula' => 'payload.amount', 'description_template' => 'Transfer out'],
            ],
        ],
    ],
    [
        'event_type'  => 'treasury.intercompany.transfer.completed',
        'rule_name'   => 'Intercompany transfer (source side) — default',
        'template'    => [
            'name'           => 'Intercompany transfer (source) — default',
            'memo_template'  => 'Intercompany transfer {payload.transfer_number} (source side)',
            'lines' => [
                ['line_no' => 1, 'account_selector' => 'system:Intercompany Receivable',    'debit_formula'  => 'payload.amount', 'credit_formula' => '0', 'description_template' => 'Receivable from entity {payload.destination_entity_id}'],
                ['line_no' => 2, 'account_selector' => 'payload.source_bank_gl_account_id', 'debit_formula' => '0', 'credit_formula' => 'payload.amount', 'description_template' => 'Cash out (intercompany)'],
            ],
        ],
    ],
    [
        'event_type'  => 'treasury.bank_transaction.matched',
        'rule_name'   => 'Bank tx matched — uncategorized fallback',
        'template'    => [
            'name'           => 'Bank tx matched — uncategorized fallback',
            'memo_template'  => 'Bank transaction (review for proper category)',
            'lines' => [
                ['line_no' => 1, 'account_selector' => 'system:Uncategorized Expense', 'debit_formula'  => 'payload.amount', 'credit_formula' => '0', 'description_template' => 'Pending categorization'],
                ['line_no' => 2, 'account_selector' => 'payload.bank_gl_account_id',   'debit_formula' => '0', 'credit_formula' => 'payload.amount', 'description_template' => 'Bank transaction'],
            ],
        ],
    ],
    // ── Phase 2a — Treasury feed categorize/split passthrough ──
    // Emitted by /modules/treasury/api/account_transactions.php whenever a
    // user explicitly categorizes (single account) or splits (multi-account)
    // a bank statement line. The payload carries the fully rendered, balanced
    // JE lines so the engine just persists them — same pattern as
    // ap.bill.approved + billing.invoice.sent.
    [
        'event_type'  => 'treasury.bank_transaction.categorized',
        'rule_name'   => 'Bank tx categorized — passthrough',
        'template'    => [
            'name'           => 'Bank tx categorized — passthrough',
            'memo_template'  => '{payload.memo}',
            'line_source'    => 'payload',
        ],
    ],
    // ── Sprint 7e — AP / Billing module event-layer migration ──
    [
        'event_type'  => 'ap.bill.approved',
        'rule_name'   => 'AP bill approved — passthrough',
        'template'    => [
            'name'           => 'AP bill approved — passthrough',
            'memo_template'  => 'AP Bill {payload.internal_ref} / {payload.vendor_name}',
            'line_source'    => 'payload',
        ],
    ],
    [
        'event_type'  => 'billing.invoice.sent',
        'rule_name'   => 'AR invoice sent — passthrough',
        'template'    => [
            'name'           => 'AR invoice sent — passthrough',
            'memo_template'  => 'Invoice {payload.invoice_number} / {payload.client_name}',
            'line_source'    => 'payload',
        ],
    ],
    [
        'event_type'  => 'ap.payment.cleared',
        'rule_name'   => 'AP payment cleared — default',
        'template'    => [
            'name'           => 'AP payment cleared — default',
            'memo_template'  => 'AP payment {payload.payment_number} to {payload.vendor_name}',
            'lines' => [
                ['line_no' => 1, 'account_selector' => 'system:Accounts Payable',   'debit_formula'  => 'payload.amount', 'credit_formula' => '0', 'description_template' => 'Pay {payload.vendor_name}'],
                ['line_no' => 2, 'account_selector' => 'payload.bank_gl_account_id','debit_formula' => '0', 'credit_formula' => 'payload.amount', 'description_template' => 'Bank disbursement'],
            ],
        ],
    ],
    [
        'event_type'  => 'billing.payment.received',
        'rule_name'   => 'AR payment received — default',
        'template'    => [
            'name'           => 'AR payment received — default',
            'memo_template'  => 'Payment received {payload.payment_number} from {payload.client_name}',
            'lines' => [
                ['line_no' => 1, 'account_selector' => 'payload.bank_gl_account_id', 'debit_formula'  => 'payload.amount', 'credit_formula' => '0', 'description_template' => 'Bank receipt'],
                ['line_no' => 2, 'account_selector' => 'system:Accounts Receivable', 'debit_formula' => '0', 'credit_formula' => 'payload.amount', 'description_template' => 'Collect from {payload.client_name}'],
            ],
        ],
    ],
];

/**
 * Idempotently seed the default posting-rule + journal-template pack.
 * Returns counts of what was inserted vs already present.
 *
 * Strategy:
 *   - For each pack entry:
 *     1. Find OR create the journal_template by (tenant_id, name).
 *     2. If created, insert its lines.
 *     3. Find OR create the posting_rule by (tenant_id, event_type, name).
 *
 * Existing rows are NEVER overwritten — once a tenant has customised a
 * rule or template we leave it alone.
 */
function postingRulesSeedDefaults(int $tenantId): array {
    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('No DB');

    $rulesInserted = 0;
    $templatesInserted = 0;

    $findTpl = $pdo->prepare('SELECT id FROM accounting_journal_templates WHERE tenant_id = :t AND name = :n LIMIT 1');
    $insTpl = $pdo->prepare(
        'INSERT INTO accounting_journal_templates (tenant_id, name, memo_template, currency_source, line_source)
         VALUES (:t, :n, :m, "payload", :ls)'
    );
    $insLine = $pdo->prepare(
        'INSERT INTO accounting_journal_template_lines
            (tenant_id, journal_template_id, line_no, account_selector,
             debit_formula, credit_formula, description_template)
         VALUES (:t, :tpl, :ln, :sel, :df, :cf, :desc)'
    );
    $findRule = $pdo->prepare(
        'SELECT id FROM accounting_posting_rules
          WHERE tenant_id = :t AND event_type = :et AND name = :n LIMIT 1'
    );
    $insRule = $pdo->prepare(
        'INSERT INTO accounting_posting_rules
            (tenant_id, name, event_type, journal_template_id, priority, status, description)
         VALUES (:t, :n, :et, :tpl, 100, "active", :desc)'
    );

    foreach (POSTING_RULES_DEFAULT_PACK as $entry) {
        $tplName    = $entry['template']['name'];
        $lineSource = (string) ($entry['template']['line_source'] ?? 'template');

        // Template
        $findTpl->execute(['t' => $tenantId, 'n' => $tplName]);
        $tplId = (int) ($findTpl->fetchColumn() ?: 0);
        if (!$tplId) {
            $insTpl->execute([
                't'  => $tenantId,
                'n'  => $tplName,
                'm'  => $entry['template']['memo_template'],
                'ls' => $lineSource,
            ]);
            $tplId = (int) $pdo->lastInsertId();
            if ($lineSource === 'template') {
                foreach ($entry['template']['lines'] ?? [] as $l) {
                    $insLine->execute([
                        't' => $tenantId, 'tpl' => $tplId,
                        'ln' => (int) $l['line_no'],
                        'sel' => $l['account_selector'],
                        'df' => $l['debit_formula']  ?? null,
                        'cf' => $l['credit_formula'] ?? null,
                        'desc' => $l['description_template'] ?? null,
                    ]);
                }
            }
            $templatesInserted++;
        }

        // Rule
        $findRule->execute([
            't' => $tenantId, 'et' => $entry['event_type'], 'n' => $entry['rule_name'],
        ]);
        if (!$findRule->fetchColumn()) {
            $insRule->execute([
                't' => $tenantId,
                'n' => $entry['rule_name'],
                'et' => $entry['event_type'],
                'tpl' => $tplId,
                'desc' => 'Default seed pack — Sprint 7c.1. Customisable.',
            ]);
            $rulesInserted++;
        }
    }

    return [
        'rules_inserted'     => $rulesInserted,
        'templates_inserted' => $templatesInserted,
        'pack_size'          => count(POSTING_RULES_DEFAULT_PACK),
    ];
}
