<?php
/**
 * Accounting — Consolidation engine.
 *
 *  - entityRelationshipGet/Upsert/List                         → CRUD on directional ownership edges
 *  - entityRelationshipResolveDescendants($root, $asOfDate)    → recursive descent with effective dates
 *  - consolidateTrialBalance($tenantId, $entityIds[], $asOf)   → unions per-entity TBs, applies eliminations
 *  - consolidateIncomeStatement($tenantId, $entityIds[], $from, $to)
 *  - consolidateBalanceSheet($tenantId, $entityIds[], $asOf)
 *
 * Elimination logic:
 *   - Any JE line with counterparty_entity_id IN the consolidation scope
 *     AND the opposite-leg entity also IN scope  →  eliminate both sides.
 *   - Falls back to per-pair IC totals from the elimination worksheet to
 *     ensure we eliminate even manually-tagged orphan lines.
 *
 * Ownership-pct treatment:
 *   - 'full' method (majority subsidiary):  include 100% of P&L and BS.
 *   - 'equity' (≤50% typically):            currently included as-is (TODO).
 *   - 'cost':                               excluded from consolidation.
 *   - 'none':                               excluded.
 *
 *   Non-controlling interest (minority) is NOT yet broken out — flagged
 *   TODO for Accounting v1.0.
 */

declare(strict_types=1);

require_once __DIR__ . '/accounting.php';

function entityRelationshipList(int $tenantId): array
{
    return scopedQuery(
        'SELECT r.*, ep.legal_name AS parent_name, ec.legal_name AS child_name
         FROM accounting_entity_relationships r
         LEFT JOIN accounting_entities ep ON ep.id = r.parent_entity_id
         LEFT JOIN accounting_entities ec ON ec.id = r.child_entity_id
         WHERE r.tenant_id = :tenant_id
         ORDER BY r.parent_entity_id, r.child_entity_id'
    );
}

function entityRelationshipUpsert(int $tenantId, array $data): int
{
    $p = (int) ($data['parent_entity_id'] ?? 0);
    $c = (int) ($data['child_entity_id']  ?? 0);
    if ($p <= 0 || $c <= 0) throw new \InvalidArgumentException('parent + child entity ids required');
    if ($p === $c)          throw new \InvalidArgumentException('parent and child must differ');
    $pct    = (float) ($data['ownership_pct'] ?? 100);
    if ($pct < 0 || $pct > 100) throw new \InvalidArgumentException('ownership_pct must be 0..100');
    $rel    = (string) ($data['relationship_type']   ?? 'subsidiary');
    $method = (string) ($data['consolidation_method'] ?? 'full');
    if (!in_array($rel, ['subsidiary','affiliate','branch','jv','other'], true)) {
        throw new \InvalidArgumentException('invalid relationship_type');
    }
    if (!in_array($method, ['full','proportionate','equity','cost','none'], true)) {
        throw new \InvalidArgumentException('invalid consolidation_method');
    }
    $from = (string) ($data['effective_from'] ?? date('Y-m-d'));
    $to   = !empty($data['effective_to']) ? (string) $data['effective_to'] : null;

    $existing = scopedFind(
        'SELECT id FROM accounting_entity_relationships
         WHERE tenant_id = :tenant_id AND parent_entity_id = :p AND child_entity_id = :c AND effective_from = :f',
        ['p' => $p, 'c' => $c, 'f' => $from]
    );
    $row = [
        'ownership_pct'        => $pct,
        'relationship_type'    => $rel,
        'consolidation_method' => $method,
        'effective_to'         => $to,
        'notes'                => $data['notes'] ?? null,
        'active'               => isset($data['active']) ? (int) $data['active'] : 1,
    ];
    if ($existing) {
        scopedUpdate('accounting_entity_relationships', (int) $existing['id'], $row);
        accountingAudit('accounting.consolidation.relationship_updated', ['id' => $existing['id']], (int) $existing['id']);
        _consolidationLogCrossTenant($tenantId, $p, $c, $row, (int) $existing['id'], 'updated');
        return (int) $existing['id'];
    }
    $id = scopedInsert('accounting_entity_relationships', array_merge($row, [
        'parent_entity_id' => $p,
        'child_entity_id'  => $c,
        'effective_from'   => $from,
    ]));
    accountingAudit('accounting.consolidation.relationship_created', [
        'id' => $id, 'parent' => $p, 'child' => $c, 'pct' => $pct, 'method' => $method,
    ], $id);
    _consolidationLogCrossTenant($tenantId, $p, $c, $row, $id, 'created');
    return $id;
}

