<?php
declare(strict_types=1);

$failures = 0;
$assert = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};

require_once __DIR__ . '/../core/treasury/bank_transaction_identity.php';
require_once __DIR__ . '/../core/treasury/bank_transaction_dedupe.php';

$assert('normalizes punctuation and whitespace',
    bankTxnNormalizeDescription('  FCB  FUNDS-TRANSFER  ') === 'fcb funds transfer');
$assert('recognizes compact Plaid description inside full bank narrative',
    bankTxnDescriptionsEquivalent('Batch Trkid', 'ACH BATCH TRKID#111680195 INTERNET BAT'));
$assert('does not merge different transfer destinations',
    !bankTxnDescriptionsEquivalent('FCB FUNDS TRANSFER TO X1348', 'FCB FUNDS TRANSFER FROM X9785'));

$partitions = bankTxnPartitionRowsByIdentity([
    ['posted_date' => '2026-08-03', 'amount' => -15000, 'description' => 'Batch Trkid'],
    ['posted_date' => '2026-08-03', 'amount' => -15000, 'description' => 'ACH BATCH TRKID#111680195 INTERNET BAT'],
    ['posted_date' => '2026-08-11', 'amount' => -7500, 'description' => 'FCB FUNDS TRANSFER TO X1348'],
    ['posted_date' => '2026-08-11', 'amount' => -7500, 'description' => 'FCB FUNDS TRANSFER FROM X9785'],
]);
$assert('partitions compact and full bank descriptions as one event', count($partitions[0]) === 2);
$assert('keeps different same-day transfers as separate events', count($partitions) === 3);

$sync = (string) file_get_contents(__DIR__ . '/../api/plaid_sync_transactions.php');
$api = (string) file_get_contents(__DIR__ . '/../modules/treasury/api/account_transactions.php');
$reviewApi = (string) file_get_contents(__DIR__ . '/../api/bank_transaction_dedupe.php');
$ui = (string) file_get_contents(__DIR__ . '/../modules/treasury/ui/AccountTransactions.jsx');
$assert('Plaid full-history sync uses replay candidate matching', str_contains($sync, 'bankTxnFindReplayCandidate'));
$assert('Plaid modifications resolve historical aliases', str_contains($sync, 'bankTxnAliasLineId'));
$assert('Treasury list excludes audit-only duplicate rows', str_contains($api, 'duplicate_of_line_id IS NULL'));
$assert('account UI records pair-specific distinct or reopened reviews',
    str_contains($ui, 'Save distinct review') && str_contains($ui, 'Reopen review')
    && str_contains($ui, 'treasury-duplicate-activity-banner'));
$assert('bulk repair is disabled while pair decisions use the guarded endpoint',
    str_contains($reviewApi, 'Bulk bank-line repair is unavailable')
    && str_contains($reviewApi, "action'] ?? '') === 'review_pair'"));
