<?php
/**
 * Accounting API — Recurring journal entries.
 *
 *   GET  /api/accounting/recurring_journal_entries[?status=active|paused|ended]
 *   GET  /api/accounting/recurring_journal_entries?id=N           → header + lines
 *   POST /api/accounting/recurring_journal_entries                → create with lines
 *   PUT  /api/accounting/recurring_journal_entries?id=N           → update header
 *   POST /api/accounting/recurring_journal_entries?action=replace_lines&id=N
 *   POST /api/accounting/recurring_journal_entries?action=pause&id=N
 *   POST /api/accounting/recurring_journal_entries?action=resume&id=N
 *   POST /api/accounting/recurring_journal_entries?action=end&id=N
 *   POST /api/accounting/recurring_journal_entries?action=run_now&id=N
 *   POST /api/accounting/recurring_journal_entries?action=run_due → cron entrypoint
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';
require_once __DIR__ . '/../lib/recurring_je.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';
$tid    = (int) $ctx['tenant_id'];

/** Turn both header-level and line-level validation failures into one useful message. */
function recurringJeValidationMessage(array $validation): string
{
    $messages = array_values((array) ($validation['errors'] ?? []));
    foreach ((array) ($validation['line_validations'] ?? []) as $line) {
        foreach ((array) ($line['errors'] ?? []) as $message) {
            $messages[] = 'Line ' . (int) ($line['line_no'] ?? 0) . ': ' . $message;
        }
    }
    $messages = array_values(array_unique(array_filter(array_map('strval', $messages))));
    return implode('; ', $messages) ?: 'Journal validation failed';
}

if ($method === 'GET' && !empty($_GET['id'])) {
    rbac_legacy_require($user, 'accounting.je.create');
    $id  = (int) $_GET['id'];
    $row = scopedFind('SELECT * FROM accounting_recurring_journal_entries WHERE tenant_id = :tenant_id AND id = :id', ['id' => $id]);
    if (!$row) api_error('Not found', 404);
    $lines = scopedQuery(
        'SELECT id, line_no, account_code, debit, credit, description, dim_json
         FROM accounting_recurring_je_lines WHERE tenant_id = :tenant_id AND recurring_je_id = :rid
         ORDER BY line_no, id', ['rid' => $id]
    );
    foreach ($lines as &$line) {
        $line['dims'] = recurringJeDecodeDimensions($line['dim_json'] ?? null);
    }
    unset($line);
    api_ok(['template' => $row, 'lines' => $lines]);
}

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.je.create');
    $where = ['tenant_id = :tenant_id']; $params = [];
    if (!empty($_GET['status'])) { $where[] = 'status = :s'; $params['s'] = (string) $_GET['status']; }
    $rows = scopedQuery(
        'SELECT id, name, cadence, next_run_date, end_date, auto_post, status,
                last_run_at, last_run_je_id, entity_id, created_at
         FROM accounting_recurring_journal_entries
         WHERE ' . implode(' AND ', $where) . ' ORDER BY status, next_run_date, id',
        $params
    );
    api_ok(['rows' => $rows]);
}

if ($method === 'POST' && $action === '') {
    rbac_legacy_require($user, 'accounting.je.create');
    $body = api_json_body();
    api_require_fields($body, ['name', 'cadence', 'next_run_date', 'lines']);
    if (!in_array($body['cadence'], ['weekly','biweekly','monthly','quarterly','yearly'], true)) {
        api_error('Invalid cadence', 422);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $body['next_run_date'])) {
        api_error('next_run_date must be YYYY-MM-DD', 422);
    }
    $lines = $body['lines'];
    if (!is_array($lines) || count($lines) < 2) api_error('Need at least 2 lines', 422);
    $td = 0.0; $tc = 0.0;
    foreach ($lines as $l) {
        $td += (float) ($l['debit'] ?? 0);
        $tc += (float) ($l['credit'] ?? 0);
    }
    if (abs($td - $tc) > 0.005 || $td <= 0) api_error('Lines must balance and total > 0', 422);

    try {
        $entityId = accountingValidateActiveEntityId($tid, $body['entity_id'] ?? null)
            ?? (int) accountingDefaultEntity($tid)['id'];
        $lines = recurringJePrepareLines($tid, $entityId, (string) $body['next_run_date'], $lines);
        $validation = accountingValidateJe($tid, [
            'entity_id' => $entityId,
            'posting_date' => (string) $body['next_run_date'],
            'lines' => $lines,
        ]);
        if (!$validation['ok']) {
            throw new \InvalidArgumentException(recurringJeValidationMessage($validation));
        }
    } catch (\Throwable $e) {
        api_error('Recurring template is not ready: ' . $e->getMessage(), 422);
    }

    $pdo = getDB();
    if ($pdo->inTransaction()) { error_log('[accounting/recurring-je create] rolling back stale active transaction before begin'); $pdo->rollBack(); }
    $pdo->beginTransaction();
    try {
        $id = scopedInsert('accounting_recurring_journal_entries', [
            'entity_id'     => $entityId,
            'name'          => (string) $body['name'],
            'memo'          => $body['memo']      ?? null,
            'cadence'       => (string) $body['cadence'],
            'next_run_date' => (string) $body['next_run_date'],
            'end_date'      => $body['end_date']  ?? null,
            'auto_post'     => !empty($body['auto_post']) ? 1 : 0,
        ]);
        foreach (array_values($lines) as $i => $l) {
            scopedInsert('accounting_recurring_je_lines', [
                'recurring_je_id' => $id,
                'line_no'         => $i + 1,
                'account_code'    => (string) $l['account_code'],
                'debit'           => (float) ($l['debit'] ?? 0),
                'credit'          => (float) ($l['credit'] ?? 0),
                'description'     => $l['description'] ?? null,
                'dim_json'        => json_encode((array) ($l['dims'] ?? []), JSON_UNESCAPED_SLASHES),
            ]);
        }
        $pdo->commit();
    } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    accountingAudit('accounting.recurring_je.created', ['name' => $body['name'], 'cadence' => $body['cadence']], $id);
    api_ok(['id' => $id], 201);
}