/**
 * Append a cross-tenant audit row whenever parent/child sit on different
 * tenants. Same-tenant edges are recorded by `accountingAudit()` only and
 * intentionally NOT mirrored here.
 */
function _consolidationLogCrossTenant(
    int $actingTenantId, int $parentEntityId, int $childEntityId,
    array $row, int $relationshipId, string $verb
): void {
    require_once __DIR__ . '/../../../core/cross_tenant_audit.php';
    $pTid = crossTenantAuditEntityTenantId($parentEntityId);
    $cTid = crossTenantAuditEntityTenantId($childEntityId);
    if ($pTid === 0 || $cTid === 0 || $pTid === $cTid) return;

    $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
    crossTenantAuditLog(
        $actingTenantId,
        $pTid,
        $cTid,
        $verb === 'updated'
            ? 'consolidation.edge_updated'
            : 'consolidation.edge_created',
        array_merge($row, [
            'relationship_id'  => $relationshipId,
            'parent_entity_id' => $parentEntityId,
            'child_entity_id'  => $childEntityId,
        ]),
        $parentEntityId,
        $childEntityId,
        $user ? (int) ($user['id'] ?? 0) : null,
        $user ? (string) ($user['email'] ?? $user['name'] ?? '') : null
    );
}

/**
 * Return all descendants of $rootEntityId that are in-scope for consolidation
 * on $asOf, respecting effective_from/effective_to and consolidation_method.
 *
 * Returns array of [entity_id => ['ownership_pct'=>..,'method'=>..,'path'=>[...]]].
 */
function entityRelationshipResolveDescendants(int $tenantId, int $rootEntityId, string $asOf): array
{
    $stmt = getDB()->prepare(
        'SELECT parent_entity_id, child_entity_id, ownership_pct, consolidation_method
         FROM accounting_entity_relationships
         WHERE tenant_id = :t AND active = 1
           AND effective_from <= :asof_lo
           AND (effective_to IS NULL OR effective_to >= :asof_hi)'
    );
    $stmt->execute(['t' => $tenantId, 'asof_lo' => $asOf, 'asof_hi' => $asOf]);
    $edges = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $e) {
        $edges[(int) $e['parent_entity_id']][] = $e;
    }
    $out = [$rootEntityId => ['ownership_pct' => 100.0, 'method' => 'full', 'path' => [$rootEntityId]]];
    $queue = [$rootEntityId];
    while ($queue) {
        $cur = array_shift($queue);
        foreach ($edges[$cur] ?? [] as $edge) {
            $child = (int) $edge['child_entity_id'];
            if (isset($out[$child])) continue; // already visited
            if ($edge['consolidation_method'] === 'none' || $edge['consolidation_method'] === 'cost') continue;
            $out[$child] = [
                'ownership_pct' => (float) $edge['ownership_pct'],
                'method'        => $edge['consolidation_method'],
                'path'          => array_merge($out[$cur]['path'], [$child]),
            ];
            $queue[] = $child;
        }
    }
    return $out;
}

/**
 * Build consolidated trial balance across a set of entities, with
 * intercompany eliminations applied.
 *
 * @return array ['rows'=>[...], 'eliminations'=>[...], 'entities'=>[...], 'as_of'=>...]
 */
