<?php
/**
 * Accounting — Reconciliation packet builder.
 *
 * Given a reconciliation id, builds a structured packet:
 *   {
 *     reconciliation, bank_account, opened_by, closed_by, reopened_by,
 *     matched:   [{posted_date, description, amount, je_number, posting_date, ...}],
 *     unmatched: [{posted_date, description, amount, ...}],
 *     totals:    {matched_total, unmatched_total, matched_count, unmatched_count,
 *                 statement_balance, gl_balance, difference},
 *     ai_narrative, ai_narrative_generated_at
 *   }
 *
 * reconciliationPacketGenerateNarrative() calls aiAsk() with the packet as
 * context and stores the returned text on accounting_reconciliations.ai_narrative.
 * Nothing auto-applies — the UI renders it inside <AISuggestion />.
 */

declare(strict_types=1);

require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/../../../core/ai_service.php';

function reconciliationPacketBuild(int $tenantId, int $reconId): array
{
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT r.*, ba.name AS bank_account_name, ba.gl_account_code, ba.bank_name, ba.last4
         FROM accounting_reconciliations r
         JOIN accounting_bank_accounts ba ON ba.id = r.bank_account_id
         WHERE r.tenant_id = :t AND r.id = :id LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $reconId]);
    $recon = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$recon) throw new \RuntimeException('Reconciliation not found');

    // Matched statement lines on or before period_end.
    $stmt = $db->prepare(
        "SELECT bsl.id, bsl.posted_date, bsl.description, bsl.amount, bsl.bank_reference,
                bsl.matched_je_id, bsl.matched_at, je.je_number, je.posting_date AS je_posting_date,
                je.memo AS je_memo
         FROM accounting_bank_statement_lines bsl
         LEFT JOIN accounting_journal_entries je ON je.id = bsl.matched_je_id
         WHERE bsl.tenant_id = :t AND bsl.bank_account_id = :b
           AND bsl.match_status = 'matched'
           AND bsl.posted_date <= :pe
         ORDER BY bsl.posted_date, bsl.id"
    );
    $stmt->execute(['t' => $tenantId, 'b' => (int) $recon['bank_account_id'], 'pe' => $recon['period_end']]);
    $matched = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Unmatched (still) statement lines on or before period_end.
    $stmt = $db->prepare(
        "SELECT id, posted_date, description, amount, bank_reference, match_status
         FROM accounting_bank_statement_lines
         WHERE tenant_id = :t AND bank_account_id = :b
           AND match_status IN ('unmatched','ignored')
           AND posted_date <= :pe
         ORDER BY posted_date, id"
    );
    $stmt->execute(['t' => $tenantId, 'b' => (int) $recon['bank_account_id'], 'pe' => $recon['period_end']]);
    $unmatched = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $matchedTotal   = array_sum(array_map(fn ($r) => (float) $r['amount'], $matched));
    $unmatchedTotal = array_sum(array_map(fn ($r) => (float) $r['amount'], $unmatched));

    return [
        'reconciliation' => $recon,
        'bank_account'   => [
            'id'              => (int) $recon['bank_account_id'],
            'name'            => $recon['bank_account_name'],
            'gl_account_code' => $recon['gl_account_code'],
            'bank_name'       => $recon['bank_name'],
            'last4'           => $recon['last4'],
        ],
        'matched'   => $matched,
        'unmatched' => $unmatched,
        'totals'    => [
            'matched_count'     => count($matched),
            'unmatched_count'   => count($unmatched),
            'matched_total'     => round($matchedTotal, 2),
            'unmatched_total'   => round($unmatchedTotal, 2),
            'statement_balance' => (float) $recon['statement_balance'],
            'gl_balance'        => (float) $recon['gl_balance'],
            'difference'        => (float) $recon['difference'],
        ],
        'ai_narrative'              => $recon['ai_narrative']              ?? null,
        'ai_narrative_generated_at' => $recon['ai_narrative_generated_at'] ?? null,
    ];
}

/**
 * Call aiAsk() with the packet totals as context; persist the narrative.
 * Returns the AI envelope (including `content`, `model`, `interaction_id`).
 */
function reconciliationPacketGenerateNarrative(int $tenantId, int $reconId, ?int $actorUserId): array
{
    $packet = reconciliationPacketBuild($tenantId, $reconId);
    $t      = $packet['totals'];

    $context = [
        'bank_account'      => $packet['bank_account']['name'],
        'period_end'        => $packet['reconciliation']['period_end'],
        'statement_balance' => $t['statement_balance'],
        'gl_balance'        => $t['gl_balance'],
        'difference'        => $t['difference'],
        'matched_count'     => $t['matched_count'],
        'unmatched_count'   => $t['unmatched_count'],
        'matched_total'     => $t['matched_total'],
        'unmatched_total'   => $t['unmatched_total'],
        'unmatched_sample'  => array_slice($packet['unmatched'], 0, 10),
    ];

    $res = aiAsk([
        'feature_class' => 'narrative',
        'kind'          => 'narrative',
        'feature_key'   => 'accounting.reconciliation.packet_narrative',
        'prompt'        => 'Write a concise (120-180 word) reconciliation packet narrative. '
                         . 'Describe whether the reconciliation is in balance or not, how many items '
                         . 'are still unmatched, and surface any notable patterns in the unmatched sample. '
                         . 'Do NOT restate any dollar figures — the packet already shows them in a table. '
                         . 'Close with a one-line verdict.',
        'context'       => $context,
        'max_output_tokens' => 400,
    ]);

    getDB()->prepare(
        'UPDATE accounting_reconciliations
         SET ai_narrative = :n, ai_narrative_generated_at = :ts
         WHERE id = :id AND tenant_id = :t'
    )->execute([
        'n'  => $res['content'] ?? '',
        'ts' => date('Y-m-d H:i:s'),
        'id' => $reconId, 't' => $tenantId,
    ]);

    return $res;
}
