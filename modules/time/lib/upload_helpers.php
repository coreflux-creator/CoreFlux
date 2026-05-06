<?php
/**
 * Time — Upload extraction helpers.
 *
 * Pure helper functions used by the manual upload (`api/upload.php`) and
 * the email intake (`lib/intake.php`). No HTTP / RBAC / api_ok in here.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/db.php';

/**
 * Resolve each AI-extracted person_name to candidate rows in `people`.
 *
 * Match strategy (case-insensitive):
 *   1. Exact `first_name + last_name`
 *   2. Exact `preferred_name + last_name`
 *   3. Exact `preferred_name`
 *
 * Each entry gets `match_candidates: [{id, name, email}]` — typically zero
 * or one rows. The user always confirms before save.
 */
function timeUploadResolvePeople(\PDO $pdo, int $tenantId, array $people): array
{
    if (empty($people)) return [];
    foreach ($people as &$p) {
        $name = trim((string) ($p['person_name'] ?? ''));
        $p['match_candidates'] = [];
        if ($name === '') continue;
        $stmt = $pdo->prepare(
            "SELECT id, first_name, last_name, preferred_name, email_primary
               FROM people
              WHERE tenant_id = :t
                AND deleted_at IS NULL
                AND (
                       LOWER(CONCAT_WS(' ', first_name, last_name))     = LOWER(:n)
                    OR LOWER(CONCAT_WS(' ', preferred_name, last_name)) = LOWER(:n)
                    OR LOWER(preferred_name)                            = LOWER(:n)
                )
              LIMIT 5"
        );
        $stmt->execute(['t' => $tenantId, 'n' => $name]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $display = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            if ($display === '') $display = (string) ($row['preferred_name'] ?? '');
            $p['match_candidates'][] = [
                'id'    => (int) $row['id'],
                'name'  => $display,
                'email' => $row['email_primary'] ?? null,
            ];
        }
    }
    return $people;
}

/**
 * Heuristic: fraction of extracted lines that have a parseable work_date
 * AND a non-zero hours value AND a project string.
 */
function timeUploadConfidence(array $lines): float
{
    if (empty($lines)) return 0.0;
    $good = 0;
    foreach ($lines as $l) {
        $hasDate    = !empty($l['work_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $l['work_date']);
        $hasHours   = !empty($l['hours']) && (float) $l['hours'] > 0;
        $hasProject = !empty($l['project']);
        if ($hasDate && $hasHours && $hasProject) $good++;
    }
    return round($good / count($lines), 3);
}