function consolidateTrialBalance(int $tenantId, array $entityIds, string $asOf): array
{
    if (!$entityIds) throw new \InvalidArgumentException('entityIds[] required');
    $entityIds = array_values(array_unique(array_map('intval', $entityIds)));
    $in = implode(',', $entityIds);

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT a.code, a.name, a.account_type, a.normal_side,
                COALESCE(SUM(l.debit), 0)  AS debit,
                COALESCE(SUM(l.credit), 0) AS credit
         FROM accounting_accounts a
         LEFT JOIN accounting_journal_entry_lines l ON l.account_id = a.id
         LEFT JOIN accounting_journal_entries je ON je.id = l.je_id
         WHERE a.tenant_id = :t_a
           AND (je.id IS NULL OR (
                je.tenant_id = :t_je AND je.status = "posted"
                AND je.posting_date <= :asof
                AND je.entity_id IN (' . $in . ')
           ))
         GROUP BY a.id
         ORDER BY a.code'
    );
    $stmt->execute(['t_a' => $tenantId, 't_je' => $tenantId, 'asof' => $asOf]);
    $raw = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Compute eliminations: for every IC-tagged line where BOTH the source
    // and counterparty entity are in scope, eliminate the debit AND credit
    // on the mapped due-from/due-to accounts.
    $elimStmt = $pdo->prepare(
        'SELECT a.code, a.account_type, a.normal_side,
                COALESCE(SUM(l.debit), 0)  AS debit,
                COALESCE(SUM(l.credit), 0) AS credit,
                COUNT(*) AS line_count
         FROM accounting_journal_entry_lines l
         JOIN accounting_journal_entries je ON je.id = l.je_id
         JOIN accounting_accounts a ON a.id = l.account_id
         WHERE je.tenant_id = :t
           AND je.status = "posted"
           AND je.posting_date <= :asof
           AND je.entity_id IN (' . $in . ')
           AND l.counterparty_entity_id IN (' . $in . ')
         GROUP BY a.id'
    );
    $elimStmt->execute(['t' => $tenantId, 'asof' => $asOf]);
    $eliminations = $elimStmt->fetchAll(\PDO::FETCH_ASSOC);
    $elimByCode = [];
    foreach ($eliminations as $e) {
        $elimByCode[$e['code']] = [
            'debit'      => (float) $e['debit'],
            'credit'     => (float) $e['credit'],
            'line_count' => (int)   $e['line_count'],
        ];
    }

    // Apply eliminations to raw TB: subtract elim debits from debits and
    // elim credits from credits on the same account.
    $rows = [];
    foreach ($raw as $r) {
        $code = $r['code'];
        $gross = [
            'debit'  => (float) $r['debit'],
            'credit' => (float) $r['credit'],
        ];
        $elim = $elimByCode[$code] ?? ['debit' => 0, 'credit' => 0, 'line_count' => 0];
        $net = [
            'debit'  => round($gross['debit']  - $elim['debit'],  2),
            'credit' => round($gross['credit'] - $elim['credit'], 2),
        ];
        $signed = $r['normal_side'] === 'debit'
            ? round($net['debit']  - $net['credit'],  2)
            : round($net['credit'] - $net['debit'],   2);
        if (abs($signed) < 0.005 && ($gross['debit'] + $gross['credit']) < 0.005) continue;
        $rows[] = [
            'code'           => $code,
            'name'           => $r['name'],
            'account_type'   => $r['account_type'],
            'normal_side'    => $r['normal_side'],
            'debit_gross'    => $gross['debit'],
            'credit_gross'   => $gross['credit'],
            'debit_elim'     => $elim['debit'],
            'credit_elim'    => $elim['credit'],
            'debit_net'      => $net['debit'],
            'credit_net'     => $net['credit'],
            'balance_signed' => $signed,
        ];
    }

    return [
        'as_of'        => $asOf,
        'entities'     => $entityIds,
        'rows'         => $rows,
        'eliminations' => $eliminations,
    ];
}

/**
 * Consolidation with per-entity method weighting + CTA.
 *
 * Spec re-audit decision (2026-02): "Consolidation supports both
 * equity method and proportionate consolidation, plus CTA postings."
 *
 * The legacy `consolidateTrialBalance()` implicitly applies the
 * `full` method to every entity in scope. This variant reads the
 * actual `consolidation_method` + `ownership_pct` per entity from
 * `accounting_entity_relationships` and applies the appropriate
 * treatment:
 *
 *   - 'full'         : 100% line-by-line pickup (current default).
 *   - 'proportionate': line-by-line pickup scaled by ownership_pct
 *                      (each child's debit/credit × pct/100).
 *   - 'equity'       : child's lines EXCLUDED from line-by-line
 *                      pickup; investor records a single equity-
 *                      pickup synthetic row (Investment in
 *                      subsidiary on the BS, Equity-method income
 *                      on the IS) sized at pct × child's net income.
 *   - 'cost'/'none'  : excluded (matches legacy semantics).
 *
 * CTA (Cumulative Translation Adjustment): when an entity carries
 * a `functional_currency` different from the consolidation reporting
 * currency, this function emits a synthetic CTA equity row capturing
 * the delta from translating BS at the closing rate vs IS at the
 * average rate. v1.0 scope ships the framework — the FX rate inputs
 * come from `accounting_fx_rates` (if present) or default to 1.0 so
 * single-currency tenants are unaffected. Multi-period CTA roll-
 * forward is deferred to v1.1.
 *
 * @param array{root_entity_id?:int, reporting_currency?:string} $opts
 * @return array ['as_of', 'entities', 'rows', 'eliminations',
 *                'method_treatments', 'cta_adjustments']
 */
