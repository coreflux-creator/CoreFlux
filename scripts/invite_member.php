<?php
/**
 * scripts/invite_member.php — fire a CoreFlux invite from the CLI.
 *
 * Mirrors `/api/admin/memberships.php?action=invite` but skips the
 * HTTP/session layer so ops can run it directly against the prod DB
 * (Cloudways SSH, cron, ad-hoc fix-up after a wipe, etc.).
 *
 * Usage:
 *   php scripts/invite_member.php \
 *       --email=jane@acme.com \
 *       --tenant=7 \
 *       --persona-type=master_admin \
 *       [--persona-label="Primary"] \
 *       [--name="Jane Doe"] \
 *       [--actor=1]            # acting admin user id (defaults to first global admin)
 *       [--ttl=10080]          # minutes; default 7 days
 *       [--redirect=/admin]    # post-consume redirect
 *       [--no-mail]            # skip mailerSend(); just print the link
 *
 * Always prints the magic-link URL on stdout so an ops engineer can
 * forward it manually if the Resend delivery bounces.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/magic_link.php';
require_once __DIR__ . '/../core/mailer.php';
require_once __DIR__ . '/../core/rbac/permissions.php';

function _arg(string $k, ?string $default = null): ?string {
    foreach ($GLOBALS['argv'] as $a) {
        if (strpos($a, "--{$k}=") === 0) return substr($a, strlen("--{$k}="));
        if ($a === "--{$k}") return '1';
    }
    return $default;
}

$email   = strtolower(trim((string) (_arg('email') ?? '')));
$tenantId = (int) (_arg('tenant') ?? 0);
$personaType  = (string) (_arg('persona-type')  ?? 'employee');
$personaLabel = (string) (_arg('persona-label') ?? 'Primary');
$nameRaw      = (string) (_arg('name', '') ?? '');
$ttlMinutes   = (int) (_arg('ttl') ?? 60 * 24 * 7);
$redirect     = (string) (_arg('redirect') ?? '/admin/memberships');
$skipMail     = (bool) _arg('no-mail');

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "ERR: --email=<valid address> is required\n");
    exit(1);
}
if ($tenantId <= 0) {
    fwrite(STDERR, "ERR: --tenant=<id> is required\n");
    exit(1);
}

$pdo = getDB();
if (!$pdo) { fwrite(STDERR, "ERR: no DB connection\n"); exit(2); }

// Resolve actor: explicit --actor flag, else the first global admin.
$actorId = (int) (_arg('actor') ?? 0);
if ($actorId <= 0) {
    $actorId = (int) ($pdo->query('SELECT id FROM users WHERE is_global_admin = 1 ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
}
if ($actorId <= 0) {
    fwrite(STDERR, "ERR: no actor user found — pass --actor=<id>\n");
    exit(3);
}

// 1) Find or JIT-create user.
[$firstName, $lastName] = (static function (string $n): array {
    if ($n === '') return ['', ''];
    $p = preg_split('/\s+/', $n, 2);
    return [(string) $p[0], (string) ($p[1] ?? '')];
})($nameRaw);

$st = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
$st->execute(['e' => $email]);
$invitedUserId = (int) ($st->fetchColumn() ?: 0);
if ($invitedUserId <= 0) {
    $ins = $pdo->prepare(
        "INSERT INTO users (email, first_name, last_name, role, status, created_at)
         VALUES (:e, :f, :l, :r, 'active', NOW())"
    );
    $ins->execute([
        'e' => $email, 'f' => $firstName, 'l' => $lastName,
        'r' => in_array($personaType, ['tenant_admin','admin','manager','employee','contractor'], true)
               ? $personaType : 'employee',
    ]);
    $invitedUserId = (int) $pdo->lastInsertId();
}

// 2) Upsert pending membership.
$up = $pdo->prepare(
    'INSERT INTO tenant_memberships
        (user_id, tenant_id, persona_label, persona_type, is_primary, status,
         invited_by_user_id, invited_at)
     VALUES (:u, :t, :pl, :pt, 0, "pending", :ib, NOW())
     ON DUPLICATE KEY UPDATE
        persona_type       = VALUES(persona_type),
        status             = IF(status = "revoked", "pending", status),
        invited_by_user_id = VALUES(invited_by_user_id),
        invited_at         = NOW()'
);
$up->execute([
    'u' => $invitedUserId, 't' => $tenantId,
    'pl' => $personaLabel, 'pt' => $personaType, 'ib' => $actorId,
]);
$find = $pdo->prepare(
    'SELECT id FROM tenant_memberships
      WHERE user_id = :u AND tenant_id = :t AND persona_label = :pl LIMIT 1'
);
$find->execute(['u' => $invitedUserId, 't' => $tenantId, 'pl' => $personaLabel]);
$membershipId = (int) $find->fetchColumn();

// 3) Issue magic link.
$link = magicLinkIssue($email, $tenantId, $redirect, '127.0.0.1', 'invite_member.php', max(15, $ttlMinutes));
$linkUrl = magicLinkUrl($link['raw_token'], rtrim((string) (getenv('CF_BASE_URL') ?: 'https://app.corefluxapp.com'), '/'));

// 4) Mail (unless suppressed).
$mailRes = ['ok' => false, 'driver' => 'skipped', 'error' => '--no-mail'];
if (!$skipMail) {
    $tName = (string) ($pdo->query('SELECT name FROM tenants WHERE id = ' . (int) $tenantId)->fetchColumn() ?: 'CoreFlux');
    $subject = "You've been invited to {$tName} on CoreFlux";
    $linkSafe = htmlspecialchars($linkUrl, ENT_QUOTES, 'UTF-8');
    $bodyHtml = "<p>Hi" . ($firstName !== '' ? ' ' . htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8') : '') . ",</p>"
              . "<p>You've been invited to join <strong>" . htmlspecialchars($tName, ENT_QUOTES, 'UTF-8') . "</strong> on CoreFlux as "
              . "<em>" . htmlspecialchars($personaLabel, ENT_QUOTES, 'UTF-8') . " ({$personaType})</em>.</p>"
              . "<p><a href=\"{$linkSafe}\" style=\"display:inline-block;padding:10px 18px;background:#1f6feb;color:#fff;text-decoration:none;border-radius:6px;\">Accept invite & sign in</a></p>"
              . "<p style=\"font-size:12px;color:#666\">Or paste this link:<br><code>{$linkSafe}</code></p>"
              . "<p style=\"font-size:12px;color:#666\">Expires {$link['expires_at']} UTC.</p>";
    $mailRes = mailerSend([
        'to' => $email, 'subject' => $subject, 'body_html' => $bodyHtml,
        'tenant_id' => $tenantId, 'module' => 'admin', 'purpose' => 'membership_invite',
    ]);
}

// 5) Audit.
try {
    RBACResolver::auditMembership($membershipId, 'invited', $actorId, [
        'email' => $email, 'persona_label' => $personaLabel, 'persona_type' => $personaType,
        'mailer_driver' => $mailRes['driver'] ?? null,
        'mailer_ok' => (bool) ($mailRes['ok'] ?? false),
        'cli' => true,
    ]);
} catch (\Throwable $_) {}

echo "============================================================\n";
echo " CoreFlux invite issued\n";
echo "============================================================\n";
echo "  email          : {$email}\n";
echo "  user_id        : {$invitedUserId}\n";
echo "  membership_id  : {$membershipId}\n";
echo "  tenant_id      : {$tenantId}\n";
echo "  persona        : {$personaLabel} ({$personaType})\n";
echo "  expires_at     : {$link['expires_at']} UTC\n";
echo "  mailer.driver  : " . ($mailRes['driver'] ?? '?') . "\n";
echo "  mailer.ok      : " . (($mailRes['ok'] ?? false) ? 'yes' : 'no') . "\n";
if (!($mailRes['ok'] ?? false) && !empty($mailRes['error'])) {
    echo "  mailer.error   : {$mailRes['error']}\n";
}
echo "------------------------------------------------------------\n";
echo "  Magic link (forward manually if mail bounced):\n";
echo "  {$linkUrl}\n";
echo "============================================================\n";
