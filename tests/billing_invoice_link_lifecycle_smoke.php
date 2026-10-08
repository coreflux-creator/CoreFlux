<?php
declare(strict_types=1);

putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../modules/billing/lib/billing.php';
require_once __DIR__ . '/../modules/billing/lib/invoice_delivery.php';

final class InvoiceLinkFakePDO extends PDO
{
    public array $calls = [];
    public mixed $nextFetch = false;
    public int $changedRows = 0;

    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new InvoiceLinkFakeStatement($this, $query);
    }
    public function lastInsertId(?string $name = null): string|false { return '42'; }
}

final class InvoiceLinkFakeStatement extends PDOStatement
{
    private array $bindings = [];

    public function __construct(private InvoiceLinkFakePDO $db, private string $sql) {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bindings[$param] = $value;
        return true;
    }
    public function execute(?array $params = null): bool
    {
        $this->db->calls[] = ['sql' => $this->sql, 'params' => $params ?? $this->bindings];
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->db->nextFetch;
    }
    public function rowCount(): int { return $this->db->changedRows; }
}

$db = new InvoiceLinkFakePDO();
$GLOBALS['pdo'] = $db;
$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};

$issued = billingIssueViewToken(17, 31);
$insert = $db->calls[count($db->calls) - 1];
$check('random 256-bit token returned', (bool) preg_match('/^[a-f0-9]{64}$/', $issued['token']));
$check('token URL uses shared customer invoice view', str_ends_with($issued['url'], '/billing/invoice.php?t=' . $issued['token']));
$check('default lifetime is 90 days on database clock',
    (int) ($insert['params']['days'] ?? 0) === 90
    && str_contains($insert['sql'], 'DATE_ADD(NOW(), INTERVAL :days DAY)'));
$check('insert binds tenant and invoice',
    (int) ($insert['params']['t'] ?? 0) === 17 && (int) ($insert['params']['i'] ?? 0) === 31);
$check('only token hash is stored',
    ($insert['params']['h'] ?? null) === hash('sha256', $issued['token'], true)
    && !array_key_exists('tk', $insert['params'])
    && !str_contains($insert['sql'], ', token,'));
$check('token row identity returned', $issued['token_id'] === 42);

foreach ([0, 366] as $days) {
    try {
        billingIssueViewToken(17, 31, $days);
        $check("lifetime {$days} refused", false);
    } catch (InvalidArgumentException $e) {
        $check("lifetime {$days} refused", true);
    }
}

$before = count($db->calls);
$check('malformed token rejected before database lookup', billingTokenFindByRaw('bad') === null && count($db->calls) === $before);
$db->nextFetch = ['id' => 42, 'tenant_id' => 17, 'invoice_id' => 31];
$found = billingTokenFindByRaw($issued['token']);
$lookup = $db->calls[count($db->calls) - 1];
$check('valid token looked up by binary hash', $found === $db->nextFetch
    && ($lookup['params']['h'] ?? null) === hash('sha256', $issued['token'], true));
$check('public lookup excludes revoked and expired links',
    str_contains($lookup['sql'], 'revoked_at IS NULL')
    && str_contains($lookup['sql'], 'expires_at > NOW()'));

$db->changedRows = 2;
$revoked = billingRevokeInvoiceViewTokens(17, 31, 7, 42);
$bulk = $db->calls[count($db->calls) - 1];
$check('rotation revokes earlier links only', $revoked === 2
    && (int) ($bulk['params']['tenant_id'] ?? 0) === 17
    && (int) ($bulk['params']['invoice_id'] ?? 0) === 31
    && (int) ($bulk['params']['keep_token_id'] ?? 0) === 42
    && str_contains($bulk['sql'], 'id <> :keep_token_id'));
$check('revocation records the actor',
    (int) ($bulk['params']['actor'] ?? 0) === 7
    && str_contains($bulk['sql'], 'revoked_by_user_id = :actor'));

billingRevokeInvoiceViewToken(17, 31, 42, 7);
$single = $db->calls[count($db->calls) - 1];
$check('targeted token revocation remains tenant/invoice/token scoped',
    (int) ($single['params']['tenant_id'] ?? 0) === 17
    && (int) ($single['params']['invoice_id'] ?? 0) === 31
    && (int) ($single['params']['token_id'] ?? 0) === 42);