function consolidateTrialBalanceWithMethods(
    int $tenantId,
    array $entityIds,
    string $asOf,
    array $opts = []
): array {
    if (!$entityIds) throw new \InvalidArgumentException('entityIds[] required');
    $entityIds = array_values(array_unique(array_map('intval', $entityIds)));
    $rootEntityId    = (int) ($opts['root_entity_id'] ?? $entityIds[0]);
    $reportingCcy    = (string) ($opts['reporting_currency'] ?? 'USD');

    // Resolve per-entity method + weight from the relationship graph.
    $weights = consolidationComputeEntityWeights($tenantId, $rootEntityId, $entityIds);

    // Buckets per treatment.
    $fullProp = [];   // entities with full or proportionate
    $equity   = [];   // entities under equity method (excluded from line-by-line)
    foreach ($entityIds as $eid) {
        $w = $weights[$eid] ?? ['method' => 'full', 'weight' => 1.0, 'excluded' => false];
        if ($w['excluded']) continue;
        if ($w['method'] === 'equity') $equity[$eid] = $w;
        else                           $fullProp[$eid] = $w;
    }

    // Aggregate per-entity TB rows with weights applied (full=1.0,
    // proportionate=pct/100). Equity entities are excluded here.
    $rows = [];
    $treatments = [];
    if ($fullProp) {
        $rows = _consolidationPerEntityWeightedTB(
            $tenantId, array_keys($fullProp), $asOf, $fullProp
        );
        foreach ($fullProp as $eid => $w) {
            $treatments[] = [
                'entity_id' => $eid, 'method' => $w['method'],
                'ownership_pct' => round($w['weight'] * 100, 4),
            ];
        }
    }

    // Equity-method pickup — synthetic rows ONLY (no line-by-line).
    foreach ($equity as $eid => $w) {
        $pickup = _consolidationEquityPickup($tenantId, $eid, $asOf, $w['weight']);
        if ($pickup !== null) {
            $rows[] = $pickup;
        }
        $treatments[] = [
            'entity_id' => $eid, 'method' => 'equity',
            'ownership_pct' => round($w['weight'] * 100, 4),
            'pickup_amount' => $pickup['balance_signed'] ?? 0,
        ];
    }

    // CTA — emit per-entity CTA equity adjustment for any entity
    // whose functional currency differs from the reporting currency.
    $ctaAdjustments = _consolidationApplyCTA($tenantId, $entityIds, $asOf, $reportingCcy, $rows);

    return [
        'as_of'             => $asOf,
        'entities'          => $entityIds,
        'rows'              => $rows,
        'method_treatments' => $treatments,
        'cta_adjustments'   => $ctaAdjustments,
        'reporting_currency'=> $reportingCcy,
    ];
}

/**
 * Per-entity (method, weight, excluded) lookup. Walks the relationship
 * graph rooted at $rootEntityId; the root itself always carries full.
 * Entities NOT reachable from root via active edges return method='full',
 * weight=1.0 (treat as standalone).
 *
 * @return array<int, array{method:string, weight:float, excluded:bool}>
 */
function consolidationComputeEntityWeights(int $tenantId, int $rootEntityId, array $entityIds): array {
    $descendants = entityRelationshipResolveDescendants($tenantId, $rootEntityId, date('Y-m-d'));
    $out = [];
    foreach ($entityIds as $eid) {
        $eid = (int) $eid;
        if ($eid === $rootEntityId) {
            $out[$eid] = ['method' => 'full', 'weight' => 1.0, 'excluded' => false];
            continue;
        }
        $d = $descendants[$eid] ?? null;
        if ($d === null) {
            $out[$eid] = ['method' => 'full', 'weight' => 1.0, 'excluded' => false];
            continue;
        }
        $m   = (string) ($d['method'] ?? 'full');
        $pct = (float) ($d['ownership_pct'] ?? 100.0);
        $w   = max(0.0, min(1.0, $pct / 100.0));
        $excluded = in_array($m, ['cost','none'], true);
        $out[$eid] = ['method' => $m, 'weight' => $w, 'excluded' => $excluded];
    }
    return $out;
}

