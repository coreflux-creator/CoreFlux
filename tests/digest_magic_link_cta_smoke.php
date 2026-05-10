<?php
/**
 * Smoke: AI digest emails now embed one-tap magic-link CTAs.
 *
 * Verifies:
 *   - magicLinkIssue accepts an optional ttlMinutes
 *   - aiAgentDeepLink maps every agent_key → /modules/...
 *   - aiAgentBuildDigestHtml signature accepts $ctaContext
 *   - Per-section CTA + master CTA only render when ctaContext is supplied
 *   - aiAgentDigestSend renders + sends PER recipient (so each gets a
 *     unique single-use link)
 *   - text body includes the URL (for plain-text mail clients)
 *   - graceful no-op when ctaContext is null (back-compat)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

echo "magic_link.php — TTL override\n";
$lib = (string) file_get_contents(__DIR__ . '/../core/magic_link.php');
$a('magicLinkIssue accepts ttlMinutes',     str_contains($lib, '?int $ttlMinutes = null'));
$a('hard cap at 14 days',                   str_contains($lib, 'min($ttlMinutes, 60 * 24 * 14)'));
$a('default falls back to const',           str_contains($lib, ': COREFLUX_MAGIC_LINK_TTL_MINUTES'));

echo "\ncore/ai_agents.php — deep-link map\n";
$src = (string) file_get_contents(__DIR__ . '/../core/ai_agents.php');
$a('aiAgentDeepLink defined',               str_contains($src, 'function aiAgentDeepLink'));
$a('bookkeeper → /modules/accounting',      str_contains($src, "'bookkeeper'           => '/modules/accounting'"));
$a('reconciliation → /bank-rec',            str_contains($src, "'reconciliation'       => '/modules/accounting/bank-rec'"));
$a('treasury_analyst → /modules/treasury',  str_contains($src, "'treasury_analyst'     => '/modules/treasury'"));
$a('cfo → /modules/reports/exec',           str_contains($src, "'cfo'                  => '/modules/reports/exec'"));
$a('cfo_variance → /modules/reports/exec',  str_contains($src, "'cfo_variance'         => '/modules/reports/exec'"));
$a('treasury_payments → /modules/treasury', str_contains($src, "'treasury_payments'    => '/modules/treasury'"));
$a('tax_mapping → /tax-mappings',           str_contains($src, "'tax_mapping'          => '/modules/accounting/tax-mappings'"));
$a('payroll_tax → /modules/payroll',        str_contains($src, "'payroll_tax'          => '/modules/payroll'"));

echo "\nbuilder accepts ctaContext\n";
$a('aiAgentBuildDigestHtml has 4th arg',    str_contains($src, '?array $ctaContext = null'));
$a('lazy-requires magic_link.php',          str_contains($src, "require_once \$fn") &&
                                            str_contains($src, "'/magic_link.php'"));
$a('CTA mints with 3-day TTL',              str_contains($src, '60 * 24 * 3'));
$a('CTA call passes recipient email',       str_contains($src, "(string) \$ctaContext['recipient_email']"));
$a('CTA call passes tenant_id',             str_contains($src, "(int) \$ctaContext['tenant_id']"));
$a('open-redirect helper still in lib',     str_contains($lib, '#^(?://|https?:)#i'));
$a('CTA mint failure logs but continues',   str_contains($src, "'[ai_agents] CTA mint failed:"));

echo "\nrender output\n";
$a('per-section CTA HTML',                  str_contains($src, 'Open " . $title . " in CoreFlux →'));
$a('per-section CTA text URL',              str_contains($src, 'Open this view: {$ctaUrl}'));
$a('master CTA HTML',                       str_contains($src, 'Open CoreFlux Dashboard →'));
$a('master CTA text URL',                   str_contains($src, 'Open the dashboard: {$masterUrl}'));
$a('expiry note in footer',                 str_contains($src, 'expire in 3 days'));

echo "\nDigestSend sends PER recipient\n";
$a('foreach recipient in send loop',        str_contains($src, "foreach (\$recipients as \$email)"));
$a('renders per recipient with ctaContext', str_contains($src, "['tenant_id' => \$tenantId, 'recipient_email' => \$email]"));
$a('per-recipient sendEmail call',          str_contains($src, "'to'         => [\$email]"));
$a('collects message_ids map',              str_contains($src, '$messageIds[$email]'));
$a('captures send_errors map',              str_contains($src, '$sendErrors[$email]'));
$a('writes last_send_error if any',         str_contains($src, "'err' => \$sendErrors ? substr"));
$a('back-compat: returns single message_id',str_contains($src, "'message_id'  => \$messageIds ? array_values"));
$a('back-compat: returns recipients',       str_contains($src, "'recipients'  => \$recipients"));

echo "\nNon-regression: builder still works without ctaContext\n";
require_once __DIR__ . '/../core/ai_agents.php';
$noCtx = aiAgentBuildDigestHtml([
    'bookkeeper' => ['ok' => true, 'envelope' => ['content' => 'Books look healthy.']],
], 'Hi there.');
$a('returns html + text keys',              isset($noCtx['html'], $noCtx['text']));
$a('renders agent body in html',            stripos($noCtx['html'], 'Books look healthy.') !== false);
$a('renders agent body in text',            stripos($noCtx['text'], 'Books look healthy.') !== false);
$a('no CTA in html (no ctaContext)',        stripos($noCtx['html'], 'Open CoreFlux Dashboard') === false);
$a('no CTA in text (no ctaContext)',        stripos($noCtx['text'], 'Open the dashboard') === false);

echo "\nbuilder with ctaContext but no DB → graceful skip\n";
// magicLinkIssue requires DB; in CLI without DB it throws. Builder should
// catch + carry on without CTAs. Simulate by passing a context with an
// invalid email so the issuer throws InvalidArgumentException.
$bad = aiAgentBuildDigestHtml(
    ['cfo' => ['ok' => true, 'envelope' => ['content' => 'CFO note.']]],
    null,
    [],
    ['tenant_id' => 1, 'recipient_email' => 'not-a-valid-email']
);
$a('graceful skip on bad email',            stripos($bad['html'], 'CFO note.') !== false);

echo "\n--- " . ($pass + $fail) . " assertions, $fail failed ---\n";
exit($fail ? 1 : 0);
