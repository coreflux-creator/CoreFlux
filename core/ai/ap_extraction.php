<?php
/**
 * core/ai/ap_extraction.php — Slice E AP invoice review helpers.
 *
 * Spec §11 ("AP Agent"):
 *   - apExtractionCreate         — register a new extraction run from
 *                                  an uploaded PDF/image.
 *   - apExtractionCheckDuplicate — fuzzy + exact match against
 *                                  ap_bills to prevent double-entry.
 *   - apExtractionDraftBill      — promote the extracted payload to
 *                                  a real ap_bills row (status=inbox).
 *   - apExtractionList / Get     — reviewer surface backing.
 *
 * Duplicate detection rules (in priority order):
 *   1. EXACT — same (vendor_name normalized, bill_number) within the
 *               tenant. Returns match_exact.
 *   2. LIKELY — same (vendor_name normalized, total, bill_date) within
 *               the tenant, even if bill_number differs (common with
 *               vendor PDF re-mails). Returns match_likely.
 *   3. NO_MATCH otherwise.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

/**
 * Normalize a payee/vendor name the same way Slice B's vendor_aliases
 * does — uppercase, strip trailing punctuation, collapse whitespace.
 * Lets duplicate-detection survive small vendor-name jitter
 * ("ACME Co." vs "acme co").
 */
function apNormalizeVendorName(string $s): string
{
    $s = strtoupper(trim($s));
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = rtrim($s, ".,;:!? ");
    return $s;
}

/**
 * Register a new extraction run. Status starts at `pending` so the
 * worker (or the synchronous handler) can flip it to `extracted` once
 * the AI returns a payload.
 *
 * @return array  The new row.
 */
function apExtractionCreate(int $tenantId, array $opts): array
{
    if ($tenantId <= 0) throw new \InvalidArgumentException('tenantId required');
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO ap_invoice_extraction_runs
            (tenant_id, sub_tenant_id, source_storage_uri, source_filename,
             source_mime_type, source_artifact_id, status,
             created_by_user_id, created_at, updated_at)
         VALUES
            (:t, :st, :uri, :fn, :mime, :aid, "pending", :u, NOW(), NOW())'
    )->execute([
        't'   => $tenantId,
        'st'  => isset($opts['sub_tenant_id']) ? (int) $opts['sub_tenant_id'] : null,
        'uri' => $opts['source_storage_uri']   ?? null,
        'fn'  => $opts['source_filename']      ?? null,
        'mime'=> $opts['source_mime_type']     ?? null,
        'aid' => $opts['source_artifact_id']   ?? null,
        'u'   => isset($opts['created_by_user_id']) ? (int) $opts['created_by_user_id'] : null,
    ]);
    return apExtractionGet($tenantId, (int) $pdo->lastInsertId());
}

/**
 * Stamp an extracted payload onto a run + flip status to `extracted`.
 * Idempotent — overwrites the payload if called twice on the same run.
 */
function apExtractionRecordPayload(int $tenantId, int $runId, array $payload, ?float $confidence = null, ?string $aiRunId = null): array
{
    if ($runId <= 0) throw new \InvalidArgumentException('runId required');
    getDB()->prepare(
        'UPDATE ap_invoice_extraction_runs
            SET extracted_payload_json = :p,
                confidence = :c,
                ai_run_id  = :ai,
                status     = "extracted",
                updated_at = NOW()
          WHERE id = :id AND tenant_id = :t'
    )->execute([
        'p'  => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'c'  => $confidence, 'ai' => $aiRunId,
        'id' => $runId, 't'  => $tenantId,
    ]);
    return apExtractionGet($tenantId, $runId);
}

/**
 * Run duplicate detection against existing ap_bills rows.
 * Updates the run with the verdict.
 *
 * @return array  {status, duplicate_bill_id, reason}
 */
function apExtractionCheckDuplicate(int $tenantId, int $runId): array
{
    $run = apExtractionGet($tenantId, $runId);
    if (!$run) throw new \RuntimeException("extraction run {$runId} not found");
    $payload = $run['extracted_payload'] ?? [];
    if (!$payload) {
        throw new \RuntimeException("run {$runId} has no extracted payload; call apExtractionRecordPayload first");
    }

    $vendor      = apNormalizeVendorName((string) ($payload['vendor_name'] ?? ''));
    $billNumber  = trim((string) ($payload['bill_number'] ?? ''));
    $billDate    = (string) ($payload['bill_date'] ?? '');
    $total       = round((float) ($payload['total'] ?? 0), 2);

    $verdict   = 'no_match';
    $billId    = null;
    $reason    = null;

    if ($vendor !== '' && $billNumber !== '') {
        $stmt = getDB()->prepare(
            'SELECT id, bill_number, vendor_name, total FROM ap_bills
              WHERE tenant_id = :t
                AND UPPER(TRIM(vendor_name)) = :v
                AND TRIM(bill_number) = :b
              LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId, 'v' => $vendor, 'b' => $billNumber]);
        $hit = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($hit) {
            $verdict = 'match_exact';
            $billId  = (int) $hit['id'];
            $reason  = "vendor+bill_number match (bill #{$hit['id']})";
        }
    }
    if ($verdict === 'no_match' && $vendor !== '' && $billDate !== '' && $total > 0) {
        $stmt = getDB()->prepare(
            'SELECT id, bill_number, vendor_name, total, bill_date FROM ap_bills
              WHERE tenant_id = :t
                AND UPPER(TRIM(vendor_name)) = :v
                AND bill_date = :d
                AND ABS(total - :amt) < 0.01
              LIMIT 1'
        );
        $stmt->execute(['t' => $tenantId, 'v' => $vendor, 'd' => $billDate, 'amt' => $total]);
        $hit = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($hit) {
            $verdict = 'match_likely';
            $billId  = (int) $hit['id'];
            $reason  = "vendor+date+total match (bill #{$hit['id']}, bill_number differs)";
        }
    }

    // Update the run.
    $newStatus = $verdict === 'no_match' ? $run['status'] : 'duplicate';
    getDB()->prepare(
        'UPDATE ap_invoice_extraction_runs
            SET duplicate_check_status = :ds,
                duplicate_bill_id      = :db,
                duplicate_reason       = :dr,
                status                 = :s,
                updated_at = NOW()
          WHERE id = :id AND tenant_id = :t'
    )->execute([
        'ds' => $verdict, 'db' => $billId, 'dr' => $reason, 's'  => $newStatus,
        'id' => $runId, 't'  => $tenantId,
    ]);

    return ['status' => $verdict, 'duplicate_bill_id' => $billId, 'reason' => $reason];
}