/** Internal — per-entity TB rows weighted and re-aggregated. */
function _consolidationPerEntityWeightedTB(int $tenantId, array $entityIds, string $asOf, array $weights): array {
    if (!$entityIds) return [];
    $in = implode(',', array_map('intval', $entityIds));
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT je.entity_id, a.code, a.name, a.account_type, a.normal_side,
                COALESCE(SUM(l.debit), 0)  AS debit,
                COALESCE(SUM(l.credit), 0) AS credit
         FROM accounting_journal_entry_lines l
         JOIN accounting_journal_entries je ON je.id = l.je_id
         JOIN accounting_accounts a ON a.id = l.account_id
         WHERE je.tenant_id = :t
           AND je.status = "posted"
           AND je.posting_date <= :asof
           AND je.entity_id IN (' . $in . ')
         GROUP BY je.entity_id, a.id'
    );
    $stmt->execute(['t' => $tenantId, 'asof' => $asOf]);
    $raw = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    // Apply per-entity weight, then re-aggregate per account code.
    $byCode = [];
    foreach ($raw as $r) {
        $eid    = (int) $r['entity_id'];
        $weight = (float) ($weights[$eid]['weight'] ?? 1.0);
        $code   = (string) $r['code'];
        if (!isset($byCode[$code])) {
            $byCode[$code] = [
                'code'         => $code,
                'name'         => $r['name'],
                'account_type' => $r['account_type'],
                'normal_side'  => $r['normal_side'],
                'debit'        => 0.0,
                'credit'       => 0.0,
            ];
        }
        $byCode[$code]['debit']  += (float) $r['debit']  * $weight;
        $byCode[$code]['credit'] += (float) $r['credit'] * $weight;
    }
    $rows = [];
    foreach ($byCode as $r) {
        $signed = $r['normal_side'] === 'debit'
            ? round($r['debit']  - $r['credit'], 2)
            : round($r['credit'] - $r['debit'], 2);
        if (abs($signed) < 0.005) continue;
        $rows[] = array_merge($r, [
            'debit'          => round($r['debit'], 2),
            'credit'         => round($r['credit'], 2),
            'balance_signed' => $signed,
        ]);
    }
    return $rows;
}

/**
 * Build the synthetic equity-pickup row for one investee entity.
 * Returns null if the entity has no income to pick up.
 *
 * Sizing: pickup = ownership_pct × investee's net income from
 * posted JEs as of $asOf. Surfaces as an `equity_pickup` synthetic
 * row on the consolidated TB tagged with the investee's id.
 */
function _consolidationEquityPickup(int $tenantId, int $entityId, string $asOf, float $weight): ?array {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT a.account_type,
                COALESCE(SUM(l.debit), 0)  AS debit,
                COALESCE(SUM(l.credit), 0) AS credit
         FROM accounting_journal_entry_lines l
         JOIN accounting_journal_entries je ON je.id = l.je_id
         JOIN accounting_accounts a ON a.id = l.account_id
         WHERE je.tenant_id = :t AND je.entity_id = :eid
           AND je.status = "posted" AND je.posting_date <= :asof
           AND a.account_type IN ("revenue","expense","cogs")
         GROUP BY a.account_type'
    );
    $stmt->execute(['t' => $tenantId, 'eid' => $entityId, 'asof' => $asOf]);
    $netIncome = 0.0;
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
        $bal = (float) $r['credit'] - (float) $r['debit']; // revenue side
        if ($r['account_type'] === 'revenue') $netIncome += $bal;
        else $netIncome -= $bal; // expenses/cogs reduce NI
    }
    $pickup = round($netIncome * $weight, 2);
    if (abs($pickup) < 0.005) return null;
    return [
        'code'           => 'EQUITY_PICKUP:' . $entityId,
        'name'           => "Equity-method pickup from entity #{$entityId}",
        'account_type'   => 'equity',
        'normal_side'    => 'credit',
        'debit'          => 0.0,
        'credit'         => 0.0,
        'balance_signed' => $pickup,
        'synthetic'      => 'equity_pickup',
        'source_entity_id' => $entityId,
    ];
}

/**
 * CTA — emit one synthetic equity row per foreign-currency entity
 * capturing the delta between BS-at-closing-rate and IS-at-average-
 * rate translations. v1.0 stub: reads `accounting_fx_rates` if
 * present (columns: tenant_id, currency, as_of, rate_to_usd) and
 * emits a CTA row only when the entity's functional_currency
 * differs from the reporting currency. Single-currency tenants are
 * unaffected — the helper returns an empty list.
 *
 * @return array list of CTA adjustment dicts also appended to $rows.
 */
