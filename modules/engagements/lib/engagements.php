<?php
/**
 * modules/engagements/lib/engagements.php
 *
 * Engagement = a fixed-fee project. Holds one or more milestones; each
 * milestone independently transitions through the invoicing lifecycle.
 *
 * Lifecycle of an engagement:
 *   draft     — operator is still scoping the work; no invoices yet
 *   active    — work is in progress; milestones get invoiced as they
 *               flip `ready_to_invoice` → `invoiced`
 *   completed — all non-cancelled milestones are `paid`; computed
 *               automatically when the last milestone settles
 *   archived  — closed; read-only
 *
 * Money rollups:
 *   total_fee       — declared scope (sum of milestone.amount + any
 *                     uncommitted budget)
 *   invoiced_amount — sum of milestones with status IN (invoiced, paid)
 *   paid_amount     — sum of milestones with status = paid
 *
 * Tenant safety: every read/write call accepts an explicit $tenantId
 * argument and filters by it. No global SELECTs.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';

const ENGAGEMENT_STATUSES = ['draft', 'active', 'completed', 'archived'];
const MILESTONE_STATUSES  = ['pending', 'ready_to_invoice', 'invoiced', 'paid', 'cancelled'];

// ─────────────────────────────────────────────────────────────────────
// Engagement CRUD
// ─────────────────────────────────────────────────────────────────────

/**
 * List engagements for a tenant. Optional filters: status, entity_id,
 * client_name (LIKE prefix).
 *
 * @param array{status?:string,entity_id?:int,client?:string,limit?:int,offset?:int} $filters
 */
function engagementsList(int $tenantId, array $filters = []): array
{
    $where  = ['tenant_id = :t'];
    $params = ['t' => $tenantId];
    if (!empty($filters['status']) && in_array($filters['status'], ENGAGEMENT_STATUSES, true)) {
        $where[] = 'status = :s';
        $params['s'] = $filters['status'];
    }
    if (!empty($filters['entity_id'])) {
        $where[] = 'entity_id = :e';
        $params['e'] = (int) $filters['entity_id'];
    }
    if (!empty($filters['client'])) {
        $where[] = 'client_name LIKE :c';
        $params['c'] = $filters['client'] . '%';
    }
    $limit  = max(1, min(500, (int) ($filters['limit']  ?? 100)));
    $offset = max(0, (int) ($filters['offset'] ?? 0));

    $sql = 'SELECT id, tenant_id, entity_id, client_name, project_name, currency,
                   total_fee, invoiced_amount, paid_amount, status,
                   start_date, end_date, archived_at, created_at, updated_at
              FROM engagements
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY status = "active" DESC, updated_at DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset;
    $st = getDB()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(\PDO::FETCH_ASSOC);
}

