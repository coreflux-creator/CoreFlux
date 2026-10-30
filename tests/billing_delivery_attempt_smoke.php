<?php
declare(strict_types=1);

putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../modules/billing/lib/invoice_delivery.php';

final class DeliveryFakePDO extends PDO
{
    public array $invoice = [
        'id' => 31, 'tenant_id' => 17, 'status' => 'approved',
        'journal_entry_id' => 9, 'entity_id' => 3, 'opening_cutover_id' => null,
        'sent_at' => null,
    ];
    public array $tokens = [];
    public array $calls = [];
    public int $nextId = 42;
    public int $lastId = 0;
    private bool $transaction = false;
    private array $snapshot = [];

    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new DeliveryFakeStatement($this, $query);
    }
    public function lastInsertId(?string $name = null): string|false { return (string) $this->lastId; }
    public function beginTransaction(): bool
    {
        $this->snapshot = [$this->invoice, $this->tokens, $this->nextId];
        return $this->transaction = true;
    }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool
    {
        [$this->invoice, $this->tokens, $this->nextId] = $this->snapshot;
        $this->transaction = false;
        return true;
    }
    public function inTransaction(): bool { return $this->transaction; }

    public function executeQuery(string $sql, array $params): int
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];
        if (str_contains($sql, 'INSERT INTO billing_invoice_tokens')) {
            $this->lastId = $this->nextId++;
            $this->tokens[$this->lastId] = [
                'id' => $this->lastId, 'tenant_id' => $params['t'], 'invoice_id' => $params['i'],
                'delivery_request_id' => null, 'delivery_status' => 'manual',
                'delivery_recipient' => null, 'revoked_at' => null,
            ];
            return 1;
        }
        if (str_contains($sql, 'SET delivery_request_id = :r')) {
            $token =& $this->tokens[(int) $params['token_id']];
            $token['delivery_request_id'] = $params['r'];
            $token['delivery_status'] = 'pending';
            $token['delivery_recipient'] = $params['recipient'];
            return 1;
        }
        if (str_contains($sql, 'SET delivery_status = "uncertain"')) {
            $token =& $this->tokens[(int) $params['token_id']];
            if ($token['delivery_status'] !== 'pending') return 0;
            $token['delivery_status'] = 'uncertain';
            return 1;
        }
        if (str_contains($sql, 'SET delivery_status = "failed"')) {
            $token =& $this->tokens[(int) $params['token_id']];
            $token['delivery_status'] = 'failed';
            $token['revoked_at'] = 'now';
            return 1;
        }
        if (str_contains($sql, 'SET delivery_status = "sent"')) {
            $token =& $this->tokens[(int) $params['token_id']];
            $token['delivery_status'] = 'sent';
            return 1;
        }
        if (str_contains($sql, 'UPDATE billing_invoices')) {
            if ($this->invoice['status'] === 'approved') $this->invoice['status'] = 'sent';
            $this->invoice['sent_at'] ??= 'now';
            return 1;
        }
        if (str_contains($sql, 'SET revoked_at = NOW(), revoked_by_user_id = :actor')) {
            $changed = 0;
            foreach ($this->tokens as &$token) {
                if ($token['revoked_at'] !== null || $token['id'] === (int) ($params['keep_token_id'] ?? 0)) continue;
                $token['revoked_at'] = 'now';
                $changed++;
            }
            unset($token);
            return $changed;
        }
        if (str_starts_with(ltrim($sql), 'SELECT ')) return 0;
        throw new RuntimeException('Unexpected test SQL: ' . $sql);
    }

    public function fetchQuery(string $sql, array $params): array|false
    {
        if (str_contains($sql, 'SELECT * FROM billing_invoices')) return $this->invoice;
        if (str_contains($sql, 'SELECT entity_id FROM accounting_journal_entries')) return ['entity_id' => 3];
        if (str_contains($sql, 'SELECT id, legal_name FROM accounting_entities')) {
            return ['id' => 3, 'legal_name' => 'Test Company'];
        }
        if (str_contains($sql, 'delivery_request_id = :r LIMIT 1 FOR UPDATE')) {
            foreach ($this->tokens as $token) {
                if ($token['delivery_request_id'] === $params['r']) return $token;
            }
            return false;
        }
        if (str_contains($sql, 'delivery_status IN ("pending", "uncertain")')) {
            foreach (array_reverse($this->tokens) as $token) {
                if (in_array($token['delivery_status'], ['pending', 'uncertain'], true)) return $token;
            }
            return false;
        }
        if (str_contains($sql, 'delivery_status = "sent"')) {
            foreach (array_reverse($this->tokens) as $token) {
                if ($token['delivery_status'] === 'sent') return ['id' => $token['id']];
            }
            return false;
        }
        if (str_contains($sql, 'delivery_status = "pending"')) {
            $token = $this->tokens[(int) $params['token_id']] ?? null;
            return $token && $token['delivery_status'] === 'pending'
                && $token['revoked_at'] === null ? ['id' => $token['id']] : false;
        }
        if (str_contains($sql, 'link_not_expired')) {
            $token = $this->tokens[(int) $params['token_id']] ?? null;
            return $token ? $token + ['age_seconds' => 130, 'link_not_expired' => 1] : false;
        }
        throw new RuntimeException('Unexpected test SELECT: ' . $sql);
    }
}

final class DeliveryFakeStatement extends PDOStatement
{
    private array $params = [];
    private int $changed = 0;

