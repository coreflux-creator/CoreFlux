<?php
/**
 * Mercury Slice 2 — Recipient Vault + Funding-Source Designation smoke.
 *
 * Coverage:
 *   - Migration 049: 3 new tables + idempotent ALTER on mercury_connections
 *   - Adapter additions (mercuryCreateCounterparty, mercuryListCounterparties)
 *   - Service contract (mercury_recipients.php — all 7 public helpers,
 *     validation rules, encryption round-trip, push-to-mercury idempotency,
 *     funding-default validation against synced accounts)
 *   - API endpoint contract (RBAC split, 7 routes, audit emissions)
 *   - UI JSX (MercuryRecipients page + modals, all testids)
 *   - TreasuryModule wiring (third panel under payout-rails)
 *   - Functional adapter round-trip via injected transport stub for the
 *     two new endpoints (Bearer + URL + payload shape).
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$c = fn (string $h, string $n): bool => strpos($h, $n) !== false;

// ----------------------------------------------------------------- Migration 049
echo "Migration 049_mercury_recipients.sql\n";
$migPath = __DIR__ . '/../core/migrations/049_mercury_recipients.sql';
$a('migration file exists', is_file($migPath));
$mig = (string) file_get_contents($migPath);
$a('mercury_recipients table',                   $c($mig, 'CREATE TABLE IF NOT EXISTS mercury_recipients'));
$a('recipient kind enum (vendor / funding_source)',
    $c($mig, "ENUM('vendor','funding_source')"));
$a('recipient status enum (draft/active/revoked)',
    $c($mig, "ENUM('draft','active','revoked')"));
$a('payment_method enum (ach/wire/check)',       $c($mig, "ENUM('ach','wire','check')"));
$a('mercury_recipient_bank_methods table',       $c($mig, 'CREATE TABLE IF NOT EXISTS mercury_recipient_bank_methods'));
$a('routing_number_ct VARBINARY (encrypted)',    $c($mig, 'routing_number_ct    VARBINARY(512)'));
$a('account_number_ct VARBINARY (encrypted)',    $c($mig, 'account_number_ct    VARBINARY(512)'));
$a('account_number_last4 masked column',         $c($mig, 'account_number_last4 VARCHAR(8)'));
$a('mercury_recipient_mappings table',           $c($mig, 'CREATE TABLE IF NOT EXISTS mercury_recipient_mappings'));
$a('mapping kind enum (counterparty/external_account)',
    $c($mig, "ENUM('counterparty','external_account')"));
$a('mapping UNIQUE per recipient+kind',
    $c($mig, 'UNIQUE KEY uq_mrm_recipient_kind (tenant_id, recipient_id, mercury_kind)'));
$a('mercury_connections ALTER guarded by information_schema',
    $c($mig, "TABLE_NAME='mercury_connections' AND COLUMN_NAME='default_funding_recipient_id'"));
$a('adds default_funding_recipient_id column',
    $c($mig, 'default_funding_recipient_id INT UNSIGNED NULL'));
$a('adds default_mercury_account_id column',
    $c($mig, 'default_mercury_account_id VARCHAR(80) NULL'));
$a('soft-delete column on recipients (deleted_at)',
    $c($mig, 'deleted_at      DATETIME NULL') || $c($mig, 'deleted_at           DATETIME NULL'));
$a('utf8mb4_unicode_ci (no 0900_ai_ci)',
    $c($mig, 'utf8mb4_unicode_ci') && stripos($mig, 'utf8mb4_0900_ai_ci') === false);

// ----------------------------------------------------------------- Adapter additions
echo "\ncore/mercury_adapter.php — Slice 2 additions\n";
$adv = (string) file_get_contents(__DIR__ . '/../core/mercury_adapter.php');
$a('mercuryCreateCounterparty() exported',       $c($adv, 'function mercuryCreateCounterparty'));
$a('mercuryListCounterparties() exported',       $c($adv, 'function mercuryListCounterparties'));
$a('createCounterparty posts to /recipients',    $c($adv, "/recipients"));
$a('createCounterparty validates name',          $c($adv, 'payload.name required'));
$a('list supports search/limit/offset opts',
    $c($adv, "['limit', 'offset', 'search']"));
$a('Slice 2 note on external_account scope (UI-only)',
    $c($adv, 'external bank account as a fundable source'));

// ----------------------------------------------------------------- Service contract
echo "\ncore/mercury_recipients.php\n";
$svcPath = __DIR__ . '/../core/mercury_recipients.php';
$a('service file exists', is_file($svcPath));
$svc = (string) file_get_contents($svcPath);
$a('mercuryRecipientCreate() exported',          $c($svc, 'function mercuryRecipientCreate'));
$a('mercuryRecipientUpdate() exported',          $c($svc, 'function mercuryRecipientUpdate'));
$a('mercuryRecipientList() exported',            $c($svc, 'function mercuryRecipientList'));
$a('mercuryRecipientGet() exported',             $c($svc, 'function mercuryRecipientGet'));
$a('mercuryRecipientRevoke() exported',          $c($svc, 'function mercuryRecipientRevoke'));
$a('mercuryRecipientPushToMercury() exported',   $c($svc, 'function mercuryRecipientPushToMercury'));
$a('mercuryRecipientSetFundingDefault() exported', $c($svc, 'function mercuryRecipientSetFundingDefault'));
$a('mercuryRecipientGetFundingDefault() exported', $c($svc, 'function mercuryRecipientGetFundingDefault'));
$a('kind validation (vendor|funding_source)',
    $c($svc, "in_array(\$kind, ['vendor', 'funding_source'], true)"));
$a('routing number must be 9 digits',
    $c($svc, "strlen(\$routing) !== 9"));
$a('account length 4-17 validation',
    $c($svc, "strlen(\$account) < 4 || strlen(\$account) > 17"));
$a('encrypts both routing + account via encryptField',
    $c($svc, '$rCt = encryptField($routing)') && $c($svc, '$aCt = encryptField($account)'));
$a('insert is transactional (beginTransaction/commit/rollBack)',
    $c($svc, '$pdo->beginTransaction()') && $c($svc, '$pdo->commit()') && $c($svc, '$pdo->rollBack()'));
$a('soft-revoke via deleted_at',                 $c($svc, "deleted_at = NOW()"));
$a('list excludes soft-deleted rows',            $c($svc, 'deleted_at IS NULL'));
$a('get returns bank_method (last4 only, no plaintext)',
    $c($svc, 'SELECT id, account_number_last4, account_type, nickname'));
$a('get returns mercury_mappings array',         $c($svc, "'mercury_mappings'"));
$a('push refuses funding_source kind',
    $c($svc, "funding_source recipients are not pushed via API"));
$a('push decrypts JIT then drops plaintext',
    $c($svc, '$routing = decryptField') && $c($svc, '$routing = null; $account = null'));
$a('push payload uses electronicRoutingInfo',
    $c($svc, "'electronicRoutingInfo'"));
$a('push mapping upsert on UNIQUE',              $c($svc, 'ON DUPLICATE KEY UPDATE'));
$a('setFundingDefault requires funding_source kind',
    $c($svc, "recipient must exist and be of kind=funding_source"));
$a('setFundingDefault validates account is synced',
    $c($svc, "is not in the tenant\\'s synced accounts"));
$a('graceful degrade (try/catch on read paths)',
    substr_count($svc, '} catch (\Throwable $e) {') >= 3);

// ----------------------------------------------------------------- API contract
echo "\napi/mercury_recipients.php\n";
$apiPath = __DIR__ . '/../api/mercury_recipients.php';
$a('API file exists', is_file($apiPath));
$apiF = (string) file_get_contents($apiPath);
$a('RBAC split: view vs manage',
    $c($apiF, "hasPermission(\$user, 'accounting.bank.view')") &&
    $c($apiF, "hasPermission(\$user, 'accounting.bank.manage')"));
$a('writes require manage permission',           $c($apiF, "All POST/PATCH/DELETE require manage"));
$a('GET ?action=funding_default route',          $c($apiF, "action === 'funding_default'"));
$a('GET ?id=N single-record route',              $c($apiF, '$id > 0'));
$a('GET list filters by kind',                   $c($apiF, "kind !== ''"));
$a('POST default creates recipient',             $c($apiF, 'mercuryRecipientCreate($tenantId, $body'));
$a('POST ?action=push pushes to Mercury',
    $c($apiF, "action === 'push'") && $c($apiF, 'mercuryRecipientPushToMercury($tenantId'));
$a('POST ?action=set_funding_default',
    $c($apiF, "action === 'set_funding_default'") && $c($apiF, 'mercuryRecipientSetFundingDefault'));
$a('PATCH ?id=N route',                          $c($apiF, "\$method === 'PATCH'"));
$a('DELETE ?id=N soft-revoke route',             $c($apiF, "\$method === 'DELETE'"));
$a('audit mercury.recipient.created',            $c($apiF, 'mercury.recipient.created'));
$a('audit mercury.recipient.pushed',             $c($apiF, 'mercury.recipient.pushed'));
$a('audit mercury.recipient.revoked',            $c($apiF, 'mercury.recipient.revoked'));
$a('audit mercury.funding_default.set',          $c($apiF, 'mercury.funding_default.set'));
$a('MercuryApiException → 502',                  $c($apiF, 'catch (MercuryApiException $e)') && $c($apiF, '502'));

// ----------------------------------------------------------------- UI: MercuryRecipients
echo "\nUI — MercuryRecipients.jsx\n";
$uiPath = __DIR__ . '/../modules/treasury/ui/MercuryRecipients.jsx';
$a('UI file exists', is_file($uiPath));
$ui = (string) file_get_contents($uiPath);
$a('mercury-recipients panel testid',            $c($ui, 'data-testid="mercury-recipients"'));
$a('reads list via useApi',                      $c($ui, "useApi('/api/mercury_recipients.php')"));
$a('reads accounts via useApi',                  $c($ui, "useApi('/api/mercury_accounts.php')"));
$a('reads funding_default via useApi',
    $c($ui, "useApi('/api/mercury_recipients.php?action=funding_default')"));
$a('funding-default summary card testid',        $c($ui, 'data-testid="mercury-funding-default"'));
$a('funding-default set / unset branches',
    $c($ui, 'data-testid="mercury-funding-default-set"') &&
    $c($ui, 'data-testid="mercury-funding-default-unset"'));
$a('create modal testid',                        $c($ui, 'data-testid="mercury-recipient-create-modal"'));
$a('create form fields testids',
    $c($ui, 'data-testid="mercury-recipient-kind"') &&
    $c($ui, 'data-testid="mercury-recipient-name"') &&
    $c($ui, 'data-testid="mercury-recipient-routing"') &&
    $c($ui, 'data-testid="mercury-recipient-account"'));
$a('save button testid',                         $c($ui, 'data-testid="mercury-recipient-save-btn"'));
$a('row push-to-mercury button testid',
    $c($ui, 'data-testid={`mercury-recipient-push-${r.id}`}'));
$a('row revoke button testid',
    $c($ui, 'data-testid={`mercury-recipient-revoke-${r.id}`}'));
$a('set-funding-default per-row CTA',
    $c($ui, 'data-testid={`mercury-set-funding-default-${r.id}`}'));
$a('funding-default modal testid',               $c($ui, 'data-testid="mercury-set-funding-default-modal"'));
$a('funding-default account picker',             $c($ui, 'data-testid="mercury-funding-default-account"'));
$a('funding-default save button',                $c($ui, 'data-testid="mercury-set-funding-default-save"'));
$a('push-CTA only renders for vendors',          $c($ui, "r.kind === 'vendor' && !r.mercury_id"));
$a('set-funding-default disabled when no synced accounts',
    $c($ui, 'disabled={accountRows.length === 0}'));
$a('POST to set_funding_default action',
    $c($ui, "/api/mercury_recipients.php?action=set_funding_default"));
$a('confirm dialog before revoke',               $c($ui, 'window.confirm'));
$a('explains debit → verify → push flow',
    $c($ui, 'debit') && $c($ui, 'clearance') && $c($ui, 'push'));

// ----------------------------------------------------------------- TreasuryModule wiring
echo "\nUI — TreasuryModule.jsx wiring (recipients tab)\n";
$tm = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/TreasuryModule.jsx');
$a('imports MercuryRecipients',                  $c($tm, "import MercuryRecipients from './MercuryRecipients'"));
$a('mounted inside recipients route',
    $c($tm, '<MercuryRecipients />') &&
    $c($tm, '<Route path="recipients"'));

// ----------------------------------------------------------------- Functional adapter via stub
echo "\nFunctional — counterparty round-trip via injected transport\n";
require_once __DIR__ . '/../core/mercury_adapter.php';

$captured = [];
$GLOBALS['__mercury_transport'] = function (string $method, string $url, array $headers, ?string $body) use (&$captured) {
    $captured[] = compact('method', 'url', 'headers', 'body');
    if (strpos($url, '/recipients') !== false && $method === 'POST') {
        return ['status' => 200, 'body' => json_encode([
            'id' => 'recip_abc123', 'name' => 'ACME Vendor LLC',
        ])];
    }
    if (strpos($url, '/recipients') !== false && $method === 'GET') {
        return ['status' => 200, 'body' => json_encode([
            'recipients' => [['id' => 'recip_a', 'name' => 'A'], ['id' => 'recip_b', 'name' => 'B']],
        ])];
    }
    return ['status' => 404, 'body' => '{"error":"unknown stub path"}'];
};

$createResp = mercuryCreateCounterparty('secret-token:abc', [
    'name' => 'ACME Vendor LLC',
    'paymentMethod' => 'ach',
    'electronicRoutingInfo' => [
        'electronicAccountType' => 'checking',
        'routingNumber' => '021000021',
        'accountNumber' => '12345678',
    ],
]);
$a('createCounterparty returns Mercury recipient id',
    ($createResp['id'] ?? '') === 'recip_abc123');
$a('createCounterparty POSTed to /recipients',
    !empty($captured) && (string) $captured[0]['method'] === 'POST'
    && strpos((string) $captured[0]['url'], '/api/v1/recipients') !== false);
$a('createCounterparty body carries name + electronicRoutingInfo',
    !empty($captured) && strpos((string) $captured[0]['body'], '"name":"ACME Vendor LLC"') !== false
    && strpos((string) $captured[0]['body'], 'electronicRoutingInfo') !== false);
$a('Bearer token header propagates',
    !empty($captured) && in_array('Authorization: Bearer secret-token:abc', $captured[0]['headers'] ?? [], true));

$listResp = mercuryListCounterparties('secret-token:abc', ['search' => 'A', 'limit' => 50]);
$a('listCounterparties returns recipients array',
    is_array($listResp['recipients'] ?? null) && count($listResp['recipients']) === 2);
$a('listCounterparties URL appends search + limit query',
    strpos((string) end($captured)['url'], 'search=A') !== false
    && strpos((string) end($captured)['url'], 'limit=50') !== false);

// Validation paths
$threw = false;
try { mercuryCreateCounterparty('tok', []); } catch (MercuryApiException $e) { $threw = true; }
$a('createCounterparty rejects empty payload', $threw);

unset($GLOBALS['__mercury_transport']);

// ----------------------------------------------------------------- Syntax sanity
echo "\nSyntax sanity (php -l)\n";
foreach ([
    'core/mercury_recipients.php',
    'core/mercury_adapter.php',
    'api/mercury_recipients.php',
] as $rel) {
    $out = []; $rc = 0;
    @exec('php -l ' . escapeshellarg(__DIR__ . '/../' . $rel) . ' 2>&1', $out, $rc);
    $a("php -l {$rel}", $rc === 0);
}

echo "\n=========================================\n";
echo "Mercury Slice 2 smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
