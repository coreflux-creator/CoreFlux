<?php
/**
 * Jaz finishing-touches smoke (2026-02)
 * =====================================
 * Locks the two final Jaz items Kunal called out:
 *   1. "There's no sync button" → /api/accounting.php?action=sync_now +
 *      JazSyncNowCard with "Sync everything" / "CoA only" buttons.
 *   2. "Chart of accounts only syncs one way — why not either way?" →
 *      sync_config_service.php lifts the CoA pull-only coercion;
 *      jaz_adapter.php gains createAccount();
 *      account_mapping_service.php gains accountingAccountMappingsPushToProvider();
 *      JazSyncConfigCard UI lets operators pick push/two_way for CoA.
 *
 *   php -d zend.assertions=1 /app/tests/jaz_sync_button_and_coa_bidir_smoke.php
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ────────────────────────────────────────── 1) sync_config_service.php
echo "core/accounting/sync_config_service.php — bi-dir lift on CoA\n";
$scs = (string) file_get_contents($ROOT . '/core/accounting/sync_config_service.php');
$a('CoA pull-only restriction removed',                   !$c($scs, "\$entity === 'chart_of_accounts' && !in_array(\$val, ['pull','off']"));
$a('comment annotates the 2026-02 lift',                  $c($scs, 'CoA bi-directional lifted 2026-02') || $c($scs, 'bi-directional capable'));

$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($ROOT . '/core/accounting/sync_config_service.php') . ' 2>&1', $o, $rc);
$a('php -l sync_config_service.php',                      $rc === 0);

// ────────────────────────────────────────── 2) provider_adapter.php
echo "\ncore/accounting/provider_adapter.php — createAccount() abstract\n";
$pa = (string) file_get_contents($ROOT . '/core/accounting/provider_adapter.php');
$a('createAccount abstract added',                        $c($pa, 'abstract public function createAccount(int $tenantId, int $subTenantId, array $account, string $idempotencyKey): array'));
$a('docblock mentions provider_account_id contract',      $c($pa, 'provider_account_id'));

$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($ROOT . '/core/accounting/provider_adapter.php') . ' 2>&1', $o, $rc);
$a('php -l provider_adapter.php',                         $rc === 0);

// ────────────────────────────────────────── 3) jaz_adapter.php createAccount
echo "\ncore/accounting/jaz_adapter.php — createAccount() impl\n";
$ja = (string) file_get_contents($ROOT . '/core/accounting/jaz_adapter.php');
$a('Jaz createAccount() implementation',                  $c($ja, 'public function createAccount(int $tenantId, int $subTenantId, array $account, string $idempotencyKey): array'));
$a('rejects missing name',                                $c($ja, 'createAccount requires name'));
$a('maps CoreFlux type → Jaz accountClass TitleCase',     $c($ja, "'asset'     => 'Asset'") && $c($ja, "'expense'   => 'Expense'"));
$a('POSTs to chart-of-accounts endpoint',                 $c($ja, "jazCall(\$key, 'POST', 'chart-of-accounts'"));
$a('treats 409 as idempotent success',                    $c($ja, 'isConflict') && $c($ja, "lookup returned nothing"));
$a('normalizes response via normalizeCoaRow',             $c($ja, "\$this->normalizeCoaRow"));
$a('returns provider_account_code + name + type',         $c($ja, 'provider_account_code') && $c($ja, 'provider_account_name') && $c($ja, 'provider_account_type'));

$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($ROOT . '/core/accounting/jaz_adapter.php') . ' 2>&1', $o, $rc);
$a('php -l jaz_adapter.php',                              $rc === 0);

// ────────────────────────────────────────── 4) push-side mapper
echo "\ncore/accounting/account_mapping_service.php — push-side\n";
$ams = (string) file_get_contents($ROOT . '/core/accounting/account_mapping_service.php');
$a('accountingAccountMappingsPushToProvider exists',      $c($ams, 'function accountingAccountMappingsPushToProvider(int $tenantId, int $subTenantId, string $provider'));
$a('pre-fetches provider CoA to dedupe',                  $c($ams, '$adapter->getChartOfAccounts'));
$a('lowercases codes for case-insensitive match',         $c($ams, "strtolower((string) (\$pa['code'] ?? '')"));
$a('skips codes that already exist upstream',             $c($ams, 'isset($providerCodes[$code])'));
$a('calls adapter->createAccount for net-new rows',       $c($ams, '$adapter->createAccount'));
$a('uses idempotent key per-account',                     $c($ams, "'coa_push:' . \$tenantId . ':' . \$subTenantId"));
$a('per-account best-effort (try/catch around create)',   $c($ams, 'catch (\\Throwable $e)') && $c($ams, "errors[] = ["));
$a('returns pushed / skipped_existing / errors',          $c($ams, "'pushed'") && $c($ams, "'skipped_existing'") && $c($ams, "'errors'"));

$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($ROOT . '/core/accounting/account_mapping_service.php') . ' 2>&1', $o, $rc);
$a('php -l account_mapping_service.php',                  $rc === 0);

// ────────────────────────────────────────── 5) sync_now action
echo "\napi/accounting.php — sync_now action\n";
$apiAcc = (string) file_get_contents($ROOT . '/api/accounting.php');
$a('sync_now action registered',                          $c($apiAcc, "\$method === 'POST' && \$action === 'sync_now'"));
$a('sync_now reads sync_config first',                    $c($apiAcc, "accountingSyncConfigGet(\$tid, \$sub, \$provider)"));
$a("sync_now branches on 'pull'/'push'/'two_way'",        $c($apiAcc, "in_array(\$direction, ['pull', 'two_way'], true)") && $c($apiAcc, "in_array(\$direction, ['push', 'two_way'], true)"));
$a('sync_now calls accountingAccountMappingsAutoMap',     $c($apiAcc, "\$entityResult['pull'] = accountingAccountMappingsAutoMap"));
$a('sync_now calls push-to-provider helper',              $c($apiAcc, "accountingAccountMappingsPushToProvider"));
$a('sync_now respects entity_types filter',               $c($apiAcc, "in_array(\$entity, \$entityFilter, true)"));
$a('sync_now skips off entities',                         $c($apiAcc, "if (\$direction === 'off') continue"));
$a('async-outbox entities surface a note',                $c($apiAcc, "Streams via Command Service outbox"));
$a('returns per-entity results map',                      $c($apiAcc, "'results'   => \$results"));

$rc = 0; $o = [];
exec('php -l ' . escapeshellarg($ROOT . '/api/accounting.php') . ' 2>&1', $o, $rc);
$a('php -l api/accounting.php',                           $rc === 0);

// ────────────────────────────────────────── 6) Jaz UI — sync button + CoA bi-dir
echo "\ndashboard/src/pages/JazIntegrationSettings.jsx — UI affordances\n";
$ui = (string) file_get_contents($ROOT . '/dashboard/src/pages/JazIntegrationSettings.jsx');
$a('JazSyncNowCard mounted next to sync_config',          $c($ui, '<JazSyncNowCard'));
$a('JazSyncNowCard component defined',                    $c($ui, 'function JazSyncNowCard'));
$a('Sync now button testid',                              $c($ui, 'data-testid="jaz-sync-now-all"'));
$a('CoA-only sync button testid',                         $c($ui, 'data-testid="jaz-sync-now-coa"'));
$a('Sync now POSTs ?action=sync_now',                     $c($ui, "'/api/accounting.php?action=sync_now&provider=jaz'"));
$a('Sync now passes entity_types filter',                 $c($ui, "payload.entity_types = entityTypes"));
$a('Sync now results table testid',                       $c($ui, 'data-testid="jaz-sync-now-results"'));
$a('per-entity row testid',                               $c($ui, 'data-testid={`jaz-sync-row-${entity}`}'));
$a('error surface testid',                                $c($ui, 'data-testid="jaz-sync-now-error"'));
$a('flash surfaces pushed + mapped + skipped counts',     $c($ui, "imported into CoreFlux") && $c($ui, "pushed to Jaz"));
$a('CoA dir picker no longer pinned to pull/off',         !$c($ui, "entity === 'chart_of_accounts'\n              ? ['pull', 'off']"));
$a('Restriction-lift comment annotates 2026-02',          $c($ui, 'Restriction lifted 2026-02'));

// ────────────────────────────────────────── summary
echo "\n=========================================\n";
echo "Jaz sync button + CoA bidir smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
