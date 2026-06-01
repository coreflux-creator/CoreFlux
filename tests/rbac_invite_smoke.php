<?php
/**
 * RBAC Invite-by-email smoke — backend handler shape + frontend wiring.
 *
 * No live DB: this is a static-analysis smoke. The handler is exercised
 * end-to-end by the e2e suite once the deployment ships.
 *
 *   php -d zend.assertions=1 /app/tests/rbac_invite_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ------------------------------------------------------------------ backend
echo "api/admin/memberships.php — invite handler\n";
$mems = (string) file_get_contents($ROOT . '/api/admin/memberships.php');
$a('action=invite branch present',
    $c($mems, "(string) (api_query('action') ?? '') === 'invite'"));
$a('loads magic_link.php',                $c($mems, "require_once __DIR__ . '/../../core/magic_link.php'"));
$a('loads mailer.php',                    $c($mems, "require_once __DIR__ . '/../../core/mailer.php'"));
$a('validates email with FILTER_VALIDATE_EMAIL', $c($mems, 'FILTER_VALIDATE_EMAIL'));
$a('JIT-creates the user',                $c($mems, 'SHOW COLUMNS FROM users'));
$a('schema-tolerant: handles name column',          $c($mems, "if (in_array('name', \$cols, true))"));
$a('schema-tolerant: handles is_active flag',       $c($mems, "if (in_array('is_active', \$cols, true))"));
$a('seeds placeholder password for NOT NULL cols',  $c($mems, 'password_hash(bin2hex(random_bytes(16))'));
$a('upserts membership in pending state', $c($mems, '"pending"') && $c($mems, 'invited_by_user_id, invited_at'));
$a('un-revokes via IF on status',         $c($mems, 'IF(status = "revoked", "pending", status)'));
$a('seeds module grants via RBACResolver::grantModule',
    $c($mems, 'RBACResolver::grantModule('));
$a('issues magic link via magicLinkIssue', $c($mems, 'magicLinkIssue('));
$a('builds link URL via magicLinkUrl',     $c($mems, 'magicLinkUrl('));
$a('sends mail via mailerSend',            $c($mems, "'purpose'   => 'membership_invite'"));
$a('audits with action="invited"',         $c($mems, "RBACResolver::auditMembership(\$membershipId, 'invited'"));
$a('returns 201 on success',               $c($mems, 'api_ok($resp, 201)'));
$a('TTL clamps 15min..30d',                $c($mems, 'max(15, min(60 * 24 * 30,'));
$a('global-admin gets magic_link_url',     $c($mems, "if (\$isGlobalAdmin) \$resp['magic_link_url'] = \$linkUrl"));

// ------------------------------------------------------------------ consume
echo "\napi/auth/consume_magic_link.php — invite-accept hook\n";
$consume = (string) file_get_contents($ROOT . '/api/auth/consume_magic_link.php');
$a('stamps accepted_at on consume',
    $c($consume, 'SET accepted_at = NOW(), status = "active"'));
$a('audits invite_accepted via RBACResolver',
    $c($consume, "RBACResolver::auditMembership(\$mid, 'invite_accepted'"));
$a('matches only pending/suspended invites',
    $c($consume, 'status IN ("pending","suspended")'));
$a('JIT user-create is schema-tolerant',
    $c($consume, 'SHOW COLUMNS FROM users'));
$a('handles missing first_name/last_name on hydrate',
    $c($consume, 'SELECT * FROM users WHERE id = :id'));
$a('disabled check tolerates is_active=0',
    $c($consume, "(isset(\$user['is_active']) && (int) \$user['is_active'] === 0)"));

// ------------------------------------------------------------------ syntax
echo "\nSyntax sanity\n";
foreach ([
    '/api/admin/memberships.php',
    '/api/auth/consume_magic_link.php',
] as $rel) {
    $rc = 0; $o = [];
    exec('php -l ' . escapeshellarg($ROOT . $rel) . ' 2>&1', $o, $rc);
    $a("php -l {$rel}", $rc === 0);
}

// ------------------------------------------------------------------ frontend
echo "\ndashboard/src/pages/RbacMembershipsAdmin.jsx — invite UI\n";
$jsx = (string) file_get_contents($ROOT . '/dashboard/src/pages/RbacMembershipsAdmin.jsx');
$a('imports Mail icon',                    $c($jsx, "Mail } from 'lucide-react'"));
$a('declares InviteForm component',        $c($jsx, 'function InviteForm('));
$a('Invite button has testid',             $c($jsx, 'data-testid="memberships-invite-btn"'));
$a('InviteForm POSTs to action=invite',    $c($jsx, "'/api/admin/memberships.php?action=invite'"));
$a('InviteForm exposes email input',       $c($jsx, 'data-testid="invite-email"'));
$a('InviteForm exposes ttl selector',      $c($jsx, 'data-testid="invite-ttl"'));
$a('InviteForm exposes send button',       $c($jsx, 'data-testid="invite-form-send"'));
$a('InviteForm renders result card',       $c($jsx, 'data-testid="invite-result"'));
$a('Result card surfaces mailer driver',   $c($jsx, 'data-testid="invite-result-summary"'));

// ------------------------------------------------------------------ summary
echo "\n=========================================\n";
echo "RBAC invite smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
