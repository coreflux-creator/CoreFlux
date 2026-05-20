<?php
/**
 * Time Module — tokenized client-approval helpers (SPEC §3.6, §5.5).
 *
 * Security model:
 *   - token = 64 URL-safe random chars (bin2hex of 32 random bytes)
 *   - token_hash = raw sha256(token) stored as VARBINARY(64)
 *   - DB lookups go through token_hash; the raw token is only emailed to the
 *     client approver and never re-stored server-side after issuance.
 *   - One-time use: response flips the row from 'pending' to terminal state.
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';

function timeTokenGenerate(): array
{
    $raw  = bin2hex(random_bytes(32)); // 64 chars
    $hash = hash('sha256', $raw, true); // 32 bytes raw
    return ['token' => $raw, 'hash' => $hash];
}

function timeTokenHash(string $raw): string
{
    return hash('sha256', $raw, true);
}

/** Fetch by raw token (no tenant scope — public respond endpoint). */
function timeTokenFindByRaw(string $raw): ?array
{
    $pdo = getDB();
    if (!$pdo) return null;
    // tenant-leak-allow: token_hash is a 256-bit random secret; row carries tenant_id
    $stmt = $pdo->prepare(
        'SELECT * FROM time_approval_tokens WHERE token_hash = :h LIMIT 1'
    );
    $stmt->bindValue('h', timeTokenHash($raw), \PDO::PARAM_LOB);
    $stmt->execute();
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Entry list for building the email body + auditing. */
function timeTokenCollectEntries(int $placementId, int $periodId, array $entryIds): array
{
    if (empty($entryIds)) return [];
    $placeholders = [];
    $params = ['pid' => $placementId, 'per' => $periodId];
    foreach (array_values($entryIds) as $i => $id) {
        $key = 'e' . $i;
        $placeholders[] = ':' . $key;
        $params[$key] = (int) $id;
    }
    $sql = 'SELECT te.id, te.work_date, te.category, te.hours, te.description
            FROM time_entries te
            WHERE te.tenant_id = :tenant_id
              AND te.placement_id = :pid
              AND te.period_id = :per
              AND te.status = "pending_review"
              AND te.id IN (' . implode(',', $placeholders) . ')
            ORDER BY te.work_date, te.id';
    return scopedQuery($sql, $params);
}

function timeTokenBuildEmailBody(array $token, array $entries, array $placement, string $approveUrl, string $rejectUrl): array
{
    $total = 0.0;
    $byDay = [];
    foreach ($entries as $e) {
        $total += (float) $e['hours'];
        $byDay[$e['work_date']] = ($byDay[$e['work_date']] ?? 0) + (float) $e['hours'];
    }
    $placementTitle = $placement['title'] ?? ('Placement #' . (int) $token['placement_id']);
    $consultant     = trim(($placement['first_name'] ?? '') . ' ' . ($placement['last_name'] ?? '')) ?: 'the consultant';

    $text  = "Hi,\n\n";
    $text .= "Please review and approve the timesheet below for {$consultant} on {$placementTitle}.\n\n";
    $text .= "Total hours: " . number_format($total, 2) . "\n";
    foreach ($byDay as $d => $h) {
        $text .= "  {$d}: " . number_format($h, 2) . " hrs\n";
    }
    $text .= "\nApprove: {$approveUrl}\n";
    $text .= "Reject:  {$rejectUrl}\n\n";
    $text .= "This link is single-use and expires on " . ($token['expires_at'] ?? '') . ".\n";
    $text .= "If you didn't expect this email, please ignore it.\n";

    $safeTitle = htmlspecialchars($placementTitle, ENT_QUOTES, 'UTF-8');
    $safeWho   = htmlspecialchars($consultant, ENT_QUOTES, 'UTF-8');
    $rowsHtml = '';
    foreach ($byDay as $d => $h) {
        $rowsHtml .= "<tr><td style='padding:4px 12px;border-bottom:1px solid #eee'>{$d}</td><td style='padding:4px 12px;border-bottom:1px solid #eee;text-align:right'>" . number_format($h, 2) . "</td></tr>";
    }
    $html  = "<div style='font-family:system-ui,Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#111'>";
    $html .= "<h2 style='margin:0 0 8px'>Timesheet approval requested</h2>";
    $html .= "<p style='margin:0 0 16px;color:#555'>Please review and approve the timesheet below for <strong>{$safeWho}</strong> on <strong>{$safeTitle}</strong>.</p>";
    $html .= "<table style='width:100%;border-collapse:collapse;font-size:14px;margin-bottom:16px'><thead><tr><th style='text-align:left;padding:4px 12px;background:#f6f7f9'>Day</th><th style='text-align:right;padding:4px 12px;background:#f6f7f9'>Hours</th></tr></thead><tbody>{$rowsHtml}<tr><td style='padding:8px 12px;font-weight:600'>Total</td><td style='padding:8px 12px;text-align:right;font-weight:600'>" . number_format($total, 2) . "</td></tr></tbody></table>";
    $html .= "<div style='margin:16px 0'><a href='" . htmlspecialchars($approveUrl, ENT_QUOTES, 'UTF-8') . "' style='background:#047857;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block;margin-right:8px'>Approve</a>";
    $html .= "<a href='" . htmlspecialchars($rejectUrl, ENT_QUOTES, 'UTF-8') . "' style='background:#fff;color:#b91c1c;padding:10px 18px;border-radius:6px;text-decoration:none;display:inline-block;border:1px solid #b91c1c'>Reject</a></div>";
    $html .= "<p style='font-size:12px;color:#888;margin-top:24px'>Single-use link. Expires on " . htmlspecialchars((string) ($token['expires_at'] ?? ''), ENT_QUOTES, 'UTF-8') . ".</p>";
    $html .= "</div>";

    return [
        'subject' => "Timesheet approval: {$placementTitle} — " . number_format($total, 2) . ' hrs',
        'text'    => $text,
        'html'    => $html,
        'total'   => round($total, 2),
    ];
}