$assert('Plaid candidate resemblance does not silently reuse a bank line',
    str_contains($sync, "\$results['possible_replays']")
    && !str_contains($sync, "\$results['reused'] = (\$results['reused'] ?? 0) + 1"));

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "[SKIP] sqlite driver unavailable" . PHP_EOL;
    exit($failures > 0 ? 1 : 0);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE accounting_bank_statement_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    bank_account_id INTEGER NOT NULL,
    posted_date TEXT NOT NULL,
    description TEXT,
    amount NUMERIC NOT NULL,
    fitid TEXT,
    external_id TEXT,
    source_system TEXT,
    bank_reference TEXT,
    match_status TEXT NOT NULL DEFAULT "unmatched",
    matched_je_id INTEGER,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    duplicate_of_line_id INTEGER,
    dedupe_reason TEXT,
    deduplicated_at TEXT
)');
$pdo->exec('CREATE UNIQUE INDEX uq_fitid ON accounting_bank_statement_lines(tenant_id, bank_account_id, fitid)');
$pdo->exec('CREATE TABLE accounting_bank_transaction_aliases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    bank_account_id INTEGER NOT NULL,
    statement_line_id INTEGER NOT NULL,
    provider TEXT NOT NULL,
    external_id TEXT NOT NULL,
    provider_item_id TEXT,
    UNIQUE(tenant_id, bank_account_id, provider, external_id)
)');
$pdo->exec('CREATE TABLE accounting_journal_entries (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    source_module TEXT,
    source_ref_type TEXT,
    source_ref_id INTEGER,
    entity_id INTEGER NOT NULL DEFAULT 1,
    currency TEXT NOT NULL DEFAULT "USD",
    intercompany_group_id TEXT
)');
$pdo->exec('CREATE TABLE accounting_journal_entry_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    je_id INTEGER NOT NULL,
    account_id INTEGER NOT NULL,
    debit NUMERIC NOT NULL DEFAULT 0,
    credit NUMERIC NOT NULL DEFAULT 0,
    memo TEXT,
    counterparty_company_id INTEGER,
    counterparty_person_id INTEGER,
    counterparty_entity_id INTEGER,
    dim_json TEXT
)');
$pdo->exec('CREATE TABLE accounting_subledger_links (
    tenant_id INTEGER NOT NULL,
    source_module TEXT,
    source_record_id TEXT,
    journal_entry_id INTEGER
)');