if ($method === 'POST' && $action === 'replace_lines') {
    rbac_legacy_require($user, 'accounting.je.create');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    $lines = $body['lines'] ?? [];
    if (!is_array($lines) || count($lines) < 2) api_error('Need at least 2 lines', 422);
    $td = 0.0; $tc = 0.0;
    foreach ($lines as $l) { $td += (float) ($l['debit'] ?? 0); $tc += (float) ($l['credit'] ?? 0); }
    if (abs($td - $tc) > 0.005 || $td <= 0) api_error('Lines must balance and total > 0', 422);
    $template = scopedFind(
        'SELECT entity_id, next_run_date FROM accounting_recurring_journal_entries
          WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$template) api_error('Not found', 404);
    try {
        $entityId = accountingValidateActiveEntityId($tid, $template['entity_id'] ?? null)
            ?? (int) accountingDefaultEntity($tid)['id'];
        $lines = recurringJePrepareLines($tid, $entityId, (string) $template['next_run_date'], $lines);
        $validation = accountingValidateJe($tid, [
            'entity_id' => $entityId,
            'posting_date' => (string) $template['next_run_date'],
            'lines' => $lines,
        ]);
        if (!$validation['ok']) {
            throw new \InvalidArgumentException(recurringJeValidationMessage($validation));
        }
    } catch (\Throwable $e) {
        api_error('Recurring template is not ready: ' . $e->getMessage(), 422);
    }
    $pdo = getDB();
    if ($pdo->inTransaction()) { error_log('[accounting/recurring-je replace] rolling back stale active transaction before begin'); $pdo->rollBack(); }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM accounting_recurring_je_lines WHERE tenant_id = :t AND recurring_je_id = :r')
            ->execute(['t' => $tid, 'r' => $id]);
        foreach (array_values($lines) as $i => $l) {
            scopedInsert('accounting_recurring_je_lines', [
                'recurring_je_id' => $id,
                'line_no'         => $i + 1,
                'account_code'    => (string) $l['account_code'],
                'debit'           => (float) ($l['debit'] ?? 0),
                'credit'          => (float) ($l['credit'] ?? 0),
                'description'     => $l['description'] ?? null,
                'dim_json'        => json_encode((array) ($l['dims'] ?? []), JSON_UNESCAPED_SLASHES),
            ]);
        }
        $pdo->commit();
    } catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    accountingAudit('accounting.recurring_je.lines_replaced', ['line_count' => count($lines)], $id);
    api_ok(['ok' => true]);
}

if ($method === 'POST' && in_array($action, ['pause','resume','end'], true)) {
    rbac_legacy_require($user, 'accounting.je.create');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $newStatus = match ($action) {
        'pause'  => 'paused',
        'resume' => 'active',
        'end'    => 'ended',
    };
    scopedUpdate('accounting_recurring_journal_entries', $id, ['status' => $newStatus]);
    accountingAudit('accounting.recurring_je.' . $action, ['status' => $newStatus], $id);
    api_ok(['ok' => true, 'status' => $newStatus]);
}

if ($method === 'POST' && $action === 'run_now') {
    rbac_legacy_require($user, 'accounting.je.create');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $body = api_json_body();
    try {
        $r = recurringJeRunOnce($tid, $id, $user['id'] ?? null, $body['run_date'] ?? null);
        api_ok($r);
    } catch (\Throwable $e) {
        api_error('Run failed: ' . $e->getMessage(), 500);
    }
}

if ($method === 'POST' && $action === 'run_due') {
    // Cron entrypoint. Master-admin or scoped tenant-admin can hit it
    // (use accounting.coa.edit as a coarse "settings-level" perm).
    rbac_legacy_require($user, 'accounting.coa.edit');
    $r = recurringJeRunDueForTenant($tid, $user['id'] ?? null);
    api_ok($r);
}

