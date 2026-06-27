<?php
/**
 * Placements Module — cross-module read library
 *
 * Other modules (time, accounting, billing, payroll) use these helpers to
 * read placement data without selecting from `placement_*` tables directly.
 *
 * SPEC: /app/modules/placements/SPEC.md §6
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';

/**
 * Allowed values for the placements.remote_policy ENUM. The MySQL column is
 * NOT NULL-able only — it accepts only these three strings or NULL. Empty
 * string '' yields SQLSTATE[01000] 1265 "Data truncated" under strict mode
 * (the frontend's PlacementCreate form initialises the field to '' so the
 * operator can see "—" by default). All write paths MUST coerce via
 * placementsNormalizeRemotePolicy() before INSERT/UPDATE.
 */
const PLACEMENTS_ALLOWED_REMOTE = ['onsite','hybrid','remote'];

function placementsNormalizeRemotePolicy($v): ?string
{
    if ($v === null) return null;
    $v = trim((string) $v);
    if ($v === '') return null;
    return in_array($v, PLACEMENTS_ALLOWED_REMOTE, true) ? $v : null;
}

function placementsSafeFields(string $alias = 'p'): string
{
    $cols = ['id','tenant_id','person_id','external_id','jobdiva_job_id','status','start_date','end_date',
             'actual_end_date','due_date','engagement_type','worksite_state','worksite_country',
             'remote_policy','title','end_client_name','end_client_company_id','client_id','staffing_job_id','notes',
             'recruiter_name','recruiter_email','account_manager_name','account_manager_email',
             'client_approver_name','client_approver_email','tokenized_email_approval_enabled',
             'bulk_uploads_can_be_pre_approved',
             'billing_cycle_id','ap_cycle_id','payroll_cycle_id',
             'client_bill_cycle','client_bill_cycle_anchor','vendor_pay_cycle','vendor_pay_cycle_anchor',
             'created_by_user_id','created_at','updated_at','deleted_at'];
    return implode(', ', array_map(fn($c) => "{$alias}.{$c}", $cols));
}

function placementGet(int $id): ?array
{
    // Single-row GET joins both `people` (so the Overview tab can show
    // the person's actual name + email — operator complaint: "it doesn't
    // even have the NAME?!") and `companies` (so the end-client FK
    // resolves to a clickable, real company row when present). LEFT
    // JOINs are intentional: placements may be drafts without a person
    // assigned yet, and end_client_company_id is nullable when the
    // operator hasn't promoted the free-text name to a Company row.
    //
    // The hand-picked `pe.X AS person_X` aliases below cover the fields
    // the Overview tab needs verbatim. Underneath that, `placementHydratePersonFields()`
    // fans out the FULL row from `people` as `person_*` so any column
    // we add to `people` later (linkedin_url, secondary_email, custom
    // fields, etc.) shows up on placement detail automatically — operator
    // ask: "why isn't it universal? we're still not getting some of the
    // details from people across to placements."
    $sql = 'SELECT ' . placementsSafeFields() . ',
                   pe.first_name        AS person_first_name,
                   pe.last_name         AS person_last_name,
                   pe.email_primary     AS person_email_primary,
                   pe.phone_primary     AS person_phone_primary,
                   pe.classification    AS person_classification,
                   pe.work_auth_status  AS person_work_auth_status,
                   pe.work_auth_expiry  AS person_work_auth_expiry,
                   ec.name              AS end_client_company_name,
                   ec.website           AS end_client_company_website,
                   sj.title             AS staffing_job_title,
                   sj.status            AS staffing_job_status
            FROM placements p
            LEFT JOIN people    pe ON pe.id = p.person_id          AND pe.tenant_id = p.tenant_id
            LEFT JOIN companies ec ON ec.id = p.end_client_company_id AND ec.tenant_id = p.tenant_id
            LEFT JOIN staffing_jobs sj ON sj.id = p.staffing_job_id AND sj.tenant_id = p.tenant_id
            WHERE p.tenant_id = :tenant_id AND p.id = :id AND p.deleted_at IS NULL';
    $row = scopedFind($sql, ['id' => $id]);
    if (!$row) return null;
    return placementHydratePersonFields($row);
}

/**
 * Fan-out every column on the linked `people` row as `person_*` so the
 * placement detail page automatically reflects new person columns with
 * zero schema or UI work. Hand-picked aliases on the parent query still
 * win — we only add keys that aren't already on the row.
 *
 * Intentionally narrow: ONLY pulls from the `people` table for the
 * already-resolved `person_id`. Returns the row unchanged when there is
 * no linked person (draft placement) or the people row is missing.
 */
function placementHydratePersonFields(array $row, ?callable $loader = null): array
{
    if (empty($row['person_id'])) return $row;
    try {
        if ($loader !== null) {
            $person = $loader((int) $row['person_id']);
        } else {
            $person = scopedFind(
                'SELECT * FROM people WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL',
                ['id' => (int) $row['person_id']]
            );
        }
    } catch (\Throwable $e) {
        error_log('[placementHydratePersonFields] ' . $e->getMessage());
        return $row;
    }
    if (!$person) return $row;
    foreach ($person as $k => $v) {
        if ($k === 'id' || $k === 'tenant_id' || $k === 'deleted_at') continue;
        $key = 'person_' . $k;
        if (!array_key_exists($key, $row)) {
            $row[$key] = $v;
        }
    }
    return $row;
}

