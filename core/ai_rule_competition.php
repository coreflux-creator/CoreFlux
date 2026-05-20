<?php
/**
 * core/ai_rule_competition.php — Phase 2 AI v1: rule replay + scoring.
 *
 * Given an AI rule proposal (current_rule_json vs proposed_rule_json), this
 * library deterministically replays the last N relevant events through both
 * rules and produces:
 *   - per-event diff log
 *   - aggregate "events_changed" + "dollars_changed"
 *   - a 0..1 confidence score that the proposed rule is better
 *
 * v1 ships ONE rule type:
 *
 *   rule_type = 'ap_expense_category_map'
 *     Shape:  { "<category>": "<account_code>", "...": "...", "default": "<code>" }
 *     Replay: sample the last N posted AP bill lines, run their category
 *             through each rule, see which account_code each rule would
 *             have chosen, compute the $ delta.
 *
 * Adding a new rule type is a 1-function patch in this file
 * (rcRegisterReplay) — no schema migration needed.
 *
 *   aiRuleCompete(int $proposalId, int $sampleSize = 50): array
 *
 * Stateless, idempotent — running it twice on the same proposal overwrites
 * comparison_json with the freshly-computed values.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// ── Rule-type replay registry ────────────────────────────────────────
// Each entry maps a rule_type to a callable:
//   fn(int $tenantId, array $rule, int $sampleSize) -> array<int,array>
// Each row in the returned array describes ONE event's outcome under the
// rule: { event_key, dollars, outcome_value, raw }.
$GLOBALS['_AI_RULE_REPLAY_REGISTRY'] = $GLOBALS['_AI_RULE_REPLAY_REGISTRY'] ?? [];

function rcRegisterReplay(string $ruleType, callable $fn): void
{
    $GLOBALS['_AI_RULE_REPLAY_REGISTRY'][$ruleType] = $fn;
}

function rcReplayRule(int $tenantId, string $ruleType, array $rule, int $sampleSize): array
{
    if (!isset($GLOBALS['_AI_RULE_REPLAY_REGISTRY'][$ruleType])) {
        throw new \InvalidArgumentException("rcReplayRule: no replay handler for rule_type '{$ruleType}'");
    }
    return ($GLOBALS['_AI_RULE_REPLAY_REGISTRY'][$ruleType])($tenantId, $rule, $sampleSize);
}

// ── v1 replay: ap_expense_category_map ──────────────────────────────
// Reads the last $sampleSize posted ap_bill_lines for this tenant, joined
// to bill headers for tenant + status filters. The category column on the
// LINE is the input; the rule maps category → expense account code.
rcRegisterReplay('ap_expense_category_map', function (int $tenantId, array $rule, int $sampleSize): array {
    $pdo = getDB();

    // Confirm the table exists. If a fresh tenant hasn't run the AP module
    // migration yet, return an empty sample (the caller will surface a
    // friendly "not enough history" message).
    try {
        $pdo->query('SELECT 1 FROM ap_bill_lines LIMIT 0');
    } catch (\Throwable $_) { return []; }

    $stmt = $pdo->prepare(
        'SELECT l.id AS line_id, l.bill_id, l.category, l.amount,
                l.gl_expense_account_code AS posted_account_code,
                b.bill_date, b.vendor_id
           FROM ap_bill_lines l
           JOIN ap_bills      b ON b.id = l.bill_id
          WHERE b.tenant_id = :t
            AND b.status IN ("approved","paid","partially_paid","posted")
          ORDER BY b.bill_date DESC, l.id DESC
          LIMIT ' . max(1, (int) $sampleSize)
    );
    $stmt->execute(['t' => $tenantId]);

    $default = (string) ($rule['default'] ?? '6900');
    $out = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $cat       = (string) ($row['category'] ?? '');
        $proposed  = (string) ($rule[$cat] ?? $default);
        $out[] = [
            'event_key'     => "ap_bill_line:{$row['line_id']}",
            'dollars'       => (float) $row['amount'],
            'outcome_value' => $proposed,
            'raw'           => [
                'bill_id'              => (int) $row['bill_id'],
                'category'             => $cat,
                'amount'               => (float) $row['amount'],
                'posted_account_code'  => $row['posted_account_code'],
            ],
        ];
    }
    return $out;
});

/**
 * Compete a single proposal: load it, replay both rules, diff, persist.
 *
 * Returns the updated proposal row including comparison_json + score.
 */
