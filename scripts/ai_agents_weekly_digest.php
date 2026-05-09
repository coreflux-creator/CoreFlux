<?php
/**
 * Weekly AI Agent digest cron (Sprint 7g — Slice 3).
 *
 * Runs DAILY but only fires for tenants whose `ai_agent_digest_settings.send_dow`
 * matches today's day-of-week AND who haven't been sent a digest in the last
 * 6 days (idempotency belt). Iterates every digest-enabled tenant, runs all
 * 5 agents, builds the HTML, and ships it via the existing tenant Resend
 * pipeline.
 *
 * Usage:
 *   php scripts/ai_agents_weekly_digest.php
 *
 * Add to crontab (e.g. every day at 07:00 UTC):
 *   0 7 * * * /usr/bin/php /app/scripts/ai_agents_weekly_digest.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/ai_agents.php';

$dow  = (int) date('N'); // 1..7 (Mon..Sun)
$pdo  = getDB();

$rows = $pdo->prepare(
    'SELECT tenant_id
       FROM ai_agent_digest_settings
      WHERE enabled = 1
        AND send_dow = :d
        AND (last_sent_at IS NULL OR last_sent_at < DATE_SUB(NOW(), INTERVAL 6 DAY))'
);
$rows->execute(['d' => $dow]);
$tenants = $rows->fetchAll(\PDO::FETCH_COLUMN) ?: [];

$ran = 0; $failed = 0;
foreach ($tenants as $tid) {
    $tid = (int) $tid;
    try {
        $r = aiAgentDigestSend($tid, null);
        $ran++;
        echo "[ok]   tenant={$tid} message_id=" . ($r['message_id'] ?? '-') . "\n";
    } catch (\Throwable $e) {
        $failed++;
        @getDB()->prepare(
            'INSERT INTO ai_agent_digest_settings (tenant_id, last_send_error)
             VALUES (:t, :err)
             ON DUPLICATE KEY UPDATE last_send_error = VALUES(last_send_error)'
        )->execute(['t' => $tid, 'err' => substr($e->getMessage(), 0, 500)]);
        echo "[fail] tenant={$tid} error=" . $e->getMessage() . "\n";
    }
}
echo "Summary: dow={$dow} ran={$ran} failed={$failed} candidates=" . count($tenants) . "\n";
exit($failed > 0 ? 1 : 0);
