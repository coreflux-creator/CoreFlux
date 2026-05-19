<?php
/**
 * Plaid Transfer smoke — webhook + sync cursor + Link UI + Treasury settings.
 *
 * Validates the AP pay-out integration end-to-end at the contract level:
 *
 *   Migration `047_plaid_transfer_cursor.sql`
 *     - plaid_transfer_cursor + plaid_transfer_events tables
 *     - UNIQUE (tenant_id, plaid_event_id) idempotency
 *     - utf8mb4_unicode_ci (Cloudways MySQL 5.7 compatible)
 *
 *   `core/plaid_transfer_sync.php`
 *     - plaidTransferMapEventStatus() handles all Plaid event_type values
 *     - plaidTransferSync() reads cursor, pages through /transfer/event/sync,
 *       persists events idempotently (INSERT IGNORE on UNIQUE),
 *       updates ap_payments by rail_external_ref, upserts cursor.
 *
 *   `api/plaid_transfer_webhook.php`
 *     - JWT verification via plaidVerifyWebhook(), persists raw payload,
 *       always 200s (no retry storm), dispatches plaidTransferSync() per
 *       linked tenant on TRANSFER_EVENTS_UPDATE.
 *
 *   `api/plaid_transfer_link.php`
 *     - GET ?action=status, POST link_token / exchange / disconnect actions,
 *       RBAC gated by accounting.bank.manage, audit emitter wired.
 *
 *   UI — JSX wiring
 *     - PlaidTransferLinkButton.jsx — link_token + exchange POSTs, Plaid SDK lazy load
 *     - PlaidTransferSettings.jsx   — three branches, testids
 *     - TreasuryModule.jsx          — payout-rails tab + route
 *     - PaymentsList.jsx            — inline CTA when configured but not linked
 *     - payments.php API surfaces plaid_transfer_linked flag
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$c = fn (string $h, string $n): bool => strpos($h, $n) !== false;

// ----------------------------------------------------------------- Migration
echo "Migration 047_plaid_transfer_cursor.sql\n";
$migPath = __DIR__ . '/../core/migrations/047_plaid_transfer_cursor.sql';
$a('migration file exists', is_file($migPath));
$mig = (string) file_get_contents($migPath);
$a('plaid_transfer_cursor table',                $c($mig, 'CREATE TABLE IF NOT EXISTS plaid_transfer_cursor'));
$a('plaid_transfer_events table',                $c($mig, 'CREATE TABLE IF NOT EXISTS plaid_transfer_events'));
$a('cursor uniqueness per tenant',               $c($mig, 'UNIQUE KEY uq_ptc_tenant (tenant_id)'));
$a('event idempotency via UNIQUE',
    $c($mig, 'UNIQUE KEY uq_pte_event'));
$a('last_event_id BIGINT UNSIGNED',              $c($mig, 'last_event_id   BIGINT UNSIGNED'));
$a('payload_json JSON column',                   $c($mig, 'payload_json      JSON'));
$a('event_type column',                          $c($mig, 'event_type        VARCHAR(40)'));
$a('failure_reason column',                      $c($mig, 'failure_reason    VARCHAR(120)'));
$a('utf8mb4_unicode_ci (no 0900_ai_ci)',
    $c($mig, 'utf8mb4_unicode_ci') && stripos($mig, 'utf8mb4_0900_ai_ci') === false);
$a('idx by transfer_id for replay lookups',      $c($mig, 'ix_pte_transfer'));

// ----------------------------------------------------------------- core/plaid_transfer_sync.php
echo "\ncore/plaid_transfer_sync.php\n";
$syncPath = __DIR__ . '/../core/plaid_transfer_sync.php';
$a('sync library file exists', is_file($syncPath));
$sync = (string) file_get_contents($syncPath);
$a('plaidTransferMapEventStatus() exported',     $c($sync, 'function plaidTransferMapEventStatus'));
$a('plaidTransferSync() exported',               $c($sync, 'function plaidTransferSync(int $tenantId)'));
$a('maps pending → pending',                     $c($sync, "case 'pending':       return 'pending'"));
$a('maps posted → posted',                       $c($sync, "case 'posted':        return 'posted'"));
$a('maps settled → settled',                     $c($sync, "case 'settled':       return 'settled'"));
$a('maps failed → failed',                       $c($sync, "case 'failed':        return 'failed'"));
$a('maps returned → returned',                   $c($sync, "case 'returned':      return 'returned'"));
$a('maps swept → settled (ledger sweep)',        $c($sync, "case 'swept':         return 'settled'"));
$a('reads cursor from plaid_transfer_cursor',
    $c($sync, 'SELECT last_event_id FROM plaid_transfer_cursor WHERE tenant_id'));
$a('calls /transfer/event/sync via plaidPost',
    $c($sync, "plaidPost('/transfer/event/sync'"));
$a('after_id pagination cursor',                 $c($sync, "'after_id' => \$newCursor"));
$a('count 25 page size',                         $c($sync, "'count'    => 25"));
$a('persists events with INSERT IGNORE (idempotent)',
    $c($sync, 'INSERT IGNORE INTO plaid_transfer_events'));
$a('updates ap_payments by rail_external_ref',
    $c($sync, 'UPDATE ap_payments') && $c($sync, 'rail_external_ref = :ref'));
$a('only updates plaid_transfer rail',
    $c($sync, 'disbursement_rail = "plaid_transfer"'));
$a('cursor upsert (ON DUPLICATE KEY UPDATE)',
    $c($sync, 'INSERT INTO plaid_transfer_cursor') && $c($sync, 'ON DUPLICATE KEY UPDATE'));
$a('has_more pagination loop',                   $c($sync, 'has_more'));
$a('return envelope: fetched / updated_payments / new_cursor / errors',
    $c($sync, "'fetched'") && $c($sync, "'updated_payments'") &&
    $c($sync, "'new_cursor'") && $c($sync, "'errors'"));
$a('errors caught (sync_fetch_failed branch)',   $c($sync, 'sync_fetch_failed'));
$a('errors caught (event_persist_failed branch)', $c($sync, 'event_persist_failed'));
$a('errors caught (payment_update_failed branch)', $c($sync, 'payment_update_failed'));

// ----------------------------------------------------------------- api/plaid_transfer_webhook.php
echo "\napi/plaid_transfer_webhook.php\n";
$whPath = __DIR__ . '/../api/plaid_transfer_webhook.php';
$a('webhook file exists', is_file($whPath));
$wh = (string) file_get_contents($whPath);
$a('requires plaid_service',                     $c($wh, "require_once __DIR__ . '/../core/plaid_service.php'"));
$a('requires plaid_transfer_sync',               $c($wh, "require_once __DIR__ . '/../core/plaid_transfer_sync.php'"));
$a('JWT verification via plaidVerifyWebhook',    $c($wh, 'plaidVerifyWebhook($jwt, $rawBody)'));
$a('reads Plaid-Verification header',            $c($wh, "'plaid-verification'"));
$a('persists to plaid_webhook_events (audit)',
    $c($wh, 'INSERT INTO plaid_webhook_events'));
$a('persists payload regardless of verify (forensics)',
    $c($wh, 'Persist (audit/replay regardless of verification)'));
$a('returns 200 even on signature_invalid (no retry-storm)',
    $c($wh, "'reason' => 'signature_invalid'") && $c($wh, 'http_response_code(200)'));
$a('dispatches sync on TRANSFER_EVENTS_UPDATE',
    $c($wh, "'TRANSFER_EVENTS_UPDATE'"));
$a('loops over every linked tenant',
    $c($wh, "rail = 'plaid_transfer'") && $c($wh, "status = 'linked'") &&
    $c($wh, 'plaidTransferSync((int) $tid)'));
$a('marks event processed_at after success',
    $c($wh, 'SET processed_at = NOW()'));
$a('writes error_message on failure',            $c($wh, 'SET error_message = :m'));
$a('final response envelope includes synced_tenants',
    $c($wh, "'synced_tenants'") && $c($wh, "'fetched'") && $c($wh, "'updated_payments'"));

// ----------------------------------------------------------------- api/plaid_transfer_link.php
echo "\napi/plaid_transfer_link.php\n";
$linkPath = __DIR__ . '/../api/plaid_transfer_link.php';
$a('link API file exists', is_file($linkPath));
$link = (string) file_get_contents($linkPath);
$a('RBAC gate: accounting.bank.manage',
    $c($link, "rbac_legacy_require(\$user, 'accounting.bank.manage')"));
$a('GET ?action=status branch',                  $c($link, "\$method === 'GET' && \$action === 'status'"));
$a('status returns configured + linked + rail',
    $c($link, "'configured' =>") && $c($link, "'linked'") && $c($link, "'rail'"));
$a('status reads tenant_payment_rails',
    $c($link, "FROM tenant_payment_rails") && $c($link, "rail = 'plaid_transfer'"));
$a('status degrades gracefully when migration not run',
    $c($link, '$row = null') && $c($link, '} catch (\Throwable $e) {'));
$a('POST link_token action calls /link/token/create',
    $c($link, "plaidPost('/link/token/create'"));
$a('link_token transfer product specified',
    $c($link, "'products'      => ['transfer']"));
$a('POST exchange action stores in tenant_payment_rails',
    $c($link, "INSERT INTO tenant_payment_rails") && $c($link, "'plaid_transfer'") &&
    $c($link, 'ON DUPLICATE KEY UPDATE'));
$a('exchange encrypts access_token via plaidEncryptAccessToken',
    $c($link, 'plaidEncryptAccessToken'));
$a('POST disconnect action flips status to revoked',
    $c($link, "action === 'disconnect'") && $c($link, "SET status = 'revoked'"));
$a('disconnect emits payment_rails.plaid.disconnected audit',
    $c($link, 'payment_rails.plaid.disconnected'));
$a('linked exchange emits payment_rails.plaid.linked audit',
    $c($link, 'payment_rails.plaid.linked'));
$a('config gate (503) preserved for POST link_token / exchange',
    $c($link, '!plaidConfigured()') && $c($link, '503'));
$a('rejects non-GET/POST methods',
    $c($link, "if (\$method !== 'POST') api_error('Method not allowed', 405)"));

// ----------------------------------------------------------------- core/payment_rails/plaid_transfer_driver.php
echo "\ncore/payment_rails/plaid_transfer_driver.php (existing driver contract)\n";
$drvPath = __DIR__ . '/../core/payment_rails/plaid_transfer_driver.php';
$a('driver file exists', is_file($drvPath));
$drv = (string) file_get_contents($drvPath);
$a('driver name() returns plaid_transfer',
    $c($drv, "public function name(): string { return 'plaid_transfer'; }"));
$a('isConfigured() reads PLAID_CLIENT_ID + PLAID_SECRET_*',
    $c($drv, 'PLAID_CLIENT_ID') && $c($drv, 'PLAID_SECRET_SANDBOX') && $c($drv, 'PLAID_SECRET_PRODUCTION'));
$a('originate() requires linked funding source',
    $c($drv, 'tenant has not linked a funding source'));
$a('two-step originate (authorization/create → transfer/create)',
    $c($drv, "ENDPOINT_AUTHORIZATION_CREATE") && $c($drv, "ENDPOINT_TRANSFER_CREATE"));
$a('idempotency_key per item (resilient retry)',
    $c($drv, 'idempotency_key'));

// ----------------------------------------------------------------- UI: PlaidTransferLinkButton.jsx
echo "\nUI — PlaidTransferLinkButton.jsx\n";
$btnPath = __DIR__ . '/../dashboard/src/components/PlaidTransferLinkButton.jsx';
$a('component file exists', is_file($btnPath));
$btn = (string) file_get_contents($btnPath);
$a('lazy-loads Plaid Link CDN',
    $c($btn, 'cdn.plaid.com/link/v2/stable/link-initialize.js'));
$a('POSTs /api/plaid_transfer_link.php for link_token',
    $c($btn, "api.post('/api/plaid_transfer_link.php', {})"));
$a('POSTs /api/plaid_transfer_link.php?action=exchange',
    $c($btn, "/api/plaid_transfer_link.php?action=exchange"));
$a('exchange body has public_token + account_id',
    $c($btn, 'public_token: publicToken') && $c($btn, 'account_id:'));
$a('reads account_id from Plaid metadata.accounts[0].id',
    $c($btn, 'metadata.accounts[0]'));
$a('state machine covers idle/loading/ready/linking/exchanging/done/error',
    $c($btn, "useState('idle')") && $c($btn, "setStatus('linking')") &&
    $c($btn, "setStatus('exchanging')") && $c($btn, "setStatus('done')") &&
    $c($btn, "setStatus('error')"));
$a('exposes data-testid plaid-transfer-link-btn',
    $c($btn, '`plaid-transfer-link-btn'));
$a('error state has testid',                     $c($btn, "data-testid=\"plaid-transfer-link-error\""));
$a('default export wired',                       $c($btn, 'export default function PlaidTransferLinkButton'));

// ----------------------------------------------------------------- UI: PlaidTransferSettings.jsx
echo "\nUI — PlaidTransferSettings.jsx\n";
$setPath = __DIR__ . '/../modules/treasury/ui/PlaidTransferSettings.jsx';
$a('settings panel exists', is_file($setPath));
$set = (string) file_get_contents($setPath);
$a('reads status via useApi',
    $c($set, "useApi('/api/plaid_transfer_link.php?action=status')"));
$a('not-configured branch testid',               $c($set, 'data-testid="plaid-transfer-not-configured"'));
$a('not-linked branch testid',                   $c($set, 'data-testid="plaid-transfer-not-linked"'));
$a('linked branch testid',                       $c($set, 'data-testid="plaid-transfer-linked"'));
$a('mounts PlaidTransferLinkButton in not-linked branch',
    $c($set, '<PlaidTransferLinkButton'));
$a('disconnect button posts to disconnect action',
    $c($set, "/api/plaid_transfer_link.php?action=disconnect"));
$a('disconnect button has data-testid',          $c($set, 'data-testid="plaid-transfer-disconnect-btn"'));
$a('shows item_id and account_id metadata',
    $c($set, 'data-testid="plaid-transfer-item-id"') && $c($set, 'data-testid="plaid-transfer-account-id"'));
$a('reload after link/disconnect',               substr_count($set, 'reload()') >= 2);
$a('confirm dialog before disconnect',           $c($set, 'window.confirm'));

// ----------------------------------------------------------------- UI: AdminModule wiring
echo "\nUI — AdminModule wiring (centralized Integrations)\n";
$tmPath = __DIR__ . '/../dashboard/src/pages/AdminModule.jsx';
$tm = (string) file_get_contents($tmPath);
$a('imports PlaidTransferSettings',
    $c($tm, "import PlaidTransferSettings from '../../../modules/treasury/ui/PlaidTransferSettings'"));
$a('integrations hub route mounted',             $c($tm, 'path="/integrations"'));
$a('plaid route mounted under /admin/integrations',
    $c($tm, '<Route path="/integrations/plaid"    element={<PlaidTransferSettings session={session} />} />'));

// ----------------------------------------------------------------- UI: PaymentsList inline CTA
echo "\nUI — PaymentsList inline CTA\n";
$plPath = __DIR__ . '/../modules/ap/ui/PaymentsList.jsx';
$pl = (string) file_get_contents($plPath);
$a('reads plaid_transfer_linked from API',
    $c($pl, 'plaidTransferLinked = !!data?.plaid_transfer_linked'));
$a('renders inline link-CTA testid when configured but unlinked',
    $c($pl, 'data-testid="ap-plaid-link-cta"'));
$a('CTA links to /admin/integrations/plaid',
    $c($pl, 'to="/admin/integrations/plaid"'));
$a('CTA Link has its own testid',                $c($pl, 'data-testid="ap-plaid-link-cta-link"'));
$a('still surfaces ready badge when linked',
    $c($pl, 'Plaid Transfer ready'));
$a('still surfaces disabled notice when not configured',
    $c($pl, 'data-testid="ap-plaid-disabled-notice"'));
$a('plaidEligible() guard requires linked + method=plaid + status=sent + no rail_ref',
    $c($pl, 'plaidTransferLinked &&') &&
    $c($pl, "p.method === 'plaid'") &&
    $c($pl, "p.status === 'sent'") &&
    $c($pl, '!p.rail_external_ref'));
$a('per-row "Send via Plaid" button testid',
    $c($pl, 'data-testid={`ap-send-via-plaid-${p.id}`}'));
$a('per-row Send-via-Plaid POSTs originate?rail=plaid_transfer',
    $c($pl, "?action=originate&id=") && $c($pl, "&rail=plaid_transfer"));
$a('per-row error/success affordances rendered',
    $c($pl, 'ap-send-via-plaid-error-') && $c($pl, 'ap-send-via-plaid-ok-'));

// ----------------------------------------------------------------- API: originate accepts rail override
echo "\nAPI — originate rail override\n";
$apPath = __DIR__ . '/../modules/ap/api/payments.php';
$ap = (string) file_get_contents($apPath);
$a('originate action accepts ?rail= query param',
    $c($ap, "(string) (\$_GET['rail'] ?? '')"));
$a('rail override allowlist (nacha / plaid_transfer)',
    $c($ap, "['nacha', 'plaid_transfer']"));
$a('rail override mutates row.disbursement_rail before dispatch',
    $c($ap, "\$row['disbursement_rail'] = \$railOverride"));

// ----------------------------------------------------------------- dispatch passes tenant_id
echo "\ncore/payment_rails/originate_helpers.php — tenant_id passthrough\n";
$oh = (string) file_get_contents(__DIR__ . '/../core/payment_rails/originate_helpers.php');
$a("dispatch opts['tenant_id'] populated for driver",
    $c($oh, "'tenant_id'       => isset(\$sourceRow['tenant_id'])"));
$a('falls back to currentTenantContext when missing',
    $c($oh, 'currentTenantContext'));

// ----------------------------------------------------------------- API: payments.php returns flag
echo "\nAPI — modules/ap/api/payments.php\n";
$a('returns plaid_transfer_linked in GET response',
    $c($ap, "'plaid_transfer_linked' => \$plaidLinked"));
$a('reads tenant_payment_rails row before returning',
    $c($ap, "FROM tenant_payment_rails WHERE tenant_id = :tenant_id AND rail = 'plaid_transfer'"));
$a('degrades gracefully (try/catch around rail lookup)',
    $c($ap, '$plaidLinked = false;') &&
    $c($ap, '} catch (\Throwable $e) {'));

// ----------------------------------------------------------------- syntax sanity (php -l)
echo "\nSyntax sanity (php -l)\n";
$phpFiles = [
    'core/plaid_transfer_sync.php',
    'api/plaid_transfer_webhook.php',
    'api/plaid_transfer_link.php',
];
foreach ($phpFiles as $rel) {
    $p = __DIR__ . '/../' . $rel;
    $out = []; $rc = 0;
    @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $out, $rc);
    $a("php -l {$rel}", $rc === 0);
}

echo "\n=========================================\n";
echo "Plaid Transfer smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