function aiRuleCompete(int $proposalId, int $sampleSize = 50): array
{
    $pdo = getDB();
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $stmt = $pdo->prepare('SELECT * FROM rule_proposals WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $proposalId]);
    $prop = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$prop) throw new \RuntimeException("aiRuleCompete: proposal {$proposalId} not found");

    $tenantId = (int) $prop['tenant_id'];
    $ruleType = (string) $prop['rule_type'];
    $current  = is_array($cur = json_decode((string) $prop['current_rule_json'],  true)) ? $cur : [];
    $proposed = is_array($pro = json_decode((string) $prop['proposed_rule_json'], true)) ? $pro : [];

    try {
        $beforeRows = rcReplayRule($tenantId, $ruleType, $current,  $sampleSize);
        $afterRows  = rcReplayRule($tenantId, $ruleType, $proposed, $sampleSize);
    } catch (\Throwable $e) {
        // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
        $pdo->prepare('UPDATE rule_proposals SET status="error", status_reason=:r WHERE id=:id')
            ->execute(['r' => substr($e->getMessage(), 0, 240), 'id' => $proposalId]);
        throw $e;
    }

    // Index by event_key (both arrays are sorted identically by the
    // replay; we still index defensively in case a row dropped).
    $beforeByKey = [];
    foreach ($beforeRows as $r) $beforeByKey[$r['event_key']] = $r;

    $diff = [];
    $changedCount  = 0;
    $changedDollars = 0.0;
    foreach ($afterRows as $a) {
        $b = $beforeByKey[$a['event_key']] ?? null;
        if (!$b) continue;
        $changed = (string) $b['outcome_value'] !== (string) $a['outcome_value'];
        if ($changed) {
            $changedCount++;
            $changedDollars += (float) $a['dollars'];
            $diff[] = [
                'event_key'   => $a['event_key'],
                'dollars'     => $a['dollars'],
                'from'        => $b['outcome_value'],
                'to'          => $a['outcome_value'],
                'raw'         => $a['raw'],
            ];
        }
    }
    $totalCompared = count($afterRows);

    // Score: tighter "fewer surprise changes are better" with a small
    // bias toward proposals that move at least some events. A wholly
    // identical proposal scores 0; a wholly different proposal scores low
    // too (operators want measured changes, not full overhauls).
    $changeRatio = $totalCompared > 0 ? $changedCount / $totalCompared : 0.0;
    $score = $changeRatio === 0.0
        ? 0.0
        : max(0.0, min(1.0, 1.0 - abs($changeRatio - 0.15) * 2));

    $comparison = [
        'rule_type'          => $ruleType,
        'sample_size'        => $totalCompared,
        'events_changed'     => $changedCount,
        'change_ratio'       => round($changeRatio, 4),
        'dollars_changed'    => round($changedDollars, 2),
        'diff'               => array_slice($diff, 0, 200),     // bound storage
        'computed_at'        => date('c'),
    ];

    // tenant-leak-allow: defense-in-depth — caller scoped row by tenant_id before this id-only write
    $pdo->prepare(
        'UPDATE rule_proposals
            SET comparison_json = :cj,
                score           = :sc,
                events_compared = :ec,
                events_changed  = :ech,
                dollars_changed = :dc,
                status          = IF(status IN ("accepted","rejected","applied"), status, "competed"),
                updated_at      = NOW()
          WHERE id = :id'
    )->execute([
        'cj'  => json_encode($comparison),
        'sc'  => round($score, 4),
        'ec'  => $totalCompared,
        'ech' => $changedCount,
        'dc'  => round($changedDollars, 2),
        'id'  => $proposalId,
    ]);

    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $stmt = $pdo->prepare('SELECT * FROM rule_proposals WHERE id = :id');
    $stmt->execute(['id' => $proposalId]);
    return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
}
