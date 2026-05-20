<?php
/**
 * Posting engine — accountingProcessEvent (Sprint 7b, spec §12-13).
 *
 * Modules emit accounting events (or pass an in-memory event payload to
 * the process function directly). The engine:
 *   1. Loads the highest-priority matching posting_rule for (tenant,
 *      entity, event_type), evaluating optional JSON conditions.
 *   2. Renders the rule's journal_template into a balanced JE.
 *   3. Posts the JE through the existing accountingPostJe() (preserving
 *      idempotency, audit trail, period checks).
 *   4. Stamps accounting_events.status=posted + journal_entry_id, and
 *      writes a row into accounting_subledger_links.
 *
 * Errors
 *   - no rule:          status=ignored,  error_message='no posting rule matched'
 *   - render/eval err:  status=failed,   error_message=...
 *   - period closed:    status=failed    (raised by accountingPostJe)
 *   - unbalanced:       status=failed    (raised by accountingPostJe)
 *
 * Idempotency
 *   - The (tenant_id, source_module, source_record_id, event_type)
 *     unique key on accounting_events guarantees a given source record's
 *     event is processed at most once. Re-calling process on a
 *     status='posted' event is a no-op.
 *
 * Dry run
 *   - When $dryRun=true, the engine renders the JE shape and returns it
 *     without inserting an accounting_events row, without posting a JE,
 *     and without stamping subledger_links. Used by the rule sandbox UI
 *     and by `?dry_run=1` on POST /api/accounting/events.
 */
declare(strict_types=1);

require_once __DIR__ . '/formula.php';
require_once __DIR__ . '/../accounting/system_accounts.php';
require_once __DIR__ . '/../../modules/accounting/lib/accounting.php';

/**
 * @param array $event { entity_id, event_type, source_module,
 *                       source_record_id, event_date, payload }
 * @return array {
 *     status: 'posted'|'ignored'|'failed'|'preview',
 *     event_id?, journal_entry_id?, je_number?, total_debit, total_credit,
 *     rule_id?, template_id?, lines: [...rendered], error?
 * }
 */
