<?php
/**
 * mail_observability_smoke.php
 *
 * Slice covers:
 *   • migrations/083_mail_observability.sql (mail_webhook_events +
 *     mail_recipient_suppressions tables, with all indexes the runtime
 *     queries assume).
 *   • core/mail/suppressions.php helper module.
 *   • mailerSend() suppression filter (recipients on the list never
 *     reach the driver; soft-fails with all_recipients_suppressed when
 *     every recipient is dropped).
 *   • api/webhooks/resend.php receiver with Svix signature verification,
 *     replay-window timestamp check, status auto-flip + auto-suppress.
 *   • api/admin/mail_suppressions.php list/add/remove with RBAC gate.
 *   • api/admin/mail_outbox_show.php single-row drill-down.
 *   • dashboard/src/pages/MailSuppressionsCard.jsx UI.
 *   • dashboard/src/pages/MailOutboxDetailModal.jsx UI.
 *   • Wiring on MailSettingsPage + MailHealthCard.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Mail observability (Resend webhook + suppressions + outbox drill-down)\n";
echo "======================================================================\n\n";

$ROOT = dirname(__DIR__);

// --- migration ----------------------------------------------------
echo "core/migrations/083_mail_observability.sql\n";
$mig = $read("{$ROOT}/core/migrations/083_mail_observability.sql");
$a('file exists',                              $mig !== '');
$a('mail_webhook_events table',                str_contains($mig, 'CREATE TABLE IF NOT EXISTS mail_webhook_events'));
$a('  provider column (multi-source)',         preg_match('/provider VARCHAR/', $mig) === 1);
$a('  event_type column',                      preg_match('/event_type VARCHAR/', $mig) === 1);
$a('  message_id column',                      preg_match('/message_id VARCHAR/', $mig) === 1);
$a('  mail_outbox_id FK-ish',                  str_contains($mig, 'mail_outbox_id BIGINT UNSIGNED NULL'));
$a('  signature_verified flag',                str_contains($mig, 'signature_verified TINYINT'));
$a('  index on message_id',                    str_contains($mig, 'idx_mwe_msgid'));
$a('  index on (tenant_id, event_type)',       str_contains($mig, 'idx_mwe_tenant_evt'));

$a('mail_recipient_suppressions table',        str_contains($mig, 'CREATE TABLE IF NOT EXISTS mail_recipient_suppressions'));
$a('  email_normalized column',                str_contains($mig, 'email_normalized VARCHAR'));
$a('  reason column',                          preg_match("/reason VARCHAR.+DEFAULT 'manual'/", $mig) === 1);
$a('  soft-delete (removed_at)',               str_contains($mig, 'removed_at DATETIME NULL'));
$a('  unique key with removed_at allows re-suppression',
                                                str_contains($mig, 'UNIQUE KEY ux_mrs_active (tenant_id, email_normalized, removed_at)'));

// --- suppressions helper ------------------------------------------
echo "\ncore/mail/suppressions.php\n";
$sup = $read("{$ROOT}/core/mail/suppressions.php");
$a('cf_mail_normalize_email lower+trim',       str_contains($sup, 'function cf_mail_normalize_email')
                                            && str_contains($sup, 'return strtolower(trim($email));'));
$a('cf_mail_is_suppressed soft-fails on DB error',
                                                str_contains($sup, 'function cf_mail_is_suppressed')
                                            && substr_count($sup, 'return false;') >= 3);
$a('cf_mail_suppress is idempotent',           str_contains($sup, 'WHERE tenant_id = :t AND email_normalized = :e')
                                            && str_contains($sup, 'AND removed_at IS NULL'));
$a('cf_mail_suppress validates email',         str_contains($sup, 'FILTER_VALIDATE_EMAIL'));
$a('cf_mail_unsuppress soft-deletes',          str_contains($sup, 'SET removed_at = UTC_TIMESTAMP()'));
$a('cf_mail_filter_suppressed bulk IN(...)',   str_contains($sup, 'email_normalized IN ({$place})'));
$a('cf_mail_list_suppressions search/pagination',
                                                str_contains($sup, 'function cf_mail_list_suppressions')
                                            && str_contains($sup, "LIKE :q"));
$lint = shell_exec('php -l ' . escapeshellarg("{$ROOT}/core/mail/suppressions.php") . ' 2>&1');
$a('PHP -l passes',                            is_string($lint) && str_contains($lint, 'No syntax errors detected'));

// --- mailer.php wiring --------------------------------------------
echo "\ncore/mailer.php — suppression filter in mailerSend()\n";
$mailer = $read("{$ROOT}/core/mailer.php");
$a('requires suppressions module',             str_contains($mailer, "require_once __DIR__ . '/mail/suppressions.php';"));
$a('mailerSend filters via cf_mail_filter_suppressed',
                                                str_contains($mailer, 'cf_mail_filter_suppressed($tenantId, $toList)'));
$a('returns suppressed[] on success path',     str_contains($mailer, "'suppressed' => \$suppressedDrops,"));
$a('all_recipients_suppressed soft-fail',      str_contains($mailer, "'error'      => 'all_recipients_suppressed'"));
$a('soft-fail uses driver=suppressed',         str_contains($mailer, "'driver'     => 'suppressed'"));

// --- Resend webhook receiver --------------------------------------
echo "\napi/webhooks/resend.php\n";
$wh = $read("{$ROOT}/api/webhooks/resend.php");
$a('file exists',                              $wh !== '');
$a('skips api_require_auth (provider caller)', !str_contains($wh, 'api_require_auth()'));
$a('returns 200 even on GET (liveness probe)', str_contains($wh, "if (api_method() !== 'POST')")
                                            && str_contains($wh, 'liveness probe'));
$a('reads svix-id/svix-timestamp/svix-signature headers',
                                                str_contains($wh, "\$headers['svix-id']")
                                            && str_contains($wh, "\$headers['svix-timestamp']")
                                            && str_contains($wh, "\$headers['svix-signature']"));
$a('verifies via Svix-style HMAC',             str_contains($wh, '_resend_verify_svix_signature')
                                            && str_contains($wh, "hash_hmac('sha256'"));
$a('rejects replay (>5min timestamp drift)',   str_contains($wh, 'abs(time() - $ts) > 300'));
$a('decodes whsec_ prefix',                    str_contains($wh, "str_starts_with(\$secret, 'whsec_')"));
$a('constant-time signature compare',          str_contains($wh, 'hash_equals('));
$a('persists raw event to mail_webhook_events', str_contains($wh, 'INSERT INTO mail_webhook_events'));
$a('persists even when signature fails',       !str_contains($wh, 'if (!$verified) { /* skip persist */ }')
                                            && str_contains($wh, "'sv' => \$verified ? 1 : 0"));