function _consolidationApplyCTA(int $tenantId, array $entityIds, string $asOf, string $reportingCcy, array &$rows): array {
    $adjustments = [];
    if (!$entityIds) return $adjustments;
    try {
        $pdo = getDB();
        $in = implode(',', array_map('intval', $entityIds));
        $st = $pdo->prepare(
            "SELECT id, legal_name, functional_currency
               FROM accounting_entities
              WHERE tenant_id = :t AND id IN ({$in})"
        );
        $st->execute(['t' => $tenantId]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $e) {
            $ccy = (string) ($e['functional_currency'] ?? $reportingCcy);
            if ($ccy === $reportingCcy || $ccy === '') continue;
            // Look up FX rates — closing (as_of) and average (heuristic).
            $rateClose = _consolidationLookupFxRate($tenantId, $ccy, $reportingCcy, $asOf);
            $rateAvg   = _consolidationLookupFxRate($tenantId, $ccy, $reportingCcy, date('Y-m-01', strtotime($asOf)));
            if ($rateClose === null || $rateAvg === null) continue;
            $delta = $rateClose - $rateAvg;
            if (abs($delta) < 0.000001) continue;
            $row = [
                'code'           => 'CTA:' . (int) $e['id'],
                'name'           => "CTA — {$e['legal_name']} ({$ccy}→{$reportingCcy})",
                'account_type'   => 'equity',
                'normal_side'    => 'credit',
                'debit'          => 0.0,
                'credit'         => 0.0,
                'balance_signed' => 0.0, // sized by caller's translated balances in v1.1
                'synthetic'      => 'cta',
                'source_entity_id' => (int) $e['id'],
                'currency_from'  => $ccy,
                'currency_to'    => $reportingCcy,
                'rate_close'     => $rateClose,
                'rate_average'   => $rateAvg,
            ];
            $rows[]        = $row;
            $adjustments[] = $row;
        }
    } catch (\Throwable $e) {
        // accounting_fx_rates table missing or schema drift — soft degrade.
        // Single-currency tenants don't need CTA; this branch is a no-op.
    }
    return $adjustments;
}