function accountingProcessEvent(int $tenantId, array $event, ?int $actorUserId = null, bool $dryRun = false): array {
    foreach (['entity_id', 'event_type', 'source_module', 'source_record_id', 'event_date', 'payload'] as $req) {
        if (!array_key_exists($req, $event)) {
            throw new \InvalidArgumentException("event is missing '{$req}'");
        }
    }
    $payload = is_array($event['payload']) ? $event['payload'] : [];

    // Phase 1a — Event Registry validation (Live Books Rails, 2026-02-14).
    // Validates the event_type + required payload keys against the canonical
    // catalog in `event_registry`. Degrades to warn-only when the registry
    // table is missing (tenants that haven't run migration 036 yet).
    require_once __DIR__ . '/../event_registry.php';
    $validation = eventRegistryValidate(
        (string) $event['event_type'],
        $payload,
        (int) ($event['schema_version'] ?? 1)
    );
    if (!$validation['ok']) {
        throw new \InvalidArgumentException(
            "Event rejected by registry: " . implode('; ', $validation['errors'])
        );
    }
    if (!empty($validation['warnings'])) {
        foreach ($validation['warnings'] as $w) {
            error_log("[event-registry] tenant={$tenantId} type={$event['event_type']} src={$event['source_module']}:{$event['source_record_id']} :: {$w}");
        }
    }

    $context = ['payload' => $payload, 'event' => $event];

    $pdo = getDB();
    if (!$pdo) throw new \RuntimeException('no DB');

    // 1) Match a posting rule.
    $rule = postingEngineFindRule($pdo, $tenantId, (int) $event['entity_id'], (string) $event['event_type'], $context);

    // 2) Persist (or skip) the event row.
    $eventId = null;
    if (!$dryRun) {
        $insStmt = $pdo->prepare(
            'INSERT INTO accounting_events
                (tenant_id, entity_id, event_type, source_module,
                 source_record_id, event_date, payload, status,
                 posting_rule_id, created_by_user_id)
             VALUES (:t, :e, :et, :sm, :sr, :ed, :pl, "received", :rid, :u)'
        );
        try {
            $insStmt->execute([
                't' => $tenantId, 'e' => (int) $event['entity_id'],
                'et' => $event['event_type'], 'sm' => $event['source_module'],
                'sr' => (string) $event['source_record_id'],
                'ed' => $event['event_date'], 'pl' => json_encode($payload),
                'rid' => $rule['id'] ?? null, 'u' => $actorUserId,
            ]);
            $eventId = (int) $pdo->lastInsertId();
        } catch (\PDOException $e) {
            // Idempotency: if the event already exists, fetch it and
            // either return its existing posted result or re-attempt.
            if ((int) $e->errorInfo[1] === 1062) {
                $sel = $pdo->prepare(
                    'SELECT id, status, journal_entry_id FROM accounting_events
                      WHERE tenant_id = :t AND source_module = :sm
                        AND source_record_id = :sr AND event_type = :et'
                );
                $sel->execute([
                    't' => $tenantId, 'sm' => $event['source_module'],
                    'sr' => (string) $event['source_record_id'],
                    'et' => $event['event_type'],
                ]);
                $existing = $sel->fetch(\PDO::FETCH_ASSOC);
                if ($existing && $existing['status'] === 'posted') {
                    return [
                        'status' => 'posted',
                        'event_id' => (int) $existing['id'],
                        'journal_entry_id' => (int) $existing['journal_entry_id'],
                        'idempotent_replay' => true,
                    ];
                }
                $eventId = $existing ? (int) $existing['id'] : null;
            } else {
                throw $e;
            }
        }
    }

    // 3) No rule? Ignore.
    if (!$rule) {
        if ($eventId) {
            // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
            $pdo->prepare('UPDATE accounting_events SET status="ignored", error_message=:m WHERE id = :id')
                ->execute(['m' => 'no posting rule matched', 'id' => $eventId]);
        }
        return [
            'status' => 'ignored',
            'event_id' => $eventId,
            'error' => 'no posting rule matched',
        ];
    }

    // 4) Render the journal template into a JE.
    try {
        $rendered = postingEngineRender($pdo, $tenantId, (int) $rule['journal_template_id'], $context, (string) $event['event_date']);
    } catch (\Throwable $e) {
        if ($eventId) {
            // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
            $pdo->prepare('UPDATE accounting_events SET status="failed", error_message=:m WHERE id = :id')
                ->execute(['m' => $e->getMessage(), 'id' => $eventId]);
        }
        return [
            'status' => 'failed',
            'event_id' => $eventId,
            'rule_id' => (int) $rule['id'],
            'template_id' => (int) $rule['journal_template_id'],
            'error' => $e->getMessage(),
        ];
    }

    // 5) Dry-run? Return the rendered shape.
    if ($dryRun) {
        return [
            'status' => 'preview',
            'rule_id' => (int) $rule['id'],
            'template_id' => (int) $rule['journal_template_id'],
            'rule_name' => (string) $rule['name'],
            'je' => $rendered,
        ];
    }

    // 6) Post the JE through the canonical chokepoint.
    try {
        $rendered['idempotency_key'] = sprintf('event_%d_%d', $tenantId, $eventId);
        $rendered['source_module'] = (string) $event['source_module'];
        $posted = accountingPostJe($tenantId, $rendered, $actorUserId, /* post */ true);
    } catch (\Throwable $e) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE accounting_events SET status="failed", error_message=:m WHERE id = :id')
            ->execute(['m' => $e->getMessage(), 'id' => $eventId]);

        // Phase 1d — surface the failure on the unified exception queue
        // so it shows up in the operator's inbox.
        try {
            require_once __DIR__ . '/../exception_queue.php';
            exceptionOpen($tenantId, 'event.error', [
                'severity'         => 'high',
                'title'            => "Posting failed: {$event['event_type']} ({$event['source_module']}:{$event['source_record_id']})",
                'subject_type'     => 'accounting_event',
                'subject_id'       => $eventId,
                'opened_by_user_id'=> $actorUserId,
                'payload'          => [
                    'event_type'    => $event['event_type'],
                    'source_module' => $event['source_module'],
                    'error_message' => $e->getMessage(),
                    'rule_id'       => (int) $rule['id'],
                ],
            ]);
        } catch (\Throwable $_) { /* best-effort */ }

        return [
            'status' => 'failed',
            'event_id' => $eventId,
            'rule_id' => (int) $rule['id'],
            'error' => $e->getMessage(),
        ];
    }

    // 7) Mark posted + write subledger link.
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $pdo->prepare(
        'UPDATE accounting_events
            SET status="posted", journal_entry_id=:je, posted_at=NOW(), error_message=NULL
          WHERE id=:id'
    )->execute(['je' => $posted['je_id'], 'id' => $eventId]);

    $linkStmt = $pdo->prepare(
        'INSERT IGNORE INTO accounting_subledger_links
            (tenant_id, source_module, source_record_id, journal_entry_id, accounting_event_id, link_kind)
         VALUES (:t, :sm, :sr, :je, :ev, "primary")'
    );
    $linkStmt->execute([
        't' => $tenantId, 'sm' => $event['source_module'],
        'sr' => (string) $event['source_record_id'],
        'je' => (int) $posted['je_id'], 'ev' => $eventId,
    ]);

    // 7b) Phase 1c — auto-link lineage if the emit dict declared parents.
    // Accepts either parent_event_id (singular) or parent_event_ids[] for
    // fan-in cases (e.g. one payment applies to many invoices).
    $parentIds = [];
    if (!empty($event['parent_event_id'])) {
        $parentIds[] = (int) $event['parent_event_id'];
    }
    if (!empty($event['parent_event_ids']) && is_array($event['parent_event_ids'])) {
        foreach ($event['parent_event_ids'] as $pid) $parentIds[] = (int) $pid;
    }
    $parentIds = array_values(array_unique(array_filter($parentIds, fn ($x) => $x > 0)));
    if ($parentIds) {
        try {
            require_once __DIR__ . '/../event_lineage.php';
            $relationship = (string) ($event['lineage_relationship'] ?? 'spawned_by');
            foreach ($parentIds as $pid) {
                eventLineageLink($tenantId, $pid, $eventId, $relationship, $actorUserId);
            }
        } catch (\Throwable $e) {
            error_log('[event-lineage] link failed: ' . $e->getMessage());
        }
    }

    // 8) Phase 1b — record a deterministic AI interpretation row so every
    // posted event has a traceable "this is the JE we proposed and why".
    // Rule-derived interpretations get confidence=1.0 and are auto-accepted
    // (no human review required). Phase 2's actual AI proposes will compete
    // with these for events where no rule matches.
    try {
        require_once __DIR__ . '/../ai_interpretation.php';
        require_once __DIR__ . '/../event_registry.php';
        $hintRow = eventRegistryGet((string) $event['event_type']);
        aiInterpretationRecord($tenantId, $eventId, [
            'proposed_by'       => 'posting_rule:' . (int) $rule['id'],
            'confidence'        => 1.000,
            'proposed_je_lines' => array_map(static function ($l) {
                return [
                    'account_code' => $l['account_code'] ?? null,
                    'debit'        => (float) ($l['debit']  ?? 0),
                    'credit'       => (float) ($l['credit'] ?? 0),
                    'memo'         => $l['description'] ?? $l['memo'] ?? null,
                    'dims'         => $l['dims'] ?? null,
                ];
            }, $rendered['lines'] ?? []),
            'reasoning'         => sprintf(
                'Deterministic posting via rule "%s" (id=%d) → template id=%d.',
                (string) $rule['name'], (int) $rule['id'], (int) $rule['journal_template_id']
            ),
            'typical_accounting_hint' => $hintRow['typical_accounting'] ?? null,
            'status'            => 'accepted',
            'requires_review'   => false,
            'journal_entry_id'  => (int) $posted['je_id'],
            'je_number'         => $posted['je_number'] ?? null,
        ]);
    } catch (\Throwable $e) {
        // Best-effort — Phase 1b table may not exist yet on older tenants.
        error_log('[ai-interpretation] record failed: ' . $e->getMessage());
    }

    return [
        'status' => 'posted',
        'event_id' => $eventId,
        'rule_id' => (int) $rule['id'],
        'template_id' => (int) $rule['journal_template_id'],
        'journal_entry_id' => (int) $posted['je_id'],
        'je_number' => $posted['je_number'] ?? null,
        'total_debit' => (float) ($posted['total_debit'] ?? 0),
        'total_credit' => (float) ($posted['total_credit'] ?? 0),
    ];
}

