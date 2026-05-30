<?php
/**
 * /api/admin/reports/save_snapshot.php
 *
 * Reports Overhaul follow-up — point-in-time report capture.
 *
 * Captures the exact JSON envelope the operator was looking at
 * (current + comparison windows merged client-side) and the URL /
 * filter context, persisting them as a polymorphic
 * `evidence_attachments` row tagged `document_type='report_snapshot'`.
 *
 * Auditors can pull "the exact P&L on Feb-19" months later by listing
 * evidence_attachments where subject_type='tenant' AND
 * document_type='report_snapshot' AND attached_at BETWEEN ?.
 *
 *   POST /api/admin/reports/save_snapshot.php
 *     body: { report_key, label, params: {...}, envelope: {...} }
 *       → { id, label, attached_at, signed_url? }
 *
 *   GET  /api/admin/reports/save_snapshot.php?report_key=rpt-pnl&limit=20
 *       → { snapshots: [{ id, label, attached_at, params, attached_by_name }, ...] }
 *
 *   GET  /api/admin/reports/save_snapshot.php?id=N
 *       → { id, label, attached_at, params, envelope, attached_by_name }
 *
 * RBAC: same as the underlying report (we use accounting.coa.view as
 * the canonical "you can see this number" gate — any report rendering
 * payload via gl_detail already requires it).
 *
 * Storage: payload JSON stored INLINE on the row (no S3 key needed —
 * snapshots are typically <100KB envelopes). For large payloads we
 * could spill to S3 later; today inline keeps the audit cycle simple.
 *
 * Spec: PRD §"Reports Overhaul — Save report snapshot" (current fork).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$uid  = (int) ($user['id'] ?? 0);

rbac_legacy_require($user, 'accounting.coa.view');

$pdo = getDB();

if (api_method() === 'POST') {
    $body = api_json_body();

    $reportKey = trim((string) ($body['report_key'] ?? ''));
    $label     = trim((string) ($body['label']      ?? ''));
    $params    = $body['params']   ?? null;
    $envelope  = $body['envelope'] ?? null;

    if ($reportKey === '')                  api_error('report_key required',          422);
    if (strlen($reportKey) > 40)            api_error('report_key too long',          422);
    if ($label === '')                      $label = $reportKey . ' · ' . date('Y-m-d H:i');
    if (strlen($label) > 255)               $label = substr($label, 0, 255);
    if (!is_array($params))                 api_error('params must be a JSON object', 422);
    if (!is_array($envelope))               api_error('envelope must be a JSON object', 422);

    // Hard cap on payload size — anything over 256KB is a smell and
    // belongs in S3, not the evidence row. We keep MVP simple.
    $payloadEncoded = json_encode([
        'report_key' => $reportKey,
        'params'     => $params,
        'envelope'   => $envelope,
    ], JSON_UNESCAPED_SLASHES);
    if ($payloadEncoded === false) {
        api_error('envelope contains values that cannot be JSON-encoded', 422);
    }
    if (strlen($payloadEncoded) > 256 * 1024) {
        api_error('snapshot payload exceeds 256KB — too large to inline', 413);
    }

    try {
        $st = $pdo->prepare("
            INSERT INTO evidence_attachments
                (tenant_id, subject_type, subject_id,
                 document_type, label, payload,
                 source, attached_by_user_id, attached_at)
            VALUES (?, 'tenant', ?, 'report_snapshot', ?, ?,
                    'manual_upload', ?, NOW())
        ");
        $st->execute([$tid, $tid, $label, $payloadEncoded, $uid]);
        $id = (int) $pdo->lastInsertId();
    } catch (\Throwable $e) {
        error_log('[save_snapshot POST] ' . $e->getMessage());
        api_error('Could not save snapshot', 500);
    }

    api_ok([
        'id'          => $id,
        'label'       => $label,
        'report_key'  => $reportKey,
        'attached_at' => date('Y-m-d H:i:s'),
    ]);
}

if (api_method() === 'GET') {
    $id        = (int) (api_query('id') ?? 0);
    $reportKey = trim((string) (api_query('report_key') ?? ''));
    $limit     = max(1, min(100, (int) (api_query('limit') ?? 20)));

    try {
        if ($id > 0) {
            // Single-snapshot fetch: returns the envelope for audit replay.
            $st = $pdo->prepare("
                SELECT ea.id, ea.label, ea.payload, ea.attached_at,
                       ea.attached_by_user_id,
                       u.email AS attached_by_email
                  FROM evidence_attachments ea
             LEFT JOIN users u ON u.id = ea.attached_by_user_id
                 WHERE ea.tenant_id = ?
                   AND ea.id = ?
                   AND ea.document_type = 'report_snapshot'
                   AND ea.deleted_at IS NULL
            ");
            $st->execute([$tid, $id]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$row) api_error('Snapshot not found', 404);

            $decoded = json_decode((string) $row['payload'], true);
            api_ok([
                'id'          => (int) $row['id'],
                'label'       => $row['label'],
                'attached_at' => $row['attached_at'],
                'attached_by' => $row['attached_by_email'],
                'report_key'  => $decoded['report_key'] ?? null,
                'params'      => $decoded['params']     ?? null,
                'envelope'    => $decoded['envelope']   ?? null,
            ]);
        }

        // List mode: most recent first, filterable by report_key.
        $where  = "ea.tenant_id = ? AND ea.document_type = 'report_snapshot' AND ea.deleted_at IS NULL";
        $params = [$tid];
        if ($reportKey !== '') {
            // Match the report_key INSIDE the JSON payload. MySQL >=5.7
            // supports JSON_EXTRACT; we keep it ANSI-safe with LIKE on
            // the encoded string as fallback.
            $where  .= " AND ea.payload LIKE ?";
            $params[] = '%"report_key":"' . str_replace('%', '\%', $reportKey) . '"%';
        }

        $st = $pdo->prepare("
            SELECT ea.id, ea.label, ea.attached_at,
                   ea.attached_by_user_id,
                   u.email AS attached_by_email,
                   ea.payload
              FROM evidence_attachments ea
         LEFT JOIN users u ON u.id = ea.attached_by_user_id
             WHERE {$where}
          ORDER BY ea.attached_at DESC
             LIMIT {$limit}
        ");
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // Surface params for the list (skip envelope to keep response light).
        $out = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['payload'], true);
            $out[] = [
                'id'          => (int) $row['id'],
                'label'       => $row['label'],
                'attached_at' => $row['attached_at'],
                'attached_by' => $row['attached_by_email'],
                'report_key'  => $decoded['report_key'] ?? null,
                'params'      => $decoded['params']     ?? null,
            ];
        }
    } catch (\Throwable $e) {
        error_log('[save_snapshot GET] ' . $e->getMessage());
        api_error('Could not load snapshots', 500);
    }

    api_ok(['snapshots' => $out]);
}

api_error('Method not allowed', 405);