function _consolidationLookupFxRate(int $tenantId, string $from, string $to, string $asOf): ?float {
    try {
        $pdo = getDB();
        $st = $pdo->prepare(
            'SELECT rate FROM accounting_fx_rates
              WHERE tenant_id = :t AND currency_from = :f AND currency_to = :to AND as_of <= :asof
              ORDER BY as_of DESC LIMIT 1'
        );
        $st->execute(['t' => $tenantId, 'f' => $from, 'to' => $to, 'asof' => $asOf]);
        $r = $st->fetchColumn();
        return $r !== false ? (float) $r : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function consolidateIncomeStatement(int $tenantId, array $entityIds, string $from, string $to): array
{
    // Same pattern as reportIncomeStatement but multi-entity + eliminations.
    if (!$entityIds) throw new \InvalidArgumentException('entityIds[] required');
    $entityIds = array_values(array_unique(array_map('intval', $entityIds)));
    $in = implode(',', $entityIds);
    $pdo = getDB();

    $stmt = $pdo->prepare(
        'SELECT a.code, a.name, a.account_type, a.normal_side,
                COALESCE(SUM(l.debit),  0) AS debit,
                COALESCE(SUM(l.credit), 0) AS credit
         FROM accounting_accounts a
         LEFT JOIN accounting_journal_entry_lines l ON l.account_id = a.id
         LEFT JOIN accounting_journal_entries je ON je.id = l.je_id
         WHERE a.tenant_id = :t_a
           AND a.account_type IN ("revenue","expense")
           AND (je.id IS NULL OR (
                je.tenant_id = :t_je AND je.status = "posted"
                AND je.posting_date >= :f AND je.posting_date <= :tx
                AND je.entity_id IN (' . $in . ')
           ))
         GROUP BY a.id'
    );
    $stmt->execute(['t_a' => $tenantId, 't_je' => $tenantId, 'f' => $from, 'tx' => $to]);
    $raw = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    $elimStmt = $pdo->prepare(
        'SELECT a.code, COALESCE(SUM(l.debit),0) AS debit, COALESCE(SUM(l.credit),0) AS credit
         FROM accounting_journal_entry_lines l
         JOIN accounting_journal_entries je ON je.id = l.je_id
         JOIN accounting_accounts a ON a.id = l.account_id
         WHERE je.tenant_id = :t AND je.status = "posted"
           AND je.posting_date >= :f AND je.posting_date <= :tx
           AND je.entity_id IN (' . $in . ')
           AND l.counterparty_entity_id IN (' . $in . ')
           AND a.account_type IN ("revenue","expense")
         GROUP BY a.id'
    );
    $elimStmt->execute(['t' => $tenantId, 'f' => $from, 'tx' => $to]);
    $elim = [];
    foreach ($elimStmt->fetchAll(\PDO::FETCH_ASSOC) as $e) {
        $elim[$e['code']] = ['debit' => (float) $e['debit'], 'credit' => (float) $e['credit']];
    }

    $revenue = []; $expense = []; $revTotal = 0; $expTotal = 0;
    foreach ($raw as $r) {
        $d = (float) $r['debit']; $c = (float) $r['credit'];
        $e = $elim[$r['code']] ?? ['debit' => 0, 'credit' => 0];
        $netDebit  = $d - $e['debit'];
        $netCredit = $c - $e['credit'];
        $bal = $r['normal_side'] === 'debit' ? round($netDebit - $netCredit, 2) : round($netCredit - $netDebit, 2);
        $r['amount']     = $bal;
        $r['amount_elim']= round(($r['normal_side'] === 'debit') ? ($e['debit'] - $e['credit']) : ($e['credit'] - $e['debit']), 2);
        if ($r['account_type'] === 'revenue') { $revenue[] = $r; $revTotal += $bal; }
        else                                  { $expense[] = $r; $expTotal += $bal; }
    }
    return [
        'period'         => ['from' => $from, 'to' => $to, 'entity_ids' => $entityIds],
        'revenue'        => $revenue,
        'expense'        => $expense,
        'total_revenue'  => round($revTotal, 2),
        'total_expense'  => round($expTotal, 2),
        'net_income'     => round($revTotal - $expTotal, 2),
        'is_consolidated'=> true,
    ];
}

function consolidateBalanceSheet(int $tenantId, array $entityIds, string $asOf): array
{
    $tb = consolidateTrialBalance($tenantId, $entityIds, $asOf);
    $assets = []; $liab = []; $equity = [];
    $ta = 0; $tl = 0; $te = 0;
    foreach ($tb['rows'] as $r) {
        if ($r['account_type'] === 'asset')      { $assets[] = $r; $ta += $r['balance_signed']; }
        elseif ($r['account_type'] === 'liability'){ $liab[] = $r;   $tl += $r['balance_signed']; }
        elseif ($r['account_type'] === 'equity')  { $equity[] = $r; $te += $r['balance_signed']; }
    }

    // ── Non-controlling interest (NCI) breakout ───────────────────────
    // For any child entity in scope whose effective ownership_pct < 100,
    // carve (100 - pct)% of that child's standalone equity into
    // nci_equity and shrink controlling equity by the same amount.
    $nciEquity = 0.0;
    $nciDetail = [];
    $db = getDB();
    foreach ($entityIds as $eid) {
        $edgeStmt = $db->prepare(
            'SELECT ownership_pct, consolidation_method
             FROM accounting_entity_relationships
             WHERE tenant_id = :t AND child_entity_id = :c AND active = 1
               AND effective_from <= :asof_lo
               AND (effective_to IS NULL OR effective_to >= :asof_hi)
             ORDER BY effective_from DESC LIMIT 1'
        );
        $edgeStmt->execute(['t' => $tenantId, 'c' => (int) $eid, 'asof_lo' => $asOf, 'asof_hi' => $asOf]);
        $edge = $edgeStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$edge) continue;
        $pct = (float) $edge['ownership_pct'];
        if ($pct >= 100.0 || $edge['consolidation_method'] !== 'full') continue;

        // Child's standalone equity = sum of equity accounts on its books.
        $eqStmt = $db->prepare(
            'SELECT COALESCE(SUM(
                CASE WHEN a.normal_side = "credit" THEN l.credit - l.debit ELSE l.debit - l.credit END
             ), 0) AS bal
             FROM accounting_journal_entry_lines l
             JOIN accounting_journal_entries je ON je.id = l.je_id
             JOIN accounting_accounts a ON a.id = l.account_id
             WHERE je.tenant_id = :t AND je.status = "posted"
               AND je.posting_date <= :asof
               AND je.entity_id = :e
               AND a.account_type = "equity"'
        );
        $eqStmt->execute(['t' => $tenantId, 'asof' => $asOf, 'e' => (int) $eid]);
        $childEq = (float) ($eqStmt->fetch(\PDO::FETCH_ASSOC)['bal'] ?? 0);
        $nci = round($childEq * (100 - $pct) / 100.0, 2);
        if (abs($nci) < 0.005) continue;
        $nciEquity += $nci;
        $nciDetail[] = [
            'entity_id'         => (int) $eid,
            'ownership_pct'     => $pct,
            'standalone_equity' => round($childEq, 2),
            'nci_amount'        => $nci,
        ];
    }
    $controllingEquity = round($te - $nciEquity, 2);

    return [
        'as_of'             => $asOf,
        'entities'          => $entityIds,
        'assets'            => $assets,
        'liabilities'       => $liab,
        'equity'            => $equity,
        'total_assets'      => round($ta, 2),
        'total_liabilities' => round($tl, 2),
        'total_equity'      => round($te, 2),
        'controlling_equity'=> $controllingEquity,
        'nci_equity'        => round($nciEquity, 2),
        'nci_detail'        => $nciDetail,
        'eliminations'      => $tb['eliminations'],
        'is_consolidated'   => true,
    ];
}

// =======================================================================
// Consolidation run snapshots — "Lock & publish" workflow
// =======================================================================

/**
 * Compute the consolidated payload and persist a locked snapshot.
 * Returns the new run id + the payload that was locked.
 */
