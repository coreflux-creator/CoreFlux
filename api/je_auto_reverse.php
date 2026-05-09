<?php
/**
 * JE auto-reverse toggle (Sprint P2).
 *
 *   POST /api/je_auto_reverse.php?action=set
 *     body: { je_id: N, auto_reverses_on: 'YYYY-MM-DD' | null }
 *
 * Sets or clears the auto_reverses_on date on a posted, non-reversal JE.
 * Cron `scripts/auto_reverse_accruals.php` does the actual reversal on or
 * after that date.
 *
 * RBAC: `accounting.je.post` (only people who can post can schedule reversals).
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'POST') api_error('Method not allowed', 405);
RBAC::requirePermission($user, 'accounting.je.post');

$body  = api_json_body();
$jeId  = (int) ($body['je_id'] ?? 0);
$date  = $body['auto_reverses_on'] ?? null;

if ($jeId <= 0) api_error('je_id required', 422);
if ($date !== null) {
    $date = (string) $date;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        api_error('auto_reverses_on must be YYYY-MM-DD or null', 422);
    }
}

$pdo  = getDB();
$stmt = $pdo->prepare(
    "SELECT id, status, reverses_je_id, posting_date
       FROM accounting_journal_entries
      WHERE id = :id AND tenant_id = :t LIMIT 1"
);
$stmt->execute(['id' => $jeId, 't' => $tid]);
$je = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$je) api_error('JE not found', 404);
if ($je['status'] !== 'posted') api_error('JE must be posted', 422);
if ($je['reverses_je_id'] !== null) api_error('JE is itself a reversal', 422);
if ($date !== null && $date <= $je['posting_date']) {
    api_error('auto_reverses_on must be after posting_date', 422);
}

$pdo->prepare(
    'UPDATE accounting_journal_entries
        SET auto_reverses_on        = :d,
            auto_reverse_attempted_at = NULL,
            auto_reverse_last_error   = NULL
      WHERE id = :id AND tenant_id = :t'
)->execute(['d' => $date, 'id' => $jeId, 't' => $tid]);

api_ok(['je_id' => $jeId, 'auto_reverses_on' => $date]);