// ──────────────────────────────────────────────────────────────────
// Rule selection
// ──────────────────────────────────────────────────────────────────

function postingEngineFindRule(\PDO $pdo, int $tenantId, int $entityId, string $eventType, array $context): ?array {
    $stmt = $pdo->prepare(
        'SELECT * FROM accounting_posting_rules
          WHERE tenant_id = :t AND status = "active"
            AND event_type = :et
            AND (entity_id IS NULL OR entity_id = :e)
          ORDER BY priority DESC, id ASC'
    );
    $stmt->execute(['t' => $tenantId, 'et' => $eventType, 'e' => $entityId]);
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $cond = $r['conditions'] ? json_decode((string) $r['conditions'], true) : [];
        if (!is_array($cond)) $cond = [];
        if (postingEngineMatchConditions($cond, $context)) {
            return $r;
        }
    }
    return null;
}

/**
 * Conditions are a flat dict { "payload.x": value }
 *   - scalar value → equality
 *   - {"gt": N}, {"gte": N}, {"lt": N}, {"lte": N}, {"eq": V}, {"ne": V}
 *   - {"in": [a,b,c]}
 * Empty / missing conditions ⇒ match.
 */
function postingEngineMatchConditions(array $cond, array $context): bool {
    foreach ($cond as $path => $expected) {
        $actual = formulaResolveRef((string) $path, $context, /* strict */ false);
        if (is_array($expected)) {
            foreach ($expected as $op => $val) {
                $ok = match ($op) {
                    'gt'  => is_numeric($actual) && (float) $actual >  (float) $val,
                    'gte' => is_numeric($actual) && (float) $actual >= (float) $val,
                    'lt'  => is_numeric($actual) && (float) $actual <  (float) $val,
                    'lte' => is_numeric($actual) && (float) $actual <= (float) $val,
                    'eq'  => $actual == $val,
                    'ne'  => $actual != $val,
                    'in'  => is_array($val) && in_array($actual, $val, false),
                    default => false,
                };
                if (!$ok) return false;
            }
        } else {
            if ($actual != $expected) return false;
        }
    }
    return true;
}