function engagementsGet(int $tenantId, int $id): ?array
{
    $st = getDB()->prepare(
        'SELECT * FROM engagements WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    $st->execute(['t' => $tenantId, 'id' => $id]);
    $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    if (!$row) return null;
    $row['milestones'] = engagementsMilestonesList($tenantId, $id);
    return $row;
}

/**
 * Create a new engagement. Returns the new id.
 *
 * @param array{client_name:string, project_name:string, total_fee?:float,
 *              currency?:string, entity_id?:int, start_date?:string,
 *              end_date?:string, description?:string, notes?:string,
 *              milestones?: array<int, array{name:string,amount:float,target_date?:string,description?:string}>} $input
 */
function engagementsCreate(int $tenantId, array $input, ?int $actorUserId = null): int
{
    if (empty($input['client_name']))  throw new \InvalidArgumentException('client_name required');
    if (empty($input['project_name'])) throw new \InvalidArgumentException('project_name required');

    $pdo = getDB();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO engagements
                (tenant_id, entity_id, client_name, project_name, description,
                 currency, total_fee, status, start_date, end_date, notes,
                 created_by_user_id, created_at, updated_at)
             VALUES
                (:t, :e, :cn, :pn, :ds,
                 :cur, :tf, :st, :sd, :ed, :n,
                 :u, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        )->execute([
            't'  => $tenantId,
            'e'  => !empty($input['entity_id']) ? (int) $input['entity_id'] : null,
            'cn' => $input['client_name'],
            'pn' => $input['project_name'],
            'ds' => $input['description'] ?? null,
            'cur'=> strtoupper((string) ($input['currency'] ?? 'USD')),
            'tf' => round((float) ($input['total_fee'] ?? 0), 2),
            'st' => $input['status'] ?? 'draft',
            'sd' => $input['start_date'] ?? null,
            'ed' => $input['end_date'] ?? null,
            'n'  => $input['notes'] ?? null,
            'u'  => $actorUserId,
        ]);
        $id = (int) $pdo->lastInsertId();

        // Bulk-insert milestones if provided.
        $ms = $input['milestones'] ?? [];
        $sortOrder = 0;
        foreach ($ms as $m) {
            if (empty($m['name'])) continue;
            $pdo->prepare(
                'INSERT INTO engagement_milestones
                    (engagement_id, tenant_id, sort_order, name, description,
                     amount, target_date, status, created_at, updated_at)
                 VALUES
                    (:eg, :t, :so, :n, :ds, :am, :td, "pending",
                     CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
            )->execute([
                'eg' => $id,
                't'  => $tenantId,
                'so' => $sortOrder++,
                'n'  => $m['name'],
                'ds' => $m['description'] ?? null,
                'am' => round((float) ($m['amount'] ?? 0), 2),
                'td' => $m['target_date'] ?? null,
            ]);
        }

        engagementsAudit($tenantId, 'created', $id, null, [
            'project_name' => $input['project_name'],
            'milestones'   => count($ms),
        ], $actorUserId);

        _engagementsRecalcRollups($tenantId, $id);
        $pdo->commit();
        return $id;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Patch top-level engagement fields. Only whitelisted columns. */
function engagementsUpdate(int $tenantId, int $id, array $patch, ?int $actorUserId = null): void
{
    $cur = engagementsGet($tenantId, $id);
    if (!$cur) throw new \RuntimeException('Engagement not found');
    if ($cur['status'] === 'archived') {
        throw new \RuntimeException('Archived engagements are read-only');
    }
    $cols = [];
    $bind = ['id' => $id, 't' => $tenantId];
    $allowed = ['client_name','project_name','description','currency','total_fee','status',
                'start_date','end_date','notes','entity_id'];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $patch)) {
            if ($k === 'status' && !in_array($patch[$k], ENGAGEMENT_STATUSES, true)) {
                throw new \InvalidArgumentException("invalid status: {$patch[$k]}");
            }
            $cols[] = "{$k} = :{$k}";
            $bind[$k] = $patch[$k];
        }
    }
    if (!$cols) return;
    $bind['archived_at'] = ($patch['status'] ?? null) === 'archived' ? date('Y-m-d H:i:s') : ($cur['archived_at'] ?? null);
    $cols[] = 'archived_at = :archived_at';
    $cols[] = 'updated_at = CURRENT_TIMESTAMP';

    getDB()->prepare(
        'UPDATE engagements SET ' . implode(', ', $cols) . ' WHERE tenant_id = :t AND id = :id'
    )->execute($bind);

    engagementsAudit($tenantId, 'updated', $id, null, ['patch_keys' => array_keys($patch)], $actorUserId);
    _engagementsRecalcRollups($tenantId, $id);
}

function engagementsArchive(int $tenantId, int $id, ?int $actorUserId = null): void
{
    engagementsUpdate($tenantId, $id, ['status' => 'archived'], $actorUserId);
}

// ─────────────────────────────────────────────────────────────────────
// Milestones
// ─────────────────────────────────────────────────────────────────────

function engagementsMilestonesList(int $tenantId, int $engagementId): array
{
    $st = getDB()->prepare(
        'SELECT id, engagement_id, sort_order, name, description, amount,
                target_date, status, invoice_id, completed_at, invoiced_at,
                paid_at, notes, created_at, updated_at
           FROM engagement_milestones
          WHERE tenant_id = :t AND engagement_id = :eg
          ORDER BY sort_order ASC, id ASC'
    );
    $st->execute(['t' => $tenantId, 'eg' => $engagementId]);
    return $st->fetchAll(\PDO::FETCH_ASSOC);
}

