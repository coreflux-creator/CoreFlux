<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fail = 0;
$a = function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? "OK  " : "FAIL ") . $label . PHP_EOL;
    if (!$ok) $fail++;
};
$c = fn (string $path): string => (string) file_get_contents($root . '/' . $path);

echo "Process alignment smoke\n";

$magic = $c('api/auth/request_magic_link.php');
$a('magic link sends through mailerSend', str_contains($magic, 'mailerSend(['));
$a('magic link resolves tenant when omitted', str_contains($magic, '_magicLinkResolveTenantId($email)'));
$a('magic link no longer calls MailService envelope directly', !str_contains($magic, '$mail->send($envelope)'));

$setup = $c('modules/people/api/send_setup_email.php');
$a('people setup email uses canonical mailerSend', str_contains($setup, 'mailerSend(['));
$a('people setup email records soft mail failure as API failure', str_contains($setup, "if (!(\$result['ok'] ?? false))"));
$a('people setup email does not report log-only as sent', str_contains($setup, "(\$result['driver'] ?? '') === 'log'"));

$placementLib = $c('modules/placements/lib/placements.php');
foreach (['client_id', 'billing_cycle_id', 'ap_cycle_id', 'payroll_cycle_id'] as $field) {
    $a("placement read model includes {$field}", str_contains($placementLib, "'{$field}'"));
}
$a('placement list joins canonical companies', str_contains($placementLib, 'LEFT JOIN companies ec'));
$a('placement list exposes display client name', str_contains($placementLib, 'end_client_display_name'));

$placementApi = $c('modules/placements/api/placements.php');
$a('placement API loads staffing client bridge', str_contains($placementApi, "/../../staffing/lib/clients.php"));
$a('placement create/update ensures staffing client bridge', substr_count($placementApi, 'staffingClientEnsureForCompany(') >= 2);
$a('placement writes client_id from bridge', str_contains($placementApi, "\$insert['client_id'] = \$clientRef['client_id'];")
    && str_contains($placementApi, "\$body['client_id'] = \$clientRef['client_id'];"));

$staffingLib = $c('modules/staffing/lib/clients.php');
$a('staffing bridge helper exists', str_contains($staffingLib, 'function staffingClientEnsureForCompany'));
$a('staffing bridge treats companies as canonical', str_contains($staffingLib, 'companiesUpsertByName') && str_contains($staffingLib, 'companiesAddRole'));

$staffingMigration = $c('modules/staffing/migrations/005_company_bridge.sql');
$a('staffing clients bridge migration adds company_id', str_contains($staffingMigration, 'ADD COLUMN company_id'));
$a('staffing clients bridge backfills from companies', str_contains($staffingMigration, 'JOIN companies c'));
$a('AP cycle index exists', str_contains($staffingMigration, 'idx_pl_ap_cycle'));

$settlementLib = $c('modules/time/lib/settlement.php');
$a('settlement selects explicit placement cycle ids', str_contains($settlementLib, 'p.billing_cycle_id, p.ap_cycle_id, p.payroll_cycle_id'));
$a('settlement joins payroll cycle schedules for all targets', str_contains($settlementLib, 'bc.id = p.billing_cycle_id')
    && str_contains($settlementLib, 'ac.id = p.ap_cycle_id')
    && str_contains($settlementLib, 'pc.id = p.payroll_cycle_id'));
$a('settlement exposes cycle resolver helper', str_contains($settlementLib, 'function timeSettlementCycleForTarget'));

$settlementApi = $c('modules/time/api/settlement.php');
$a('settlement API uses cycle resolver', substr_count($settlementApi, 'timeSettlementCycleForTarget($target, $r)') >= 2);
$a('settlement API exposes cycle provenance', str_contains($settlementApi, "'cycle_source'"));

$cyclesApi = $c('modules/payroll/api/cycles.php');
$a('cycle reads are visible to process consumers', str_contains($cyclesApi, "rbac_legacy_can(\$user, 'placements.view')")
    && str_contains($cyclesApi, "rbac_legacy_can(\$user, 'billing.view')")
    && str_contains($cyclesApi, "rbac_legacy_can(\$user, 'ap.view')"));
$a('cycle mutations remain gated to cycle managers', substr_count($cyclesApi, "rbac_legacy_require(\$user, 'payroll.cycles.manage')") >= 5);

$usersApi = $c('api/users.php');
$usersUi = $c('dashboard/src/pages/UsersAdmin.jsx');
$a('password reset accepts POST fallback server-side', str_contains($usersApi, "\$method === 'POST' && \$action === 'password'"));
$a('password reset UI retries POST after PATCH failure', str_contains($usersUi, 'api.patch(`/api/users.php?id=${user.id}&action=password`')
    && str_contains($usersUi, 'api.post(`/api/users.php?id=${user.id}&action=password`'));

exit($fail ? 1 : 0);