// ──────────────────────────────────────────────────────────────────
// Template rendering
// ──────────────────────────────────────────────────────────────────

function postingEngineRender(\PDO $pdo, int $tenantId, int $templateId, array $context, string $eventDate): array {
    $tplStmt = $pdo->prepare('SELECT * FROM accounting_journal_templates WHERE tenant_id = :t AND id = :id');
    $tplStmt->execute(['t' => $tenantId, 'id' => $templateId]);
    $tpl = $tplStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$tpl) throw new \RuntimeException("template {$templateId} not found");

    // Sprint 7e — passthrough line source. Variable-shape JEs (bills with
    // N expense lines, invoices with N revenue lines) emit their lines on
    // the event payload as `payload.lines[]`; the engine forwards them
    // verbatim instead of materialising a fixed-N template. The emitter is
    // responsible for balance.
    $lineSource = (string) ($tpl['line_source'] ?? 'template');
    if ($lineSource === 'payload') {
        $payloadLines = $context['payload']['lines'] ?? null;
        if (!is_array($payloadLines) || count($payloadLines) < 2) {
            throw new \RuntimeException("template {$templateId} is line_source=payload but event has no payload.lines[]");
        }
        $lines = [];
        $td = 0.0; $tc = 0.0;
        foreach ($payloadLines as $i => $pl) {
            if (!is_array($pl)) throw new \RuntimeException("payload.lines[{$i}] not an object");
            // Resolve account: prefer account_id, fall back to account_code.
            if (!empty($pl['account_id'])) {
                $accountId = (int) $pl['account_id'];
            } elseif (!empty($pl['account_code'])) {
                $accountId = postingEngineLookupAccountByCode($pdo, $tenantId, (string) $pl['account_code']);
            } else {
                throw new \RuntimeException("payload.lines[{$i}] needs account_id or account_code");
            }
            $debit  = (float) ($pl['debit']  ?? 0);
            $credit = (float) ($pl['credit'] ?? 0);
            if ($debit  < 0 || $credit < 0)                  throw new \RuntimeException("payload.lines[{$i}] negative amount");
            if ($debit  > 0 && $credit > 0)                  throw new \RuntimeException("payload.lines[{$i}] cannot have both debit and credit");
            $td += $debit; $tc += $credit;
            $lines[] = [
                'account_id'  => $accountId,
                'debit'       => round($debit, 2),
                'credit'      => round($credit, 2),
                'description' => isset($pl['description']) ? (string) $pl['description'] : (isset($pl['memo']) ? (string) $pl['memo'] : null),
                'dimensions'  => isset($pl['dimensions']) && is_array($pl['dimensions']) ? $pl['dimensions'] : null,
            ];
        }
        if (round($td, 2) !== round($tc, 2)) {
            throw new \RuntimeException("payload.lines unbalanced (debit={$td}, credit={$tc})");
        }
        $memo = $tpl['memo_template'] ? formulaInterpolate((string) $tpl['memo_template'], $context) : null;
        return [
            'entity_id'    => (int) $context['event']['entity_id'],
            'posting_date' => $eventDate,
            'currency'     => (string) ($context['payload']['currency'] ?? 'USD'),
            'memo'         => $memo,
            'lines'        => $lines,
        ];
    }

    $linesStmt = $pdo->prepare(
        'SELECT * FROM accounting_journal_template_lines
          WHERE tenant_id = :t AND journal_template_id = :id
          ORDER BY line_no ASC'
    );
    $linesStmt->execute(['t' => $tenantId, 'id' => $templateId]);
    $tplLines = $linesStmt->fetchAll(\PDO::FETCH_ASSOC);
    if (count($tplLines) < 2) throw new \RuntimeException("template {$templateId} needs at least 2 lines");

    $lines = [];
    foreach ($tplLines as $tl) {
        $accountId = postingEngineResolveAccount($pdo, $tenantId, (string) $tl['account_selector'], $context);
        $debit  = $tl['debit_formula']  ? formulaEvaluate((string) $tl['debit_formula'],  $context) : 0.0;
        $credit = $tl['credit_formula'] ? formulaEvaluate((string) $tl['credit_formula'], $context) : 0.0;
        if ($debit < 0 || $credit < 0) {
            throw new \RuntimeException("line {$tl['line_no']} produced negative amount (debit={$debit}, credit={$credit})");
        }
        if ($debit > 0 && $credit > 0) {
            throw new \RuntimeException("line {$tl['line_no']} cannot have both debit and credit");
        }
        $memo = $tl['description_template'] ? formulaInterpolate((string) $tl['description_template'], $context) : null;
        $dimensions = $tl['dimensions_json'] ? json_decode((string) $tl['dimensions_json'], true) : null;
        if (!is_array($dimensions)) $dimensions = null;
        $lines[] = [
            'account_id'  => $accountId,
            'debit'       => round($debit, 2),
            'credit'      => round($credit, 2),
            'description' => $memo,
            'dimensions'  => $dimensions,
        ];
    }

    $memo = $tpl['memo_template'] ? formulaInterpolate((string) $tpl['memo_template'], $context) : null;
    return [
        'entity_id'    => (int) $context['event']['entity_id'],
        'posting_date' => $eventDate,
        'currency'     => (string) ($context['payload']['currency'] ?? 'USD'),
        'memo'         => $memo,
        'lines'        => $lines,
    ];
}