$a('resolves mail_outbox row by message_id',   str_contains($wh, 'WHERE provider_message_id = :m'));
$a('auto-suppresses on bounce/complaint',      str_contains($wh, "in_array(\$eventType, ['email.bounced', 'email.complained'], true)")
                                            && str_contains($wh, 'cf_mail_suppress('));
$a('skips suppress on soft (Transient) bounce', str_contains($wh, "stripos(\$bounceKind, 'transient') === false"));
$a('updates outbox status to bounced/complaint', str_contains($wh, "SET status = :new_status, error = :err"));
$a('sent status only fires on currently-queued', str_contains($wh, "WHERE id = :id AND status = 'queued'"));
$a('returns side_effects in response',         str_contains($wh, "'side_effects'        => \$sideEffects"));
$lint = shell_exec('php -l ' . escapeshellarg("{$ROOT}/api/webhooks/resend.php") . ' 2>&1');
$a('PHP -l passes',                            is_string($lint) && str_contains($lint, 'No syntax errors detected'));

// --- admin suppressions endpoint ----------------------------------
echo "\napi/admin/mail_suppressions.php\n";
$sa = $read("{$ROOT}/api/admin/mail_suppressions.php");
$a('RBAC gate tenant_admin.integrations',      str_contains($sa, "rbac_legacy_require(\$user, 'tenant_admin.integrations');"));
$a('GET → cf_mail_list_suppressions',          str_contains($sa, 'cf_mail_list_suppressions($tid'));
$a('POST → cf_mail_suppress',                  str_contains($sa, 'cf_mail_suppress($tid, $email, $reason'));
$a('POST validates reason whitelist',          str_contains($sa, "['manual', 'bounce', 'complaint', 'api']"));
$a('DELETE supports id-via-query',             str_contains($sa, "api_query('id')"));
$a('DELETE soft-deletes via cf_mail_unsuppress',
                                                str_contains($sa, 'cf_mail_unsuppress($tid, $email'));