function placementsList(array $filters = []): array
{
    $where  = ['p.tenant_id = :tenant_id', 'p.deleted_at IS NULL'];
    $params = [];
    if (!empty($filters['q'])) {
        // Distinct placeholders required by PDO_MYSQL native prepares.
        $where[]          = '(p.title LIKE :q OR p.end_client_name LIKE :q2 OR p.external_id = :qexact)';
        $params['q']      = '%' . $filters['q'] . '%';
        $params['q2']     = $params['q'];
        $params['qexact'] = $filters['q'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'p.status = :status';
        $params['status'] = $filters['status'];
    }
    if (!empty($filters['person_id'])) {
        $where[] = 'p.person_id = :person_id';
        $params['person_id'] = (int) $filters['person_id'];
    }
    if (!empty($filters['end_client'])) {
        $where[] = 'p.end_client_name = :end_client';
        $params['end_client'] = $filters['end_client'];
    }
    if (!empty($filters['engagement_type'])) {
        $where[] = 'p.engagement_type = :etype';
        $params['etype'] = $filters['engagement_type'];
    }
    if (!empty($filters['start_after'])) {
        $where[] = 'p.start_date >= :sa';
        $params['sa'] = $filters['start_after'];
    }
    if (!empty($filters['end_before'])) {
        $where[] = 'p.end_date IS NOT NULL AND p.end_date <= :eb';
        $params['eb'] = $filters['end_before'];
    }
    if (!empty($filters['due_before'])) {
        $where[] = 'p.due_date IS NOT NULL AND p.due_date <= :db';
        $params['db'] = $filters['due_before'];
    }

    $page    = max(1, (int) ($filters['page'] ?? 1));
    $perPage = min(200, max(1, (int) ($filters['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;
    $whereSql = implode(' AND ', $where);

    $total = (int) (scopedFind("SELECT COUNT(*) AS c FROM placements p WHERE {$whereSql}", $params)['c'] ?? 0);
    $rows  = scopedQuery(
        'SELECT ' . placementsSafeFields() . ', pe.first_name, pe.last_name, pe.email_primary,
                COALESCE(ec.name, p.end_client_name) AS end_client_display_name,
                sj.title AS staffing_job_title
         FROM placements p
         LEFT JOIN people pe ON pe.id = p.person_id AND pe.tenant_id = p.tenant_id
         LEFT JOIN companies ec ON ec.id = p.end_client_company_id AND ec.tenant_id = p.tenant_id
         LEFT JOIN staffing_jobs sj ON sj.id = p.staffing_job_id AND sj.tenant_id = p.tenant_id
         WHERE ' . $whereSql . '
         ORDER BY p.start_date DESC
         LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
        $params
    );
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

function placementChain(int $placementId): array
{
    // SELECT explicit safe columns only — never leak portal_credentials_ct.
    // Surface a derived has_portal_credentials boolean for UI gating.
    return scopedQuery(
        'SELECT id, tenant_id, placement_id, position, party_name, party_role,
                company_id, vendor_portal_id, portal_fee_pct, portal_fee_flat,
                contract_storage_object_id, submittal_id, vms_job_id,
                (portal_credentials_ct IS NOT NULL) AS has_portal_credentials,
                kms_key_version, created_at, updated_at
         FROM placement_client_chain
         WHERE tenant_id = :tenant_id AND placement_id = :pid
         ORDER BY position',
        ['pid' => $placementId]
    );
}

/**
 * Encrypt + persist vendor portal credentials for a chain row.
 * Pass a structured array (e.g. {url, username, password, notes}); it is
 * JSON-encoded then encrypted with the tenant KMS key.
 */
function placementChainSetPortalCredentials(int $chainId, array $credentials): void
{
    require_once __DIR__ . '/../../../core/encryption.php';
    $blob = json_encode($credentials, JSON_UNESCAPED_SLASHES);
    if ($blob === false) throw new \InvalidArgumentException('Could not JSON-encode credentials');
    $ct = encryptField($blob);
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    getDB()->prepare(
        'UPDATE placement_client_chain
         SET portal_credentials_ct = :ct, kms_key_version = :v
         WHERE id = :id'
    )->execute(['ct' => $ct, 'v' => 'v1', 'id' => $chainId]);
}

function placementChainClearPortalCredentials(int $chainId): void
{
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    getDB()->prepare(
        'UPDATE placement_client_chain
         SET portal_credentials_ct = NULL, kms_key_version = NULL
         WHERE id = :id'
    )->execute(['id' => $chainId]);
}

/**
 * Decrypt + return the credentials dict for a chain row, or null if unset.
 * Caller MUST audit the read with event 'placement.chain.portal.viewed'.
 */
function placementChainRevealPortalCredentials(int $chainId): ?array
{
    require_once __DIR__ . '/../../../core/encryption.php';
    // tenant-leak-allow: defense-in-depth — primary id was just fetched with tenant scope
    $stmt = getDB()->prepare(
        'SELECT portal_credentials_ct FROM placement_client_chain WHERE id = :id'
    );
    $stmt->execute(['id' => $chainId]);
    $ct = $stmt->fetchColumn();
    if (!$ct) return null;
    $blob = decryptField($ct);
    $arr  = json_decode((string) $blob, true);
    return is_array($arr) ? $arr : null;
}

function placementRates(int $placementId): array
{
    return scopedQuery(
        'SELECT * FROM placement_rates
         WHERE tenant_id = :tenant_id AND placement_id = :pid
         ORDER BY effective_from DESC, id DESC',
        ['pid' => $placementId]
    );
}

function placementCurrentRate(int $placementId, ?string $asOf = null): ?array
{
    $asOf = $asOf ?: date('Y-m-d');
    // Bind :asof to two distinct placeholders so PDO with
    // ATTR_EMULATE_PREPARES=false (server-side prepares) doesn't reject
    // the query with HY093 "Invalid parameter number". MySQL native
    // prepares do not deduplicate repeated named placeholders.
    return scopedFind(
        'SELECT * FROM placement_rates
         WHERE tenant_id = :tenant_id AND placement_id = :pid
           AND approved_at IS NOT NULL
           AND effective_from <= :asof_lo
           AND (effective_to IS NULL OR effective_to >= :asof_hi)
         ORDER BY effective_from DESC LIMIT 1',
        ['pid' => $placementId, 'asof_lo' => $asOf, 'asof_hi' => $asOf]
    );
}

function placementCommissions(int $placementId): array
{
    return scopedQuery(
        'SELECT * FROM placement_commissions
         WHERE tenant_id = :tenant_id AND placement_id = :pid
         ORDER BY role, effective_from DESC',
        ['pid' => $placementId]
    );
}

function placementReferrals(int $placementId): array
{
    return scopedQuery(
        'SELECT * FROM placement_referrals
         WHERE tenant_id = :tenant_id AND placement_id = :pid
         ORDER BY start_date DESC',
        ['pid' => $placementId]
    );
}

function placementDocuments(int $placementId): array
{
    return scopedQuery(
        'SELECT * FROM placement_documents
         WHERE tenant_id = :tenant_id AND placement_id = :pid AND deleted_at IS NULL
         ORDER BY created_at DESC',
        ['pid' => $placementId]
    );
}

/**
 * Compute net margin per SPEC §4 from a rate row + chain.
 * Returns ['adjusted_bill_rate', 'net_to_vendor', 'gross_margin_per_hour',
 *          'total_portal_fee_pct'].
 */
function placementsComputeMargin(array $rate, array $chain): array
{
    $totalPct  = 0.0;
    $totalFlat = 0.0;
    foreach ($chain as $c) {
        if (!empty($c['portal_fee_pct']))  $totalPct  += (float) $c['portal_fee_pct'];
        if (!empty($c['portal_fee_flat'])) $totalFlat += (float) $c['portal_fee_flat'];
    }
    $bill = (float) $rate['bill_rate'];
    $pay  = (float) $rate['pay_rate'];
    // Flat fee per hour amortization assumed at 160 hrs/month ≈ 173.33/mo equivalent;
    // for stored snapshot we treat flat as $/hour input directly (UI converts),
    // OR caller divides by their billable_hours_in_period as needed.
    $adjusted = $bill * (1 - $totalPct) - $totalFlat;
    $net      = $adjusted - $pay;
    return [
        'adjusted_bill_rate'    => round($adjusted, 4),
        'net_to_vendor'         => round($net, 4),
        'gross_margin_per_hour' => round($adjusted - $pay, 4),
        'total_portal_fee_pct'  => round($totalPct, 6),
    ];
}

/**
 * Audit logger for placements.* events. Writes to audit_log;
 * never blocks calling request on failure.
 */
function placementsAudit(string $event, array $meta = [], ?int $targetId = null): void
{
    $pdo = getDB();
    if (!$pdo) {
        error_log("[placements.audit] {$event} target={$targetId} meta=" . json_encode($meta));
        return;
    }
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log
             (tenant_id, actor_user_id, event, target_id, meta_json, ip_address, request_id, created_at)
             VALUES (:tenant_id, :actor_user_id, :event, :target_id, :meta_json, :ip_address, :request_id, NOW())'
        );
        $stmt->execute([
            'tenant_id'     => currentTenantId(),
            'actor_user_id' => $_SESSION['user']['id'] ?? null,
            'event'         => $event,
            'target_id'     => $targetId,
            'meta_json'     => $meta ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null,
            'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
            'request_id'    => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log("[placements.audit] db-write-failed: " . $e->getMessage() . " event={$event}");
    }
}