function consolidationLockRun(int $tenantId, array $input, ?int $actorUserId): array
{
    $type      = (string) ($input['report_type'] ?? '');
    if (!in_array($type, ['income_statement','balance_sheet','trial_balance'], true)) {
        throw new \InvalidArgumentException('report_type must be income_statement|balance_sheet|trial_balance');
    }
    $entityIds = array_values(array_unique(array_map('intval', $input['entity_ids'] ?? [])));
    if (!empty($input['root_entity_id']) && !$entityIds) {
        $tree = entityRelationshipResolveDescendants($tenantId, (int) $input['root_entity_id'], (string) ($input['period_to'] ?? date('Y-m-d')));
        $entityIds = array_map('intval', array_keys($tree));
    }
    if (!$entityIds) throw new \InvalidArgumentException('entity_ids[] (or root_entity_id) required');

    $from = !empty($input['period_from']) ? (string) $input['period_from'] : null;
    $to   = (string) ($input['period_to'] ?? date('Y-m-d'));

    // Compute the payload using the same engine the UI uses.
    if     ($type === 'income_statement') $payload = consolidateIncomeStatement($tenantId, $entityIds, $from ?: date('Y-01-01'), $to);
    elseif ($type === 'balance_sheet')    $payload = consolidateBalanceSheet($tenantId, $entityIds, $to);
    else                                  $payload = consolidateTrialBalance($tenantId, $entityIds, $to);

    $db = getDB();
    $db->prepare(
        'INSERT INTO accounting_consolidation_runs
            (tenant_id, report_type, period_from, period_to, entity_ids_json,
             root_entity_id, payload_json, status, locked_at, locked_by_user_id, notes)
         VALUES
            (:t, :rt, :pf, :pt, :eids, :root, :pj, "locked", NOW(), :u, :notes)'
    )->execute([
        't'     => $tenantId,
        'rt'    => $type,
        'pf'    => $from,
        'pt'    => $to,
        'eids'  => json_encode($entityIds),
        'root'  => !empty($input['root_entity_id']) ? (int) $input['root_entity_id'] : null,
        'pj'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'u'     => $actorUserId,
        'notes' => $input['notes'] ?? null,
    ]);
    $id = (int) $db->lastInsertId();
    accountingAudit('accounting.consolidation.run_locked', [
        'run_id'      => $id, 'report_type' => $type, 'period_to' => $to,
        'entity_ids'  => $entityIds,
    ], $id);
    return ['id' => $id, 'payload' => $payload];
}

/**
 * Reverse a locked run. Used automatically when a period is reopened.
 */
function consolidationReverseRun(int $tenantId, int $runId, string $reason, ?int $actorUserId): void
{
    if (trim($reason) === '') throw new \InvalidArgumentException('reason required');
    $row = scopedFind('SELECT * FROM accounting_consolidation_runs WHERE tenant_id = :tenant_id AND id = :id', ['id' => $runId]);
    if (!$row) throw new \RuntimeException('Run not found');
    if ($row['status'] !== 'locked') throw new \RuntimeException("Cannot reverse from status {$row['status']}");
    getDB()->prepare(
        'UPDATE accounting_consolidation_runs
         SET status = "reversed", reversed_at = NOW(), reversed_by_user_id = :u, reverse_reason = :r
         WHERE id = :id AND tenant_id = :t'
    )->execute(['u' => $actorUserId, 'r' => $reason, 'id' => $runId, 't' => $tenantId]);
    accountingAudit('accounting.consolidation.run_reversed', ['run_id' => $runId, 'reason' => $reason], $runId);
}

function consolidationListRuns(int $tenantId, ?string $reportType = null): array
{
    $where  = ['tenant_id = :tenant_id']; $params = [];
    if ($reportType) { $where[] = 'report_type = :rt'; $params['rt'] = $reportType; }
    return scopedQuery(
        'SELECT id, report_type, period_from, period_to, entity_ids_json, root_entity_id,
                status, locked_at, locked_by_user_id, reversed_at, reversed_by_user_id,
                reverse_reason, ai_narrative_generated_at
         FROM accounting_consolidation_runs
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY period_to DESC, id DESC LIMIT 100',
        $params
    );
}

function consolidationGetRun(int $tenantId, int $runId): ?array
{
    $row = scopedFind('SELECT * FROM accounting_consolidation_runs WHERE tenant_id = :tenant_id AND id = :id', ['id' => $runId]);
    if (!$row) return null;
    $row['payload'] = json_decode((string) $row['payload_json'], true);
    unset($row['payload_json']);
    $row['entity_ids'] = json_decode((string) $row['entity_ids_json'], true);
    unset($row['entity_ids_json']);
    return $row;
}