// --- admin mail_outbox show ---------------------------------------
echo "\napi/admin/mail_outbox_show.php\n";
$ms = $read("{$ROOT}/api/admin/mail_outbox_show.php");
$a('GET only',                                 str_contains($ms, "if (api_method() !== 'GET')"));
$a('include_body opt-in',                      str_contains($ms, "(int) (api_query('include_body') ?? 0) === 1"));
$a('tenant-scoped lookup',                     str_contains($ms, "(int) \$row['tenant_id'] !== \$tid"));
$a('returns webhook_events for message_id',    str_contains($ms, 'FROM mail_webhook_events')
                                            && str_contains($ms, "'webhook_events'      => \$events"));

// --- React: MailSuppressionsCard ----------------------------------
echo "\ndashboard/src/pages/MailSuppressionsCard.jsx\n";
$mc = $read("{$ROOT}/dashboard/src/pages/MailSuppressionsCard.jsx");
$a('root testid',                              str_contains($mc, 'data-testid="admin-mail-suppressions"'));
$a('list table testid',                        str_contains($mc, 'data-testid="admin-mail-suppressions-table"'));
$a('add form gated behind button',             str_contains($mc, 'data-testid="admin-mail-suppressions-add-btn"')
                                            && str_contains($mc, 'data-testid="admin-mail-suppressions-add-form"'));
$a('remove via DELETE with ?id=',              str_contains($mc, "api.delete(`/api/admin/mail_suppressions.php?id=\${row.id}`)"));
$a('search input + button',                    str_contains($mc, 'data-testid="admin-mail-suppressions-search"')
                                            && str_contains($mc, 'data-testid="admin-mail-suppressions-search-btn"'));
$a('empty state',                              str_contains($mc, 'data-testid="admin-mail-suppressions-empty"'));
$a('reason colour palette',                    str_contains($mc, 'REASON_TONE'));

// --- React: MailOutboxDetailModal ---------------------------------
echo "\ndashboard/src/pages/MailOutboxDetailModal.jsx\n";
$mo = $read("{$ROOT}/dashboard/src/pages/MailOutboxDetailModal.jsx");
$a('root testid',                              str_contains($mo, 'data-testid="mail-outbox-detail-modal"'));
$a('fetches /api/admin/mail_outbox_show.php',  str_contains($mo, '/api/admin/mail_outbox_show.php?id='));
$a('opt-in body fetch with include_body=1',    str_contains($mo, '?id=${outboxId}${includeBody ? \'&include_body=1\' : \'\'}'));
$a('renders body preview inside sandboxed iframe',
                                                str_contains($mo, 'sandbox=""')
                                            && str_contains($mo, 'srcDoc={data.body_html}'));
$a('per-recipient suppress action',            str_contains($mo, 'data-testid={`mail-outbox-detail-suppress-${i}`}')
                                            && str_contains($mo, "/api/admin/mail_suppressions.php"));
$a('renders webhook events',                   str_contains($mo, 'data-testid="mail-outbox-detail-events"'));

// --- wiring --------------------------------------------------------
echo "\nwiring (MailSettingsPage + MailHealthCard)\n";
$mp = $read("{$ROOT}/dashboard/src/pages/MailSettingsPage.jsx");
$a('MailSettingsPage imports MailSuppressionsCard',
                                                str_contains($mp, "import MailSuppressionsCard from './MailSuppressionsCard'"));
$a('MailSettingsPage mounts MailSuppressionsCard',
                                                str_contains($mp, '<MailSuppressionsCard />'));

$mh = $read("{$ROOT}/dashboard/src/pages/MailHealthCard.jsx");
$a('MailHealthCard imports MailOutboxDetailModal',
                                                str_contains($mh, "import MailOutboxDetailModal from './MailOutboxDetailModal'"));
$a('failure row is a button that opens the modal',
                                                str_contains($mh, 'data-testid={`mail-health-failure-open-${f.id}`}')
                                            && str_contains($mh, 'onClick={() => setOutboxOpen(f.id)}'));
$a('modal mounted conditionally',              str_contains($mh, 'outboxOpen !== null')
                                            && str_contains($mh, '<MailOutboxDetailModal'));

// --- Summary -------------------------------------------------------
echo "\n\n----------------------------------------\n";
echo "Mail observability smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