if ($method === 'PUT') {
    rbac_legacy_require($user, 'accounting.je.create');
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 400);
    $existing = scopedFind(
        'SELECT * FROM accounting_recurring_journal_entries
          WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $id]
    );
    if (!$existing) api_error('Not found', 404);

    $body    = api_json_body();
    $allowed = ['name','memo','cadence','next_run_date','end_date','auto_post','entity_id'];
    $data = [];
    foreach ($allowed as $f) if (array_key_exists($f, $body)) $data[$f] = $body[$f];

    if (array_key_exists('cadence', $data)
        && !in_array($data['cadence'], ['weekly','biweekly','monthly','quarterly','yearly'], true)) {
        api_error('Invalid cadence', 422);
    }
    $candidateRunDate = (string) ($data['next_run_date'] ?? $existing['next_run_date']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidateRunDate)) {
        api_error('next_run_date must be YYYY-MM-DD', 422);
    }

    $hasLinePayload = array_key_exists('lines', $body);
    $refreshStoredLines = $hasLinePayload
        || array_key_exists('entity_id', $data)
        || array_key_exists('next_run_date', $data);
    $preparedLines = null;
    try {
        $candidateEntityId = accountingValidateActiveEntityId(
            $tid,
            $data['entity_id'] ?? $existing['entity_id'] ?? null
        ) ?? (int) accountingDefaultEntity($tid)['id'];
        if (array_key_exists('entity_id', $data) || empty($existing['entity_id'])) {
            $data['entity_id'] = $candidateEntityId;
        }

        if ($refreshStoredLines) {
            $candidateLines = $hasLinePayload
                ? $body['lines']
                : scopedQuery(
                    'SELECT * FROM accounting_recurring_je_lines
                      WHERE tenant_id = :tenant_id AND recurring_je_id = :rid
                      ORDER BY line_no, id',
                    ['rid' => $id]
                );
            if (!is_array($candidateLines) || count($candidateLines) < 2) {
                throw new \InvalidArgumentException('Need at least 2 lines');
            }
            $td = 0.0; $tc = 0.0;
            foreach ($candidateLines as $line) {
                $td += (float) ($line['debit'] ?? 0);
                $tc += (float) ($line['credit'] ?? 0);
            }
            if (abs($td - $tc) > 0.005 || $td <= 0) {
                throw new \InvalidArgumentException('Lines must balance and total > 0');
            }
            $preparedLines = recurringJePrepareLines(
                $tid,
                $candidateEntityId,
                $candidateRunDate,
                $candidateLines
            );
            $validation = accountingValidateJe($tid, [
                'entity_id' => $candidateEntityId,
                'posting_date' => $candidateRunDate,
                'lines' => $preparedLines,
            ]);
            if (!$validation['ok']) {
                throw new \InvalidArgumentException(recurringJeValidationMessage($validation));
            }
        }
    } catch (\Throwable $e) {
        api_error('Recurring template is not ready: ' . $e->getMessage(), 422);
    }

    $pdo = getDB();
    if ($pdo->inTransaction()) {
        error_log('[accounting/recurring-je update] rolling back stale active transaction before begin');
        $pdo->rollBack();
    }
    $pdo->beginTransaction();
    try {
        if ($data) scopedUpdate('accounting_recurring_journal_entries', $id, $data);
        if (is_array($preparedLines) && $hasLinePayload) {
            $pdo->prepare(
                'DELETE FROM accounting_recurring_je_lines
                  WHERE tenant_id = :tenant_id AND recurring_je_id = :rid'
            )->execute(['tenant_id' => $tid, 'rid' => $id]);
            foreach (array_values($preparedLines) as $index => $line) {
                scopedInsert('accounting_recurring_je_lines', [
                    'recurring_je_id' => $id,
                    'line_no' => $index + 1,
                    'account_code' => (string) $line['account_code'],
                    'debit' => (float) ($line['debit'] ?? 0),
                    'credit' => (float) ($line['credit'] ?? 0),
                    'description' => $line['description'] ?? null,
                    'dim_json' => json_encode((array) ($line['dims'] ?? []), JSON_UNESCAPED_SLASHES),
                ]);
            }
        } elseif (is_array($preparedLines)) {
            $updateDimensions = $pdo->prepare(
                'UPDATE accounting_recurring_je_lines
                    SET dim_json = :dim_json
                  WHERE tenant_id = :tenant_id
                    AND recurring_je_id = :rid
                    AND id = :id'
            );
            foreach ($preparedLines as $line) {
                $updateDimensions->execute([
                    'dim_json' => json_encode((array) ($line['dims'] ?? []), JSON_UNESCAPED_SLASHES),
                    'tenant_id' => $tid,
                    'rid' => $id,
                    'id' => (int) $line['id'],
                ]);
            }
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    accountingAudit('accounting.recurring_je.updated', [
        'fields' => array_keys($data),
        'line_count' => is_array($preparedLines) && $hasLinePayload ? count($preparedLines) : null,
    ], $id);
    api_ok(['ok' => true, 'lines_updated' => is_array($preparedLines) && $hasLinePayload]);
}

api_error('Method not allowed', 405);
