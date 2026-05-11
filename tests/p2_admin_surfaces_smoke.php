<?php
/**
 * Smoke: P2 Admin Surfaces batch.
 *
 *   1) Client AR contacts admin (UI + API)
 *   2) Vendor PWP toggle (API + VendorsList row toggle)
 *   3) Tenant-configurable AP weekly digest schedule (migration + API + UI + cron)
 *   4) Approve-with-comment landing page (note prompt → confirm flow)
 *
 * Static contract checks — no DB connection required.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$parses = fn (string $p): bool => is_file($p)
    && (int) shell_exec('php -l ' . escapeshellarg($p) . ' >/dev/null 2>&1; echo $?') === 0;

/* ────────────────────────────────────────────────────────────────────── */
echo "1) Client AR contacts admin\n";

$apiPath = __DIR__ . '/../modules/billing/api/client_contacts.php';
$apiSrc  = (string) file_get_contents($apiPath);
$a('API file parses',                                  $parses($apiPath));
$a('GET requires billing.view',                        str_contains($apiSrc, "RBAC::requirePermission(\$user, 'billing.view')"));
$a('write requires billing.invoice.create',            str_contains($apiSrc, "RBAC::requirePermission(\$user, 'billing.invoice.create')"));
$a('GET returns rows array',                           str_contains($apiSrc, "api_ok(['rows' => \$rows])"));
$a('GET supports search (?q)',                         str_contains($apiSrc, "client_name LIKE :q"));
$a('POST upserts ON DUPLICATE KEY UPDATE',             str_contains($apiSrc, 'ON DUPLICATE KEY UPDATE'));
$a('POST validates email fields',                      str_contains($apiSrc, "FILTER_VALIDATE_EMAIL"));
$a('POST records updated_by_user_id',                  str_contains($apiSrc, 'updated_by_user_id'));
$a('?action=delete supported',                         str_contains($apiSrc, "\$method === 'POST' && \$action === 'delete'"));
$a('delete is tenant-scoped',                          str_contains($apiSrc, 'WHERE tenant_id = :t AND id = :id'));

$uiPath = __DIR__ . '/../modules/billing/ui/ClientContacts.jsx';
$ui     = (string) file_get_contents($uiPath);
$a('UI file exists',                                   is_file($uiPath));
foreach ([
    'billing-client-contacts',
    'billing-client-contacts-search',
    'billing-client-contacts-new',
    'billing-client-contacts-table',
    'billing-client-contact-modal',
    'billing-client-contact-form-name',
    'billing-client-contact-form-primary',
    'billing-client-contact-form-escalation',
    'billing-client-contact-form-save',
] as $tid) {
    $a("testid: {$tid}",                               str_contains($ui, "data-testid=\"{$tid}\""));
}
$a('UI posts to client_contacts.php',                  str_contains($ui, '/modules/billing/api/client_contacts.php'));
$a('UI supports delete with action=delete',            str_contains($ui, "action=delete&id="));

$bm = (string) file_get_contents(__DIR__ . '/../modules/billing/ui/BillingModule.jsx');
$a('BillingModule imports ClientContacts',             str_contains($bm, "import ClientContacts from './ClientContacts'"));
$a('BillingModule routes /clients → ClientContacts',   str_contains($bm, '<Route path="clients" element={<ClientContacts />}'));
$a('BillingModule nav has "Client contacts"',          str_contains($bm, "label: 'Client contacts'"));

/* ────────────────────────────────────────────────────────────────────── */
echo "\n2) Vendor Pay-When-Paid toggle\n";

$vPath = __DIR__ . '/../modules/ap/api/vendors.php';
$v     = (string) file_get_contents($vPath);
$a('vendors.php parses',                               $parses($vPath));
$a("POST ?action=toggle_pwp branch present",           str_contains($v, "\$method === 'POST' && (\$_GET['action'] ?? '') === 'toggle_pwp'"));
$a('toggle_pwp requires ap.bill.create',               preg_match('/toggle_pwp.*?RBAC::requirePermission\(\$user, \'ap\.bill\.create\'\)/s', $v) === 1);
$a('toggle_pwp updates default_pwp column',            str_contains($v, "UPDATE ap_vendors_index SET default_pwp = :p"));
$a('toggle_pwp tenant-scoped',                         str_contains($v, "WHERE tenant_id = :t AND id = :id"));
$a('toggle_pwp surfaces 409 if migration missing',     str_contains($v, "Pay-When-Paid not enabled for this tenant — run the AP module migration first.")
                                                       && str_contains($v, 'api_error(') && str_contains($v, ', 409)'));
$a('toggle_pwp emits audit event',                     str_contains($v, "apAudit('ap.vendor.default_pwp_set'"));
$a('vendors list selects default_pwp',                 str_contains($v, 'COALESCE(v.default_pwp, 0) AS default_pwp'));