    public function __construct(private DeliveryFakePDO $db, private string $sql) {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->params[$param] = $value;
        return true;
    }
    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? $this->params;
        $this->changed = $this->db->executeQuery($this->sql, $this->params);
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->db->fetchQuery($this->sql, $this->params);
    }
    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        return $row === false ? false : array_values($row)[$column];
    }
    public function rowCount(): int { return $this->changed; }
}

$db = new DeliveryFakePDO();
$GLOBALS['pdo'] = $db;
$passed = 0;
$failed = 0;
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $passed++ : $failed++;
};
$firstId = 'a223e456-e89b-42d3-a456-426614174000';
$secondId = 'b223e456-e89b-42d3-a456-426614174000';
$thirdId = 'c223e456-e89b-42d3-a456-426614174000';

$first = billingDeliveryReserve(17, 31, $firstId, 'billing@example.test', false);
$check('first send reserves one pending token', !$first['replayed']
    && $first['token_id'] === 42 && $db->tokens[42]['delivery_status'] === 'pending');
$same = billingDeliveryReserve(17, 31, $firstId, 'billing@example.test', false);
$check('exact retry returns the first attempt without issuing another token', $same['replayed']
    && $same['token_id'] === 42 && count($db->tokens) === 1);
try {
    billingDeliveryReserve(17, 31, $secondId, 'billing@example.test', false);
    $check('competing send is blocked', false);
} catch (DomainException $e) {
    $check('competing send is blocked', count($db->tokens) === 1);
}
billingDeliveryMarkUncertain(17, 31, 42, $firstId, 'provider response lost');
$check('uncertain attempt retains its possible customer link',
    $db->tokens[42]['delivery_status'] === 'uncertain' && $db->tokens[42]['revoked_at'] === null);
$released = billingDeliveryResolve(17, 31, 42, 'not_accepted', null, 7);
$check('verified non-acceptance releases the invoice without marking it sent',
    $released === 0 && $db->tokens[42]['delivery_status'] === 'failed'
    && $db->tokens[42]['revoked_at'] !== null && $db->invoice['status'] === 'approved');

$second = billingDeliveryReserve(17, 31, $secondId, 'billing@example.test', false);
$revoked = billingDeliveryFinalize(17, 31, $second['token_id'], $secondId, 'provider-123', 7);
$check('provider acceptance and invoice state finish together', $revoked === 0
    && $db->invoice['status'] === 'sent' && $db->tokens[43]['delivery_status'] === 'sent');
$replay = billingDeliveryReserve(17, 31, $secondId, 'billing@example.test', false);
$check('completed request replays after invoice status changes', $replay['replayed']
    && $replay['delivery_status'] === 'sent' && count($db->tokens) === 2);
try {
    billingDeliveryReserve(17, 31, $thirdId, 'billing@example.test', false);
    $check('fresh send requires explicit resend confirmation', false);
} catch (DomainException $e) {
    $check('fresh send requires explicit resend confirmation', true);
}
$third = billingDeliveryReserve(17, 31, $thirdId, 'billing@example.test', true);
billingDeliveryMarkUncertain(17, 31, $third['token_id'], $thirdId, 'provider accepted; finalize failed');
$revoked = billingDeliveryResolve(17, 31, $third['token_id'], 'accepted', 'provider-456', 7);
$check('reviewed accepted resend keeps paid-state rules and rotates the old link',
    $revoked === 1 && $db->invoice['status'] === 'sent'
    && $db->tokens[43]['revoked_at'] !== null && $db->tokens[44]['delivery_status'] === 'sent');

$paidDb = new DeliveryFakePDO();
$paidDb->invoice['status'] = 'paid';
$GLOBALS['pdo'] = $paidDb;
$paidFirstId = 'd223e456-e89b-42d3-a456-426614174000';
$paidResendId = 'e223e456-e89b-42d3-a456-426614174000';
try {
    billingDeliveryReserve(17, 31, $paidFirstId, 'billing@example.test', true);
    $check('paid but never sent rejects resend confirmation', false);
} catch (DomainException $e) {
    $check('paid but never sent rejects resend confirmation', count($paidDb->tokens) === 0);
}
$paidFirst = billingDeliveryReserve(17, 31, $paidFirstId, 'billing@example.test', false);
billingDeliveryFinalize(17, 31, $paidFirst['token_id'], $paidFirstId, 'provider-paid', 7);
$check('paid but never sent permits first delivery without changing paid status',
    $paidDb->invoice['status'] === 'paid' && $paidDb->invoice['sent_at'] !== null
    && $paidDb->tokens[42]['delivery_status'] === 'sent');
$paidReplay = billingDeliveryReserve(17, 31, $paidFirstId, 'billing@example.test', false);
$check('paid first delivery replays without a duplicate token',
    $paidReplay['replayed'] && count($paidDb->tokens) === 1);
$paidDb->invoice['sent_at'] = null;
$check('accepted delivery token retains send history without a timestamp',
    billingDeliveryWasSent($paidDb, 17, 31, $paidDb->invoice));
try {
    billingDeliveryReserve(17, 31, $paidResendId, 'billing@example.test', false);
    $check('paid delivered invoice requires resend confirmation', false);
} catch (DomainException $e) {
    $check('paid delivered invoice requires resend confirmation', count($paidDb->tokens) === 1);
}
$paidResend = billingDeliveryReserve(17, 31, $paidResendId, 'billing@example.test', true);
$check('paid delivered invoice permits confirmed resend',
    !$paidResend['replayed'] && $paidResend['token_id'] === 43);

echo "Invoice delivery attempts: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
