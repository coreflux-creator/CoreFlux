<?php
/**
 * core/ai/workflows/graphs/transaction_classification.php
 *
 * Spec §6 reference graph. Slice 3 is READ-ONLY — the graph
 * produces a *suggestion* (proposed GL account + memo + confidence)
 * and parks for approval. Slice 4 will wire the approved suggestion
 * to a journal_entry_drafts row via a write-tool.
 *
 *   ┌─────────────────────┐
 *   │  vendor_resolution  │   match counterparty → known vendor
 *   └──────────┬──────────┘
 *              │
 *              ▼
 *   ┌─────────────────────┐
 *   │      retrieval      │   prior classifications for similar txns
 *   └──────────┬──────────┘
 *              │
 *              ▼
 *   ┌─────────────────────┐
 *   │      classify       │   LLM proposes account + memo + confidence
 *   └──────────┬──────────┘
 *              │
 *              ▼
 *   ┌─────────────────────┐
 *   │   confidence_gate   │   ≥0.85 → auto_suggest, else → review_required
 *   └──────────┬──────────┘
 *              ├─────────────→ review_required (parks for human approval)
 *              ▼
 *   ┌─────────────────────┐
 *   │    auto_suggest     │   writes suggestion to state, completes
 *   └─────────────────────┘
 *
 * State shape (across nodes):
 *   {
 *     transaction: {source, id, amount_cents, currency, description, posted_at},
 *     vendor:      {matched: bool, vendor_id?, vendor_name?, alias_source?},
 *     prior:       [{txn_id, account_code, memo, confidence}, …],
 *     classification: {account_code, account_name, memo, confidence, rationale},
 *     route:       'auto_suggest' | 'review_required',
 *     _output:     final suggestion envelope on completion
 *   }
 *
 * The graph is registered when this file is required.
 */
declare(strict_types=1);

require_once __DIR__ . '/../engine.php';