$vl = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/VendorsList.jsx');
$a('VendorsList shows PWP column header',              str_contains($vl, '<th>PWP?</th>'));
$a('VendorsList row toggles via toggle_pwp',           str_contains($vl, "action=toggle_pwp&id="));

/* ────────────────────────────────────────────────────────────────────── */
echo "\n3) Weekly AP digest schedule\n";

$migPath = __DIR__ . '/../modules/ap/migrations/018_weekly_queue_schedule.sql';
$mig     = (string) file_get_contents($migPath);
$a('migration file exists',                            is_file($migPath));
$a('adds weekly_queue_email_dow',                      str_contains($mig, "COLUMN_NAME='weekly_queue_email_dow'"));
$a('adds weekly_queue_email_hour',                     str_contains($mig, "COLUMN_NAME='weekly_queue_email_hour'"));
$a('default dow = 7 (Sunday)',                         str_contains($mig, 'NOT NULL DEFAULT 7'));
$a('default hour = 22',                                str_contains($mig, 'NOT NULL DEFAULT 22'));
$a('idempotent via information_schema',                substr_count($mig, 'information_schema') >= 2);

$sPath = __DIR__ . '/../modules/ap/api/weekly_queue_settings.php';
$s     = (string) file_get_contents($sPath);
$a('settings API parses',                              $parses($sPath));
$a('GET requires ap.bill.view',                        str_contains($s, "RBAC::requirePermission(\$user, 'ap.bill.view')"));
$a('GET returns dow + hour + can_write',               str_contains($s, "'dow'") && str_contains($s, "'hour'") && str_contains($s, "'can_write'"));
$a('GET tolerates missing migration (try/catch)',      str_contains($s, '/* migration not applied yet */'));
$a('POST gates by admin/manager role',                 str_contains($s, "Admin/manager role required"));
$a('POST validates dow 0..7',                          str_contains($s, 'dow must be 0..7'));
$a('POST validates hour 0..23',                        str_contains($s, 'hour must be 0..23'));
$a('POST upserts ON DUPLICATE KEY UPDATE',             str_contains($s, 'ON DUPLICATE KEY UPDATE'));

$cron = (string) file_get_contents(__DIR__ . '/../scripts/ap_weekly_queue_sunday.php');
$a('cron parses',                                      $parses(__DIR__ . '/../scripts/ap_weekly_queue_sunday.php'));
$a('cron reads weekly_queue_email_dow from settings',  str_contains($cron, 'weekly_queue_email_dow'));
$a('cron reads weekly_queue_email_hour from settings', str_contains($cron, 'weekly_queue_email_hour'));
$a('cron skips when dow = 0 (disabled)',               preg_match('/dow\s*===?\s*0|dow\s*==\s*0/', $cron) === 1);

$st = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/Settings.jsx');
$a('AP Settings has weekly-queue fieldset',            str_contains($st, 'data-testid="ap-settings-weekly-queue"'));
$a('AP Settings has dow selector',                     str_contains($st, 'data-testid="ap-settings-weekly-queue-dow"'));
$a('AP Settings has hour input',                       str_contains($st, 'data-testid="ap-settings-weekly-queue-hour"'));
$a('AP Settings has save button',                      str_contains($st, 'data-testid="ap-settings-weekly-queue-save"'));
$a('AP Settings posts to weekly_queue_settings',       str_contains($st, '/modules/ap/api/weekly_queue_settings.php'));

/* ────────────────────────────────────────────────────────────────────── */
echo "\n4) Approve-with-comment email landing page\n";

$aePath = __DIR__ . '/../api/ap/approve_by_email.php';
$ae     = (string) file_get_contents($aePath);
$a('approve_by_email parses',                          $parses($aePath));
$a('accepts ?note=… param',                            str_contains($ae, "\$_GET['note']"));
$a('accepts ?confirm=1 to finalise',                   str_contains($ae, "\$_GET['confirm']"));
$a('note-prompt shown for reject (always)',            str_contains($ae, "\$action === 'reject' || (\$action === 'approve' && \$note === null)"));
$a('renders ap-email-approval-note-prompt',            str_contains($ae, 'data-testid="ap-email-approval-note-prompt"'));
$a('renders note input',                               str_contains($ae, 'data-testid="ap-email-approval-note-input"'));
$a('renders submit button',                            str_contains($ae, 'data-testid="ap-email-approval-note-submit"'));
$a('renders "Skip note & approve" link',               str_contains($ae, 'data-testid="ap-email-approval-skip-note"'));
$a('reject prompt marks textarea required',            str_contains($ae, "\$action === 'reject' ? 'required' : ''"));
$a('passes note through to consume()',                 str_contains($ae, 'apEmailApprovalConsume($rawToken, $action, $note, $ip)'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