$api = file_get_contents(__DIR__ . '/../modules/billing/api/invoices.php');
$ui = file_get_contents(__DIR__ . '/../modules/billing/ui/InvoiceDetail.jsx');
$migration = file_get_contents(__DIR__ . '/../modules/billing/migrations/021_invoice_token_revocation.sql');
$hashOnlyMigration = file_get_contents(__DIR__ . '/../modules/billing/migrations/022_invoice_token_hash_only.sql');
$deliveryMigration = file_get_contents(__DIR__ . '/../modules/billing/migrations/023_invoice_delivery_attempts.sql');
$deliveryLib = file_get_contents(__DIR__ . '/../modules/billing/lib/invoice_delivery.php');
$check('migration adds revocation time and actor idempotently',
    str_contains($migration, "COLUMN_NAME = 'revoked_at'")
    && str_contains($migration, 'ADD COLUMN revoked_at')
    && str_contains($migration, "COLUMN_NAME = 'revoked_by_user_id'")
    && str_contains($migration, 'ADD COLUMN revoked_by_user_id'));
$check('old link secrets remain valid but are removed from storage',
    str_contains($hashOnlyMigration, 'ADD UNIQUE KEY uq_bit_token_hash (token_hash)')
    && str_contains($hashOnlyMigration, 'DROP COLUMN token')
    && str_contains($lookup['sql'], 'token_hash = :h'));
$check('detail reports link state without disclosing its secret',
    str_contains($api, 'ORDER BY is_active DESC, id DESC')
    && str_contains($api, 'SELECT id, issued_at, expires_at, revoked_at')
    && !str_contains($api, "\$token['token']"));
$check('manual replacement locks the invoice and rotates prior links',
    str_contains($api, "\$action === 'replace_link'")
    && str_contains($api, 'FOR UPDATE')
    && str_contains($api, "'billing.invoice.link_replaced'"));
$check('send and revoke controls follow server permission',
    str_contains($api, "'can_send' => RBAC::hasPermission(\$user, 'billing.invoice.send')")
    && str_contains($ui, 'data.capabilities?.can_send'));
$check('resend preserves partial and paid states',
    str_contains($deliveryLib, "['approved', 'sent', 'partially_paid', 'paid']")
    && str_contains($deliveryLib, 'CASE WHEN status = "approved" THEN "sent" ELSE status END'));
$check('send reserves a durable attempt before provider dispatch',
    strpos($api, 'billingDeliveryReserve($tid, $id, $requestId')
        < strpos($api, '$svc->send($tid')
    && str_contains($deliveryLib, 'FOR UPDATE')
    && str_contains($deliveryMigration, 'UNIQUE KEY uq_bit_delivery_request'));
$check('ambiguous delivery blocks repeats without disabling a possibly delivered link',
    str_contains($api, 'billingDeliveryMarkUncertain($tid, $id, $tok[')
    && str_contains($deliveryLib, 'delivery_status IN ("pending", "uncertain")')
    && str_contains($deliveryLib, 'billingRevokeInvoiceViewTokens($tenantId, $invoiceId, $actorUserId, $tokenId)'));
$check('a repeated request returns its recorded outcome without mailing again',
    str_contains($deliveryLib, 'delivery_request_id = :r LIMIT 1 FOR UPDATE')
    && str_contains($deliveryLib, "'replayed' => true")
    && str_contains($api, "'already_sent' => true"));
$check('human review resolves only pending or uncertain delivery',
    str_contains($api, "\$action === 'resolve_send'")
    && str_contains($deliveryLib, "['pending', 'uncertain']")
    && str_contains($deliveryLib, 'delivery_started_at, NOW()) AS age_seconds'));
$check('invoice delivery redacts its link from stored mail',
    str_contains($api, "'outbox_redactions' => [\$tok['url'], \$tok['token']]"));
$check('delivery request IDs must be UUIDs',
    billingDeliveryRequestId('A223E456-E89B-42D3-A456-426614174000') === 'a223e456-e89b-42d3-a456-426614174000');
try {
    billingDeliveryRequestId('repeat');
    $check('invalid delivery request IDs are rejected', false);
} catch (InvalidArgumentException $e) {
    $check('invalid delivery request IDs are rejected', true);
}
$check('screen offers one-time copy, replacement, disable, and resend',
    str_contains($ui, 'billing-invoice-token-copy')
    && str_contains($ui, 'billing-invoice-token-replace')
    && str_contains($ui, 'billing-invoice-token-revoke')
    && str_contains($ui, 'visibleIssuedLink.url')
    && str_contains($ui, "'Resend'"));

echo "Invoice link lifecycle: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