workflowRegisterGraph([
    'name'    => 'transaction_classification',
    'version' => '2026-02-r1',
    'entry'   => 'vendor_resolution',
    'nodes'   => [

        // ── vendor_resolution ─────────────────────────────────────
        // Slice 3: best-effort match against the existing `vendors`
        // table by exact counterparty-name match. The future
        // `resolveVendorAlias` tool (ticket #10) will swap in here.
        'vendor_resolution' => function (array $state, array $ctx): array {
            $txn = $state['transaction'] ?? [];
            $name = trim((string) ($txn['description'] ?? ''));
            $vendor = ['matched' => false];
            if ($name !== '') {
                try {
                    $stmt = getDB()->prepare(
                        'SELECT id, name FROM vendors
                          WHERE tenant_id = :t AND LOWER(name) = LOWER(:n)
                          LIMIT 1'
                    );
                    $stmt->execute(['t' => (int) ($ctx['_tenant_id'] ?? 0), 'n' => $name]);
                    if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                        $vendor = ['matched' => true,
                                   'vendor_id' => (int) $row['id'],
                                   'vendor_name' => (string) $row['name'],
                                   'alias_source' => 'exact_name'];
                    }
                } catch (\Throwable $e) { /* schema-not-ready tolerated */ }
            }
            $state['vendor'] = $vendor;
            return $state;
        },

        // ── retrieval ─────────────────────────────────────────────
        // Pull the 5 most recent prior classifications for this
        // vendor. In Slice 5+ this becomes pgvector retrieval; for
        // now a simple table query on accounting_bank_statement_lines
        // joined to journal_entry_lines is over-scoped — instead we
        // read the new `ai_prior_classifications` projection if it
        // exists, otherwise return empty (graph still progresses).
        'retrieval' => function (array $state, array $ctx): array {
            $state['prior'] = [];
            $vendorId = (int) ($state['vendor']['vendor_id'] ?? 0);
            if ($vendorId > 0) {
                try {
                    $stmt = getDB()->prepare(
                        'SELECT account_code, memo, confidence
                           FROM ai_prior_classifications
                          WHERE tenant_id = :t AND vendor_id = :v
                          ORDER BY id DESC LIMIT 5'
                    );
                    $stmt->execute(['t' => (int) ($ctx['_tenant_id'] ?? 0), 'v' => $vendorId]);
                    $state['prior'] = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                } catch (\Throwable $e) { /* table not yet provisioned */ }
            }
            return $state;
        },

        // ── classify ──────────────────────────────────────────────
        // Slice 3 keeps this DETERMINISTIC for testability:
        //   - if a prior classification exists, copy the most recent
        //     one with confidence = max(0.9, prior.confidence).
        //   - else if vendor matched but no prior, suggest a
        //     placeholder account ('6000-MISC') with confidence 0.5.
        //   - else (unknown vendor), confidence 0.2.
        //
        // The LLM-driven version mounts as an alternative node in
        // Slice 5+ once we have evals on prior-classification
        // accuracy and a clear prompt. Keeping the deterministic
        // path lets us land + test the engine WITHOUT a live
        // OpenAI call.
        'classify' => function (array $state, array $ctx): array {
            $prior  = $state['prior']  ?? [];
            $vendor = $state['vendor'] ?? [];
            if (!empty($prior)) {
                $top = $prior[0];
                $state['classification'] = [
                    'account_code' => (string) ($top['account_code'] ?? '6000-MISC'),
                    'account_name' => 'Inferred from prior classifications',
                    'memo'         => (string) ($top['memo'] ?? ''),
                    'confidence'   => max(0.9, (float) ($top['confidence'] ?? 0.9)),
                    'rationale'    => sprintf('Matches %d prior classification(s) for vendor #%s',
                                              count($prior), (string) ($vendor['vendor_id'] ?? '?')),
                ];
            } elseif (!empty($vendor['matched'])) {
                $state['classification'] = [
                    'account_code' => '6000-MISC',
                    'account_name' => 'Miscellaneous expense',
                    'memo'         => 'Vendor known but no prior classification — please review.',
                    'confidence'   => 0.5,
                    'rationale'    => 'Vendor matched by exact name but no historical mapping.',
                ];
            } else {
                $state['classification'] = [
                    'account_code' => '6000-MISC',
                    'account_name' => 'Miscellaneous expense',
                    'memo'         => 'Unknown counterparty — please review.',
                    'confidence'   => 0.2,
                    'rationale'    => 'No vendor match and no prior classification.',
                ];
            }
            return $state;
        },

        // ── confidence_gate ───────────────────────────────────────
        // Branches by setting `state.route`. The edge map reads
        // this value and picks the next node accordingly.
        'confidence_gate' => function (array $state, array $ctx): array {
            $conf = (float) ($state['classification']['confidence'] ?? 0);
            $state['route'] = $conf >= 0.85 ? 'auto_suggest' : 'review_required';
            return $state;
        },

        // ── auto_suggest ──────────────────────────────────────────
        // High-confidence terminal: hand the suggestion back to the
        // caller. No DB write — that's Slice 4 with `draftJournalEntry`.
        'auto_suggest' => function (array $state, array $ctx): array {
            $state['_output'] = [
                'kind'           => 'suggestion',
                'route'          => 'auto_suggest',
                'classification' => $state['classification'] ?? null,
                'vendor'         => $state['vendor']         ?? null,
                'prior_count'    => count($state['prior']    ?? []),
                'transaction'    => $state['transaction']    ?? null,
                'requires_human_review' => false,
            ];
            return $state;
        },

        // ── review_required ───────────────────────────────────────
        // Low-confidence path: park for a human reviewer via the
        // approval interrupt. The reviewer can accept the suggestion
        // (decision_payload empty) or override (decision_payload
        // contains a new {account_code, memo}). On resume the
        // engine flows into `apply_review_decision`.
        'review_required' => function (array $state, array $ctx): array {
            workflowRequireApproval(
                'classify_transaction',
                /* risk */ 3,
                /* payload */ [
                    'transaction'    => $state['transaction']    ?? null,
                    'classification' => $state['classification'] ?? null,
                    'vendor'         => $state['vendor']         ?? null,
                    'prior'          => array_slice($state['prior'] ?? [], 0, 3),
                ],
                /* role */ 'accounting_reviewer'
            );
            // Unreachable — the engine catches the exception above.
            return $state; // @codeCoverageIgnore
        },

        // ── apply_review_decision ─────────────────────────────────
        // Runs after a reviewer approves a low-confidence
        // suggestion. Merges any reviewer overrides onto the
        // classification before the run completes.
        'apply_review_decision' => function (array $state, array $ctx): array {
            $approval = $state['_approval'] ?? [];
            $override = is_array($approval['decision_payload'] ?? null) ? $approval['decision_payload'] : [];
            $cls = $state['classification'] ?? [];
            foreach (['account_code','account_name','memo'] as $k) {
                if (!empty($override[$k])) $cls[$k] = (string) $override[$k];
            }
            $cls['reviewed_by_user_id'] = $approval['decision_payload']['reviewer_id'] ?? null;
            $state['classification'] = $cls;
            $state['_output'] = [
                'kind'           => 'suggestion',
                'route'          => 'review_required',
                'classification' => $cls,
                'vendor'         => $state['vendor']         ?? null,
                'prior_count'    => count($state['prior']    ?? []),
                'transaction'    => $state['transaction']    ?? null,
                'requires_human_review' => false, // already reviewed
                'approval_id'    => $approval['id'] ?? null,
            ];
            return $state;
        },
    ],

    'edges'   => [
        'vendor_resolution' => 'retrieval',
        'retrieval'         => 'classify',
        'classify'          => 'confidence_gate',
        'confidence_gate'   => function (array $state): string {
            return ($state['route'] ?? 'auto_suggest') === 'auto_suggest'
                ? 'auto_suggest' : 'review_required';
        },
        'auto_suggest'      => '__end__',
        // From review_required the engine pauses — when we resume
        // after approval, we flow into apply_review_decision.
        'review_required'   => 'apply_review_decision',
        'apply_review_decision' => '__end__',
    ],
]);