$insert = $pdo->prepare('INSERT INTO accounting_bank_statement_lines
    (tenant_id, bank_account_id, posted_date, description, amount, fitid, match_status, matched_je_id)
    VALUES (1, 63, :d, :n, :a, :f, :s, :j)');
foreach ([
    ['2026-09-02', 'FCB FUNDS TRANSFER TO X1348', -1036, 'old-a', 'matched', 10],
    ['2026-09-02', 'FCB FUNDS TRANSFER TO X1348', -1036, 'old-b', 'matched', 11],
    ['2026-09-02', 'FCB FUNDS TRANSFER TO X1348', -1036, 'old-c', 'unmatched', null],
    ['2026-08-03', 'Batch Trkid', -15000, 'old-d', 'unmatched', null],
    ['2026-08-03', 'ACH BATCH TRKID#111680195 INTERNET BAT', -15000, 'old-e', 'unmatched', null],
    ['2026-08-11', 'FCB FUNDS TRANSFER TO X1348', -7500, 'real-a', 'unmatched', null],
    ['2026-08-11', 'FCB FUNDS TRANSFER FROM X9785', -7500, 'real-b', 'unmatched', null],
] as $r) {
    $insert->execute(['d' => $r[0], 'n' => $r[1], 'a' => $r[2], 'f' => $r[3], 's' => $r[4], 'j' => $r[5]]);
}
$pdo->exec("INSERT INTO accounting_journal_entries
    (id, tenant_id, status, source_module, source_ref_type, source_ref_id, entity_id, currency, intercompany_group_id)
    VALUES
    (10, 1, 'posted', 'treasury_feed', 'bank_statement_line', 1, 1, 'USD', NULL),
    (11, 1, 'posted', 'treasury_feed', 'bank_statement_line', 2, 1, 'USD', NULL),
    (12, 1, 'posted', 'manual', 'intercompany_group', NULL, 1, 'USD', 'single-leg'),
    (13, 1, 'posted', 'manual', 'intercompany_group', NULL, 1, 'USD', 'two-leg'),
    (14, 1, 'posted', 'manual', 'intercompany_group', NULL, 2, 'USD', 'two-leg')");
$pdo->exec("UPDATE accounting_bank_statement_lines SET external_id='bank-event-b', source_system='mercury', bank_reference='wire-b' WHERE fitid='old-b'");
$pdo->exec("INSERT INTO accounting_journal_entry_lines (je_id, account_id, debit, credit, memo) VALUES
    (10, 100, 1036, 0, NULL), (10, 200, 0, 1036, NULL),
    (12, 100, 1036, 0, 'manual debit memo'), (12, 200, 0, 1036, 'manual credit memo'),
    (13, 100, 1036, 0, NULL), (13, 200, 0, 1036, NULL),
    (14, 100, 0, 1036, NULL), (14, 200, 1036, 0, NULL)");
$singleLegJe = [
    'status' => 'posted', 'source_module' => 'manual', 'intercompany_group_id' => 'single-leg',
];
$multiLegJe = [
    'status' => 'posted', 'source_module' => 'manual', 'intercompany_group_id' => 'two-leg',
];
$assert('recognizes equivalent accounting despite different free-form memo wording',
    bankTxnIsSingleLegExactJournalDuplicate($pdo, 1, 12, 10, $singleLegJe));
$assert('refuses to auto-reverse a multi-leg intercompany group',
    !bankTxnIsSingleLegExactJournalDuplicate($pdo, 1, 13, 10, $multiLegJe));

$preview = bankTxnDuplicatePreview($pdo, 1, 63);
$assert('finds exact replay and compact-description replay clusters', $preview['cluster_count'] === 2);
$assert('counts three excess replay rows', $preview['duplicate_rows'] === 3);
$assert('does not authorize reversals from matching bank facts', $preview['reversible_rows'] === 0);
$assert('pre-migration preview still exposes four unresolved pairs',
    !$preview['review_available'] && $preview['unreviewed_pairs'] === 4);
$assert('keeps different journals and unmatched lines for source review', $preview['conflict_rows'] === 3 && $preview['safe_rows'] === 0);
$assert('preview exposes source identity for review',
    $preview['clusters'][0]['duplicates'][0]['external_id'] === 'bank-event-b'
    && $preview['clusters'][0]['duplicates'][0]['bank_reference'] === 'wire-b');
$assert('keeps differently directed transfers separate',
    count(array_filter($preview['clusters'], static fn(array $c): bool => $c['posted_date'] === '2026-08-11')) === 0);

$candidate = bankTxnFindReplayCandidate($pdo, 1, 63, '2026-09-02', -1036, 'FCB FUNDS TRANSFER TO X1348');
$assert('full replay finds canonical existing occurrence', (int) ($candidate['id'] ?? 0) === 1);
$claimed = [1 => true];
$candidate2 = bankTxnFindReplayCandidate($pdo, 1, 63, '2026-09-02', -1036, 'FCB FUNDS TRANSFER TO X1348', $claimed);
$assert('occurrence claim advances to the next identical real row', (int) ($candidate2['id'] ?? 0) === 2);

$insert->execute(['d' => '2026-09-03', 'n' => 'Provider replay', 'a' => 50, 'f' => 'replay-a', 's' => 'matched', 'j' => 20]);
$insert->execute(['d' => '2026-09-03', 'n' => 'Provider replay', 'a' => 50, 'f' => 'replay-b', 's' => 'matched', 'j' => 20]);
$reviewedPreview = bankTxnDuplicatePreview($pdo, 1, 63);
$assert('shared-journal pair still requires review and is not auto-hidden',
    $reviewedPreview['unreviewed_pairs'] === 5
    && (int) $pdo->query("SELECT COUNT(*) FROM accounting_bank_statement_lines WHERE duplicate_of_line_id IS NOT NULL")->fetchColumn() === 0);

$pdo->exec('CREATE TABLE treasury_bank_line_reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL, bank_account_id INTEGER NOT NULL,
    first_line_id INTEGER NOT NULL, second_line_id INTEGER NOT NULL,
    fingerprint TEXT NOT NULL, decision TEXT NOT NULL, reason TEXT NOT NULL,
    evidence_ref TEXT, decided_by_user_id INTEGER NOT NULL,
    decided_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$sharedPair = array_values(array_filter(
    bankTxnDuplicatePreview($pdo, 1, 63)['review_pairs'],
    static fn(array $p): bool => $p['first']['line_id'] === 8 && $p['second']['line_id'] === 9
))[0];
$sharedRejected = false;
try {
    bankTxnDecideReview($pdo, 1, 63, 8, 9, $sharedPair['fingerprint'], 'distinct',
        'These are separate bank statement events', null, 42);
} catch (DomainException $e) {
    $sharedRejected = str_contains($e->getMessage(), 'share one journal');
}
$assert('a shared-journal pair cannot be cleared as distinct',
    $sharedPair['shared_journal'] && $sharedRejected);
$pair = array_values(array_filter(
    bankTxnDuplicatePreview($pdo, 1, 63)['review_pairs'],
    static fn(array $p): bool => $p['first']['line_id'] === 1 && $p['second']['line_id'] === 2
))[0];
$decision = bankTxnDecideReview(
    $pdo, 1, 63, 1, 2, $pair['fingerprint'], 'distinct',
    'Verified two distinct bank events and source journals', 'statement-2026-09', 42
);
$afterDecision = bankTxnDuplicatePreview($pdo, 1, 63);
$assert('distinct review is audited and clears only its exact pair',
    $decision['id'] > 0 && $afterDecision['review_available']
    && $afterDecision['reviewed_pairs'] === 1 && $afterDecision['unreviewed_pairs'] === 4);
$repeat = bankTxnDecideReview(
    $pdo, 1, 63, 2, 1, $pair['fingerprint'], 'distinct',
    'Verified two distinct bank events and source journals', null, 42
);
$assert('same decision is idempotent and does not append another event',
    $repeat['idempotent_replay'] && $repeat['id'] === $decision['id']
    && (int) $pdo->query('SELECT COUNT(*) FROM treasury_bank_line_reviews')->fetchColumn() === 1);
$reopened = bankTxnDecideReview(
    $pdo, 1, 63, 1, 2, $pair['fingerprint'], 'reopened',
    'Source bank statement needs another review', null, 42
);
$assert('reopen appends an audit event and restores the review queue',
    $reopened['id'] > $decision['id']
    && bankTxnDuplicatePreview($pdo, 1, 63)['unreviewed_pairs'] === 5);
bankTxnDecideReview(
    $pdo, 1, 63, 1, 2, $pair['fingerprint'], 'distinct',
    'Reconfirmed two separate source transactions', null, 42
);
$pdo->exec("UPDATE accounting_bank_statement_lines SET bank_reference='new-ref' WHERE id=2");
$assert('changed source facts invalidate a prior distinct review',
    bankTxnDuplicatePreview($pdo, 1, 63)['unreviewed_pairs'] === 5);
$staleRejected = false;
try {
    bankTxnDecideReview($pdo, 1, 63, 1, 2, $pair['fingerprint'], 'distinct',
        'Reviewing stale source facts must fail', null, 42);
} catch (DomainException $e) {
    $staleRejected = str_contains($e->getMessage(), 'facts changed');
}
$assert('stale review submissions are rejected', $staleRejected);
$crossAccountRejected = false;
try {
    bankTxnDecideReview($pdo, 1, 64, 1, 2, $pair['fingerprint'], 'distinct',
        'A different bank account cannot review these lines', null, 42);
} catch (DomainException $e) {
    $crossAccountRejected = str_contains($e->getMessage(), 'no longer available');
}
$assert('review cannot cross bank-account boundaries', $crossAccountRejected);
$assert('rejected reviews leave no open database transaction', !$pdo->inTransaction());
$assert('review decisions never alter bank-line matches or journals',
    (int) $pdo->query("SELECT COUNT(*) FROM accounting_bank_statement_lines WHERE duplicate_of_line_id IS NOT NULL")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM accounting_journal_entries WHERE status = 'posted'")->fetchColumn() === 5);

bankTxnRecordAlias($pdo, 1, 63, 1, 'plaid', 'new-plaid-id', 'new-item');
$assert('provider alias resolves to canonical row', bankTxnAliasLineId($pdo, 1, 63, 'plaid', 'new-plaid-id') === 1);
bankTxnRecordAlias($pdo, 1, 63, 2, 'plaid', 'new-plaid-id', 'new-item');
$assert('provider alias can be repointed to a verified occurrence', bankTxnAliasLineId($pdo, 1, 63, 'plaid', 'new-plaid-id') === 2);

exit($failures > 0 ? 1 : 0);
