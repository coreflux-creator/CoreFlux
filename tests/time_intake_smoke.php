<?php
/**
 * Time — Email intake (poll path + webhook path) smoke.
 *
 * Inbound timesheets reach the Time module via two paths that converge
 * on `time_intake_events` + `time_uploaded_documents`:
 *   1. M365/Gmail OAuth poll via Core\MailService::poll_folder()
 *   2. SendGrid Inbound Parse / Postmark webhook (HMAC-verified)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; } else { echo "  ✗ {$name}\n"; $fail++; }
};
$lint = function (string $path): bool {
    $rc = 0; @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $_, $rc); return $rc === 0;
};

// ─── Migration 005 ───
echo "Time intake — migration 005\n";
$mig = file_get_contents(__DIR__ . '/../modules/time/migrations/005_intake_events.sql');
$assert('creates time_intake_events',                   strpos($mig, 'CREATE TABLE IF NOT EXISTS time_intake_events') !== false);
$assert('source enum spans poll + webhook + sms',       strpos($mig, "ENUM('poll_m365','poll_gmail','poll_imap','webhook_sendgrid','webhook_postmark','webhook_generic','sms_twilio')") !== false);
$assert('status enum',                                  strpos($mig, "ENUM('received','downloaded','extracted','dismissed','failed')") !== false);
$assert('unique on (tenant, source, provider_msg_id)',  strpos($mig, 'uq_tie_provider_msg') !== false);
$assert('adds tenants.time_intake_email_address',       strpos($mig, 'time_intake_email_address') !== false);
$assert('adds tenants.time_intake_webhook_secret',      strpos($mig, 'time_intake_webhook_secret') !== false);

// ─── Lib ───
echo "Time intake — lib\n";
$lib = file_get_contents(__DIR__ . '/../modules/time/lib/intake.php');
$assert('timeIntakeRecordEvent() — idempotent on (provider_msg_id)', strpos($lib, "uq_tie_provider_msg") === false && strpos($lib, "WHERE tenant_id = :t AND source = :s AND provider_message_id = :pm LIMIT 1") !== false);
$assert('timeIntakeIngestAttachments()',                strpos($lib, 'function timeIntakeIngestAttachments') !== false);
$assert('uses bulk schema for AI extract',              strpos($lib, '"people":[{"person_name":string') !== false);
$assert('feature_key time.timesheet.from_intake',       strpos($lib, "'time.timesheet.from_intake'") !== false);
$assert('marks intake row extracted on success',        strpos($lib, "'extracted'") !== false);
$assert('marks intake row failed when no docs',         strpos($lib, "\$docIds ? 'extracted' : 'failed'") !== false);
$assert('skips signature gifs / .ics',                  strpos($lib, 'function timeIntakeIsTimesheetAttachment') !== false);
$assert('HMAC verify helper',                           strpos($lib, 'function timeIntakeVerifyWebhookHmac') !== false);
$assert('tenant lookup from to-address (plus addressing)', strpos($lib, '\\+t(\\d+)') !== false);
$assert('audit time.intake.received',                   strpos($lib, 'time.intake.received') !== false);
$assert('audit time.intake.parsed',                     strpos($lib, 'time.intake.parsed') !== false);
$assert('PHP parses cleanly',                           $lint(__DIR__ . '/../modules/time/lib/intake.php'));

// ─── upload helpers extracted to lib ───
echo "Time intake — upload helpers extracted\n";
$helpers = file_get_contents(__DIR__ . '/../modules/time/lib/upload_helpers.php');
$assert('lib/upload_helpers.php created',               strlen($helpers) > 0);
$assert('timeUploadResolvePeople in helper lib',        strpos($helpers, 'function timeUploadResolvePeople') !== false);
$assert('timeUploadConfidence in helper lib',           strpos($helpers, 'function timeUploadConfidence') !== false);
$apiUp = file_get_contents(__DIR__ . '/../modules/time/api/upload.php');
$assert('api/upload.php no longer redeclares helpers',  strpos($apiUp, 'function timeUploadResolvePeople') === false);
$assert('api/upload.php requires upload_helpers lib',   strpos($apiUp, 'lib/upload_helpers.php') !== false);

// ─── API ───
echo "Time intake — /api/time/intake.php\n";
$api = file_get_contents(__DIR__ . '/../modules/time/api/intake.php');
$assert('?action=webhook (NO auth, runs before api_require_auth)', strpos($api, "action === 'webhook'") !== false && strpos(substr($api, 0, strpos($api, "api_require_auth")), "action === 'webhook'") !== false);
$assert('handles SendGrid Inbound Parse multipart',     strpos($api, "provider === 'sendgrid'") !== false && strpos($api, "_FILES[\"attachment{\$i}\"]") !== false);
$assert('handles Postmark JSON body',                   strpos($api, "provider === 'postmark'") !== false && strpos($api, "Attachments'") !== false);
$assert('handles generic JSON',                         strpos($api, "provider === 'generic'") !== false || strpos($api, "Generic JSON") !== false);
$assert('strips Name <addr> form',                      strpos($api, '<([^>]+)>') !== false);
$assert('verifies HMAC via X-CF-Intake-Signature',      strpos($api, 'HTTP_X_CF_INTAKE_SIGNATURE') !== false);
$assert('?action=poll calls MailService::poll_folder',  strpos($api, 'poll_folder') !== false);
$assert('?action=process re-fetches via driver',        strpos($api, "action === 'process'") !== false);
$assert('?action=dismiss marks dismissed',              strpos($api, "action === 'dismiss'") !== false);
$assert('returns intake_id on webhook success',         strpos($api, "'intake_id' => \$intakeId") !== false);
$assert('returns 200 on tenant-not-found (no retry)',   strpos($api, 'no tenant for to=') !== false);
$assert('best-effort ack reply via MailService',        strpos($api, "MailService::getInstance()->send") !== false);
$assert('PHP parses cleanly',                           $lint(__DIR__ . '/../modules/time/api/intake.php'));

// ─── Manifest ───
echo "Time intake — manifest declarations\n";
$man = file_get_contents(__DIR__ . '/../modules/time/manifest.php');
$assert('action Intake Queue route',                    strpos($man, "'route' => 'intake'") !== false);
// time.intake.received / parsed audits already declared from before.
$assert('time.intake.received audit declared',          strpos($man, 'time.intake.received') !== false);
$assert('time.intake.dismissed audit declared',         strpos($man, 'time.intake.dismissed') !== false);

// ─── Sender auto-resolve ───
echo "Time intake — sender auto-resolve\n";
$assert('timeIntakeResolveSenderContext() helper',      strpos($lib, 'function timeIntakeResolveSenderContext') !== false);
$assert('joins users → people via email_primary',       strpos($lib, 'LOWER(p.email_primary) = LOWER(u.email)') !== false);
$assert('returns user_id + person_id + person_name',    strpos($lib, "'user_id' => null, 'person_id' => null, 'person_name' => null") !== false);

$assert('timeIntakeEnrichDraftWithSender() helper',     strpos($lib, 'function timeIntakeEnrichDraftWithSender') !== false);
$assert('queries active placements for sender',         strpos($lib, "FROM placements") !== false && strpos($lib, "status = 'active'") !== false);
$assert('prepends sender to match_candidates',          strpos($lib, 'array_unshift($group[\'match_candidates\']') !== false);
$assert('flags auto_resolved_from_sender on candidate', strpos($lib, "'auto_resolved_from_sender' => true") !== false);
$assert('single-placement → fills every line hint',     strpos($lib, '$singlePlacement') !== false);
$assert('fuzzy match project text → placement title',   strpos($lib, "str_contains(\$hay, \$proj)") !== false);
$assert('stamps draft.sender_resolved = true',          strpos($lib, "\$draft['sender_resolved'] = true") !== false);
$assert('stamps draft.sender_person_id',                strpos($lib, "sender_person_id") !== false);

$lib2 = file_get_contents(__DIR__ . '/../modules/time/lib/intake.php');
$assert('ingest pulls from_address from intake row',    strpos($lib2, "SELECT from_address FROM time_intake_events") !== false);
$assert('ingest calls enrich when sender resolves',     strpos($lib2, 'timeIntakeEnrichDraftWithSender') !== false);
$assert('ingest defaults uploaded_by to sender user_id',strpos($lib2, "uploadedByUserId = (int) \$senderCtx['user_id']") !== false);

// ─── Manual upload also enriches via current user ───
$apiUp2 = file_get_contents(__DIR__ . '/../modules/time/api/upload.php');
$assert('manual upload includes intake helpers',        strpos($apiUp2, 'lib/intake.php') !== false);
$assert('manual upload calls enrich with current user', strpos($apiUp2, 'timeIntakeResolveSenderContext') !== false && strpos($apiUp2, 'timeIntakeEnrichDraftWithSender') !== false);

// ─── UI consumes placement_id_hint ───
$ui2 = file_get_contents(__DIR__ . '/../modules/time/ui/TimesheetUpload.jsx');
$assert('UI normaliseLine reads placement_id_hint',     strpos($ui2, 'l.placement_id_hint ? Number(l.placement_id_hint)') !== false);
$assert('UI tracks placement_auto_filled flag',         strpos($ui2, 'placement_auto_filled') !== false);
$assert('UI shows ✨ auto badge on autofilled rows',    strpos($ui2, "data-testid={`time-upload-line-\${l.tmpId}-auto`}") !== false);
$assert('UI shows sender-resolved hero badge',          strpos($ui2, 'data-testid="time-upload-sender-resolved"') !== false);
$assert('UI defaults person_id from auto_resolved_from_sender', strpos($ui2, 'auto_resolved_from_sender') !== false);
$assert('UI single-mode picks up sender_person_id',     strpos($ui2, 'draft.sender_person_id') !== false);

// ─── UI ───
echo "Time intake — UI components\n";
$mod = file_get_contents(__DIR__ . '/../modules/time/ui/TimeModule.jsx');
$assert('TimeModule imports IntakeQueue',               strpos($mod, 'import IntakeQueue') !== false);
$assert('TimeModule routes /intake',                    strpos($mod, 'path="intake"') !== false);

$ui = file_get_contents(__DIR__ . '/../modules/time/ui/IntakeQueue.jsx');
$assert('IntakeQueue page testid',                      strpos($ui, 'data-testid="time-intake-queue"') !== false);
$assert('IntakeQueue poll button',                      strpos($ui, 'data-testid="time-intake-poll"') !== false);
$assert('IntakeQueue empty state',                      strpos($ui, 'data-testid="time-intake-empty"') !== false);
$assert('IntakeQueue per-row dismiss',                  strpos($ui, 'time-intake-dismiss-${r.id}') !== false);
$assert('IntakeQueue per-row reprocess',                strpos($ui, 'time-intake-reprocess-${r.id}') !== false);
$assert('IntakeQueue links to upload?doc=ID',           strpos($ui, '/modules/time/upload?doc=') !== false);

echo "\nPass: {$pass}\nFail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