/**
 * Account selector grammar:
 *   'system:NAME'        → looks up accounting_systemAccountId(tenantId, NAME)
 *   'code:1000'          → looks up by accounting_accounts.code
 *   'id:42'              → literal id (admin escape hatch)
 *   'payload.account_id' → resolves to a numeric account id from the payload
 *   'payload.account_code' → resolves to a code, then looks it up
 */
function postingEngineResolveAccount(\PDO $pdo, int $tenantId, string $selector, array $context): int {
    $selector = trim($selector);
    if (str_starts_with($selector, 'system:')) {
        $name = substr($selector, 7);
        $id = accountingSystemAccountId($tenantId, $name);
        if (!$id) throw new \RuntimeException("system account '{$name}' not seeded for tenant {$tenantId}");
        return $id;
    }
    if (str_starts_with($selector, 'code:')) {
        return postingEngineLookupAccountByCode($pdo, $tenantId, substr($selector, 5));
    }
    if (str_starts_with($selector, 'id:')) {
        return (int) substr($selector, 3);
    }
    // Treat as a payload reference.
    $resolved = formulaResolveRef($selector, $context, /* strict */ true);
    if (is_int($resolved) || (is_string($resolved) && ctype_digit($resolved))) {
        return (int) $resolved;
    }
    if (is_string($resolved) && $resolved !== '') {
        return postingEngineLookupAccountByCode($pdo, $tenantId, $resolved);
    }
    throw new \RuntimeException("could not resolve account selector '{$selector}'");
}

function postingEngineLookupAccountByCode(\PDO $pdo, int $tenantId, string $code): int {
    $stmt = $pdo->prepare(
        'SELECT id FROM accounting_accounts
          WHERE tenant_id = :t AND code = :c AND active = 1
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'c' => $code]);
    $id = $stmt->fetchColumn();
    if (!$id) throw new \RuntimeException("account with code '{$code}' not found");
    return (int) $id;
}