/**
 * Promote the extracted payload to a draft ap_bills row.
 * Refuses if the run is already marked as a duplicate.
 *
 * @return array {extraction_run_id, draft_bill_id, status}
 */
function apExtractionDraftBill(int $tenantId, int $runId, ?int $actorUserId = null): array
{
    $run = apExtractionGet($tenantId, $runId);
    if (!$run) throw new \RuntimeException("extraction run {$runId} not found");
    if ($run['status'] === 'duplicate') {
        throw new \RuntimeException("run {$runId} is flagged duplicate of bill #{$run['duplicate_bill_id']}; resolve first");
    }
    if (!empty($run['draft_bill_id'])) {
        // Idempotent.
        return [
            'extraction_run_id' => $runId,
            'draft_bill_id'     => (int) $run['draft_bill_id'],
            'status'            => 'drafted',
            'idempotent_replay' => true,
        ];
    }
    $p = $run['extracted_payload'] ?? [];
    if (!$p) {
        throw new \RuntimeException("run {$runId} has no extracted payload");
    }

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO ap_bills
            (tenant_id, bill_number, internal_ref, vendor_name, vendor_type,
             received_at, bill_date, due_date, currency,
             subtotal, tax_total, total, amount_paid, amount_due,
             status, source, source_ref_id)
         VALUES
            (:t, :bn, :ir, :vn, "other",
             CURDATE(), :bd, :dd, :cur,
             :sub, :tax, :tot, 0, :due,
             "inbox", "manual", :rr)'
    )->execute([
        't'   => $tenantId,
        'bn'  => mb_substr((string) ($p['bill_number'] ?? ('AI-' . $runId)), 0, 80),
        'ir'  => 'AI-' . str_pad((string) $runId, 6, '0', STR_PAD_LEFT),
        'vn'  => mb_substr((string) ($p['vendor_name'] ?? 'Unknown Vendor'), 0, 255),
        'bd'  => (string) ($p['bill_date'] ?? date('Y-m-d')),
        'dd'  => (string) ($p['due_date']  ?? date('Y-m-d', strtotime('+30 days'))),
        'cur' => mb_substr((string) ($p['currency'] ?? 'USD'), 0, 3),
        'sub' => round((float) ($p['subtotal']  ?? ($p['total'] ?? 0)), 2),
        'tax' => round((float) ($p['tax_total'] ?? 0), 2),
        'tot' => round((float) ($p['total']     ?? 0), 2),
        'due' => round((float) ($p['total']     ?? 0), 2),
        'rr'  => $runId,
    ]);
    $billId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'UPDATE ap_invoice_extraction_runs
            SET draft_bill_id = :b,
                status        = "drafted",
                updated_at    = NOW()
          WHERE id = :id AND tenant_id = :t'
    )->execute(['b' => $billId, 'id' => $runId, 't' => $tenantId]);

    return ['extraction_run_id' => $runId, 'draft_bill_id' => $billId, 'status' => 'drafted'];
}

/** Read a single run, decoding the payload JSON. */
function apExtractionGet(int $tenantId, int $runId): ?array
{
    if ($tenantId <= 0 || $runId <= 0) return null;
    $stmt = getDB()->prepare(
        'SELECT * FROM ap_invoice_extraction_runs
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $runId, 't' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;
    foreach (['id','tenant_id','sub_tenant_id','duplicate_bill_id','draft_bill_id','posted_bill_id','created_by_user_id'] as $k) {
        if ($row[$k] !== null) $row[$k] = (int) $row[$k];
    }
    $row['confidence'] = $row['confidence'] !== null ? (float) $row['confidence'] : null;
    $row['extracted_payload'] = $row['extracted_payload_json']
        ? (json_decode((string) $row['extracted_payload_json'], true) ?: [])
        : [];
    return $row;
}

/** List runs newest-first, optionally filtered by status. */
function apExtractionList(int $tenantId, array $filters = []): array
{
    if ($tenantId <= 0) return [];
    $where  = ['tenant_id = :t'];
    $params = ['t' => $tenantId];
    if (!empty($filters['status'])) {
        $where[] = 'status = :s'; $params['s'] = (string) $filters['status'];
    }
    $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
    $stmt = getDB()->prepare(
        'SELECT id, tenant_id, sub_tenant_id, source_filename, status,
                confidence, duplicate_check_status, duplicate_bill_id,
                draft_bill_id, posted_bill_id, created_at, updated_at
           FROM ap_invoice_extraction_runs
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY id DESC LIMIT ' . $limit
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        foreach (['id','tenant_id','sub_tenant_id','duplicate_bill_id','draft_bill_id','posted_bill_id'] as $k) {
            if ($r[$k] !== null) $r[$k] = (int) $r[$k];
        }
        $r['confidence'] = $r['confidence'] !== null ? (float) $r['confidence'] : null;
    } unset($r);
    return $rows;
}
