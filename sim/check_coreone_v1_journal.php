<?php
/** Exercise the CoreOne journal contract inside a rollback-only staging fixture. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This check is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../core/accounting/coreone_v1.php';

$tenantId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tenant=')) $tenantId = (int) substr($arg, 9);
}
if ($tenantId <= 0) {
    fwrite(STDERR, "Use --tenant=ID with a CoreFlux CI Simulation tenant.\n");
    exit(2);
}
$GLOBALS['__cf_request_tenant_id'] = $tenantId;
$pdo = getDB();
$tenantStmt = $pdo->prepare('SELECT name FROM tenants WHERE id = :id');
$tenantStmt->execute(['id' => $tenantId]);
if (!in_array($tenantStmt->fetchColumn(), ['CoreFlux CI Simulation', "CoreFlux CI Simulation {$tenantId}"], true)) {
    fwrite(STDERR, "This check can only run against a disposable CI Simulation tenant.\n");
    exit(2);
}
$entityStmt = $pdo->prepare(
    'SELECT id, base_currency FROM accounting_entities
      WHERE tenant_id = :t AND active = 1 ORDER BY id LIMIT 1'
);
$entityStmt->execute(['t' => $tenantId]);
$entity = $entityStmt->fetch(PDO::FETCH_ASSOC);
if (!$entity) {
    fwrite(STDERR, "Seed an active synthetic accounting entity first.\n");
    exit(2);
}
$bankStmt = $pdo->prepare(
    'SELECT ba.gl_account_code FROM accounting_bank_accounts ba
       JOIN accounting_accounts a ON a.tenant_id = ba.tenant_id AND a.code = ba.gl_account_code
      WHERE ba.tenant_id = :t AND a.active = 1 AND a.is_postable = 1
      ORDER BY ba.id'
);
$bankStmt->execute(['t' => $tenantId]);
$bankCode = null;
foreach ($bankStmt->fetchAll(PDO::FETCH_COLUMN) as $code) {
    if (!in_array($code, COREONE_V1_PROTECTED_ACCOUNTS, true)) {
        $bankCode = $code;
        break;
    }
}
if ($bankCode === null) {
    fwrite(STDERR, "Seed a bank-linked synthetic account outside the default control-code list.\n");
    exit(2);
}

$checks = [];
$error = null;
$rejects = static function (callable $action, string $exceptionClass): bool {
    try {
        $action();
        return false;
    } catch (Throwable $e) {
        return $e instanceof $exceptionClass;
    }
};
$sourceId = 'stage-coreone:' . bin2hex(random_bytes(8));
$secondEntityId = 0;
$body = [
    'schema_version' => 1,
    'source_record_id' => $sourceId,
    'event_date' => date('Y-m-d'),
    'memo' => 'Rollback-only CoreOne acceptance',
    'currency' => (string) $entity['base_currency'],
    'lines' => [
        ['account_code' => '6990', 'debit' => '1.00', 'credit' => '0.00'],
        ['account_code' => '4000', 'debit' => '0.00', 'credit' => '1.00'],
    ],
];

try {
    $pdo->beginTransaction();
    $issued = coreoneV1IssueCredential($tenantId, (int) $entity['id'],
        'Rollback-only acceptance', 1, null);
    $credential = coreoneV1Authenticate('Bearer ' . $issued['token']);
    $checks['credential_authenticates_to_exact_entity'] = $credential !== null
        && (int) $credential['tenant_id'] === $tenantId
        && (int) $credential['entity_id'] === (int) $entity['id'];
    if (!$credential) throw new RuntimeException('Disposable credential did not authenticate.');
    $hashStmt = $pdo->prepare('SELECT token_hash FROM coreone_accounting_credentials WHERE id = :id');
    $hashStmt->execute(['id' => (int) $issued['id']]);
    $checks['database_stores_only_token_hash'] = hash_equals(
        hash('sha256', $issued['token']), (string) $hashStmt->fetchColumn()
    );
    $checks['inactive_entity_credential_is_rejected'] = $rejects(
        static fn() => coreoneV1IssueCredential($tenantId, 999999999, 'Invalid entity', 1, null),
        InvalidArgumentException::class
    );
    $crossEntity = $body;
    $crossEntity['entity_id'] = (int) $entity['id'] + 1;
    $checks['request_cannot_override_entity'] = $rejects(
        static fn() => coreoneV1PostJournal($credential, $crossEntity),
        InvalidArgumentException::class
    );
    $control = $body;
    $control['lines'][0]['account_code'] = '1100';
    $checks['default_ar_control_is_rejected'] = $rejects(
        static fn() => coreoneV1PostJournal($credential, $control),
        InvalidArgumentException::class
    );
    $bank = $body;
    $bank['lines'][0]['account_code'] = $bankCode;
    $checks['custom_bank_control_is_rejected'] = $rejects(
        static fn() => coreoneV1PostJournal($credential, $bank),
        InvalidArgumentException::class
    );
    $unbalanced = $body;
    $unbalanced['lines'][1]['credit'] = '0.99';
    $checks['unbalanced_journal_is_rejected'] = $rejects(
        static fn() => coreoneV1PostJournal($credential, $unbalanced),
        InvalidArgumentException::class
    );

    $first = coreoneV1PostJournal($credential, $body);
    $checks['first_submission_posts_event_and_journal'] = ($first['status'] ?? '') === 'posted'
        && (int) ($first['event_id'] ?? 0) > 0
        && (int) ($first['journal_entry_id'] ?? 0) > 0;
    $pdo->prepare(
        'INSERT INTO accounting_entities (tenant_id, code, legal_name, base_currency, active)
         VALUES (:t, :code, "Rollback-only CoreOne entity", :currency, 1)'
    )->execute(['t' => $tenantId, 'code' => 'SIM-COREONE-' . bin2hex(random_bytes(4)),
        'currency' => (string) $entity['base_currency']]);
    $secondEntityId = (int) $pdo->lastInsertId();
    $secondIssued = coreoneV1IssueCredential($tenantId, $secondEntityId,
        'Second rollback-only entity', 1, null);
    $secondCredential = coreoneV1Authenticate('Bearer ' . $secondIssued['token']);
    $checks['second_credential_is_bound_to_its_entity'] = $secondCredential !== null
        && (int) $secondCredential['tenant_id'] === $tenantId
        && (int) $secondCredential['entity_id'] === $secondEntityId;
    if (!$secondCredential) throw new RuntimeException('Second entity credential did not authenticate.');
    $checks['second_entity_cannot_read_or_reverse_first'] =
        coreoneV1GetJournal($secondCredential, $sourceId) === null
        && $rejects(
            static fn() => coreoneV1ReverseJournal($secondCredential, $sourceId, 'Wrong entity'),
            RuntimeException::class
        );
    $checks['same_source_id_cannot_cross_entities'] = $rejects(
        static fn() => coreoneV1PostJournal($secondCredential, $body),
        AccountingEventConflictException::class
    );
    $secondBody = $body;
    $secondBody['source_record_id'] = 'stage-coreone:second:' . bin2hex(random_bytes(8));
    $secondBody['memo'] = 'Second-entity rollback-only acceptance';
    $secondPosted = coreoneV1PostJournal($secondCredential, $secondBody);
    $secondView = coreoneV1GetJournal($secondCredential, $secondBody['source_record_id']);
    $checks['second_entity_posts_its_own_scoped_journal'] =
        ($secondPosted['status'] ?? '') === 'posted'
        && $secondView !== null
        && (int) $secondView['journal_entry_id'] === (int) $secondPosted['journal_entry_id']
        && coreoneV1GetJournal($credential, $secondBody['source_record_id']) === null;
    $secondReversal = coreoneV1ReverseJournal($secondCredential,
        $secondBody['source_record_id'], 'Undo second rollback-only fixture');
    $checks['second_entity_reverses_only_its_own_journal'] =
        ($secondReversal['status'] ?? '') === 'reversed'
        && (int) ($secondReversal['reversal_journal_entry_id'] ?? 0) > 0;
    $second = coreoneV1PostJournal($credential, $body);
    $checks['exact_replay_reuses_event_and_journal'] = !empty($second['idempotent_replay'])
        && (int) $second['event_id'] === (int) $first['event_id']
        && (int) $second['journal_entry_id'] === (int) $first['journal_entry_id'];
    $changed = $body;
    $changed['memo'] = 'Changed after first posting';
    $checks['changed_intent_conflicts'] = $rejects(
        static fn() => coreoneV1PostJournal($credential, $changed),
        AccountingEventConflictException::class
    );
    $found = coreoneV1GetJournal($credential, $sourceId);
    $checks['lookup_is_scoped_to_source_and_entity'] = $found !== null
        && (int) $found['journal_entry_id'] === (int) $first['journal_entry_id']
        && coreoneV1GetJournal($credential, 'other:' . $sourceId) === null;
    $otherEntity = $credential;
    $otherEntity['entity_id'] = (int) $entity['id'] + 1;
    $checks['other_entity_cannot_read_or_reverse_source'] =
        coreoneV1GetJournal($otherEntity, $sourceId) === null
        && $rejects(
            static fn() => coreoneV1ReverseJournal($otherEntity, $sourceId, 'Wrong entity'),
            RuntimeException::class
        );
    $reversal = coreoneV1ReverseJournal($credential, $sourceId, 'Undo rollback-only fixture');
    $checks['source_owned_reversal_posted'] = ($reversal['status'] ?? '') === 'reversed'
        && (int) ($reversal['reversal_journal_entry_id'] ?? 0) > 0;
    $reversedView = coreoneV1GetJournal($credential, $sourceId);
    $checks['lookup_exposes_reversal_journal'] = $reversedView !== null
        && $reversedView['status'] === 'reversed'
        && (int) $reversedView['reversal_journal_entry_id']
            === (int) $reversal['reversal_journal_entry_id'];
    $replay = coreoneV1ReverseJournal($credential, $sourceId, 'Undo rollback-only fixture');
    $checks['reversal_replay_keeps_one_journal'] = !empty($replay['idempotent_replay'])
        && (int) $replay['reversal_journal_entry_id'] === (int) $reversal['reversal_journal_entry_id'];
    $checks['reversed_source_cannot_be_reposted'] = $rejects(
        static fn() => coreoneV1PostJournal($credential, $body),
        AccountingEventConflictException::class
    );
    $revoke = $pdo->prepare(
        'UPDATE coreone_accounting_credentials SET revoked_at = NOW()
          WHERE tenant_id = :t AND id = :id'
    );
    $revoke->execute(['t' => $tenantId, 'id' => (int) $issued['id']]);
    $checks['revoked_credential_cannot_authenticate'] =
        coreoneV1Authenticate('Bearer ' . $issued['token']) === null;
} catch (Throwable $e) {
    $error = $e->getMessage();
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

$countStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM accounting_events WHERE tenant_id = :t
        AND source_module = "coreone" AND source_record_id = :source_id'
);
$countStmt->execute(['t' => $tenantId, 'source_id' => $sourceId]);
$checks['fixture_left_no_accounting_event'] = (int) $countStmt->fetchColumn() === 0;
if ($secondEntityId > 0) {
    $entityCount = $pdo->prepare('SELECT COUNT(*) FROM accounting_entities WHERE tenant_id = :t AND id = :id');
    $entityCount->execute(['t' => $tenantId, 'id' => $secondEntityId]);
    $checks['fixture_left_no_second_entity'] = (int) $entityCount->fetchColumn() === 0;
}
echo json_encode(['tenant_id' => $tenantId, 'checks' => $checks, 'error' => $error],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($error === null && $checks && !in_array(false, $checks, true) ? 0 : 1);