function engagementsMilestoneCreate(int $tenantId, int $engagementId, array $input, ?int $actorUserId = null): int
{
    if (empty($input['name'])) throw new \InvalidArgumentException('name required');
    $eg = engagementsGet($tenantId, $engagementId);
    if (!$eg) throw new \RuntimeException('Engagement not found');
    if ($eg['status'] === 'archived') throw new \RuntimeException('Archived engagements are read-only');

    $maxStmt = getDB()->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM engagement_milestones WHERE tenant_id = :t AND engagement_id = :eg');
    $maxStmt->execute(['t' => $tenantId, 'eg' => $engagementId]);
    $sortOrder = (int) $maxStmt->fetchColumn();

    getDB()->prepare(
        'INSERT INTO engagement_milestones
            (engagement_id, tenant_id, sort_order, name, description, amount,
             target_date, status, created_at, updated_at)
         VALUES (:eg, :t, :so, :n, :ds, :am, :td, "pending",
                 CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
    )->execute([
        'eg' => $engagementId, 't' => $tenantId, 'so' => $sortOrder,
        'n'  => $input['name'], 'ds' => $input['description'] ?? null,
        'am' => round((float) ($input['amount'] ?? 0), 2),
        'td' => $input['target_date'] ?? null,
    ]);
    $msId = (int) getDB()->lastInsertId();

    engagementsAudit($tenantId, 'milestone_created', $engagementId, $msId, ['name' => $input['name'], 'amount' => $input['amount'] ?? 0], $actorUserId);
    _engagementsRecalcRollups($tenantId, $engagementId);
    return $msId;
}

function engagementsMilestoneUpdate(int $tenantId, int $milestoneId, array $patch, ?int $actorUserId = null): void
{
    $cur = _engagementsMilestoneGet($tenantId, $milestoneId);
    if (!$cur) throw new \RuntimeException('Milestone not found');

    $cols = [];
    $bind = ['t' => $tenantId, 'id' => $milestoneId];
    foreach (['name','description','amount','target_date','sort_order','notes'] as $k) {
        if (array_key_exists($k, $patch)) {
            $cols[] = "{$k} = :{$k}";
            $bind[$k] = $patch[$k];
        }
    }
    if (array_key_exists('status', $patch)) {
        $next = (string) $patch['status'];
        if (!in_array($next, MILESTONE_STATUSES, true)) {
            throw new \InvalidArgumentException("invalid milestone status: {$next}");
        }
        if (!_milestoneTransitionAllowed((string) $cur['status'], $next)) {
            throw new \RuntimeException("Illegal transition {$cur['status']} → {$next}");
        }
        $cols[] = 'status = :status'; $bind['status'] = $next;
        if ($next === 'ready_to_invoice')  { $cols[] = 'completed_at = CURRENT_TIMESTAMP'; }
        if ($next === 'paid')              { $cols[] = 'paid_at = CURRENT_TIMESTAMP'; }
    }
    if (!$cols) return;
    $cols[] = 'updated_at = CURRENT_TIMESTAMP';

    getDB()->prepare(
        'UPDATE engagement_milestones SET ' . implode(', ', $cols) . ' WHERE tenant_id = :t AND id = :id'
    )->execute($bind);

    engagementsAudit($tenantId, 'milestone_updated', (int) $cur['engagement_id'], $milestoneId, ['patch_keys' => array_keys($patch)], $actorUserId);
    _engagementsRecalcRollups($tenantId, (int) $cur['engagement_id']);
}

/**
 * Mark a milestone as `invoiced` and link the billing_invoices.id that
 * was just generated for it. Doesn't write the invoice itself — caller
 * (a billing API) does that and hands us the resulting id.
 */
function engagementsMilestoneAttachInvoice(int $tenantId, int $milestoneId, int $invoiceId, ?int $actorUserId = null): void
{
    $cur = _engagementsMilestoneGet($tenantId, $milestoneId);
    if (!$cur) throw new \RuntimeException('Milestone not found');
    if (!in_array($cur['status'], ['pending', 'ready_to_invoice'], true)) {
        throw new \RuntimeException("Cannot attach invoice from {$cur['status']} milestone");
    }
    getDB()->prepare(
        'UPDATE engagement_milestones
            SET status = "invoiced", invoice_id = :inv,
                invoiced_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
          WHERE tenant_id = :t AND id = :id'
    )->execute(['inv' => $invoiceId, 't' => $tenantId, 'id' => $milestoneId]);

    engagementsAudit($tenantId, 'milestone_invoiced', (int) $cur['engagement_id'], $milestoneId, ['invoice_id' => $invoiceId], $actorUserId);
    _engagementsRecalcRollups($tenantId, (int) $cur['engagement_id']);
}

/** Mark a milestone as paid (called when its underlying invoice settles). */
function engagementsMilestoneMarkPaid(int $tenantId, int $milestoneId, ?int $actorUserId = null): void
{
    engagementsMilestoneUpdate($tenantId, $milestoneId, ['status' => 'paid'], $actorUserId);
}

// ─────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────

function _engagementsMilestoneGet(int $tenantId, int $milestoneId): ?array
{
    $st = getDB()->prepare('SELECT * FROM engagement_milestones WHERE tenant_id = :t AND id = :id LIMIT 1');
    $st->execute(['t' => $tenantId, 'id' => $milestoneId]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
}

function _milestoneTransitionAllowed(string $from, string $to): bool
{
    if ($from === $to) return true;
    static $graph = [
        'pending'          => ['ready_to_invoice', 'cancelled'],
        'ready_to_invoice' => ['pending', 'invoiced', 'cancelled'],
        'invoiced'         => ['paid', 'cancelled'],
        'paid'             => [],
        'cancelled'        => ['pending'],
    ];
    return in_array($to, $graph[$from] ?? [], true);
}

/** Recalculate invoiced_amount + paid_amount + status rollups. */
function _engagementsRecalcRollups(int $tenantId, int $engagementId): void
{
    $st = getDB()->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN status IN ("invoiced","paid") THEN amount ELSE 0 END), 0) AS invoiced,
            COALESCE(SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END), 0) AS paid,
            COUNT(*) AS total_count,
            SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) AS cancelled_count
           FROM engagement_milestones
          WHERE tenant_id = :t AND engagement_id = :eg'
    );
    $st->execute(['t' => $tenantId, 'eg' => $engagementId]);
    $r = $st->fetch(\PDO::FETCH_ASSOC) ?: ['invoiced' => 0, 'paid' => 0, 'total_count' => 0, 'paid_count' => 0, 'cancelled_count' => 0];

    $newStatus = null;
    if ($r['total_count'] > 0 && $r['paid_count'] + $r['cancelled_count'] === $r['total_count'] && $r['paid_count'] > 0) {
        // All non-cancelled milestones are paid → mark engagement completed.
        $newStatus = 'completed';
    }

    $bind = ['inv' => round((float) $r['invoiced'], 2), 'pd' => round((float) $r['paid'], 2),
             't' => $tenantId, 'id' => $engagementId];
    $sql = 'UPDATE engagements SET invoiced_amount = :inv, paid_amount = :pd,
                     updated_at = CURRENT_TIMESTAMP';
    if ($newStatus !== null) {
        $sql .= ', status = CASE WHEN status IN ("archived", "completed") THEN status ELSE :ns END';
        $bind['ns'] = $newStatus;
    }
    $sql .= ' WHERE tenant_id = :t AND id = :id';
    getDB()->prepare($sql)->execute($bind);
}

function engagementsAudit(int $tenantId, string $event, ?int $engagementId, ?int $milestoneId, array $meta = [], ?int $actorUserId = null): void
{
    try {
        getDB()->prepare(
            'INSERT INTO engagement_audit_log
                (engagement_id, milestone_id, tenant_id, event, actor_user_id, meta_json, created_at)
             VALUES (:eg, :ms, :t, :ev, :u, :m, CURRENT_TIMESTAMP)'
        )->execute([
            'eg' => $engagementId,
            'ms' => $milestoneId,
            't'  => $tenantId,
            'ev' => $event,
            'u'  => $actorUserId,
            'm'  => json_encode($meta),
        ]);
    } catch (\Throwable $_) { /* audit never blocks the caller */ }
}
