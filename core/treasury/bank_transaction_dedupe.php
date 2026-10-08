<?php
/** Transaction-level bank feed similarity preview. */
declare(strict_types=1);

require_once __DIR__ . '/bank_transaction_identity.php';
require_once __DIR__ . '/bank_line_review.php';

/** @return array<int,array<int,array<string,mixed>>> */
function bankTxnPartitionRowsByIdentity(array $rows): array
{
    $buckets = [];
    foreach ($rows as $row) {
        $key = (string) ($row['posted_date'] ?? '') . '|' . bankTxnAmountKey($row['amount'] ?? 0);
        $buckets[$key][] = $row;
    }

    $partitions = [];
    foreach ($buckets as $bucketRows) {
        $bucketPartitions = [];
        foreach ($bucketRows as $row) {
            $placed = false;
            foreach ($bucketPartitions as &$partition) {
                if (bankTxnDescriptionsEquivalent(
                    (string) ($partition[0]['description'] ?? ''),
                    (string) ($row['description'] ?? '')
                )) {
                    $partition[] = $row;
                    $placed = true;
                    break;
                }
            }
            unset($partition);
            if (!$placed) $bucketPartitions[] = [$row];
        }
        foreach ($bucketPartitions as $partition) $partitions[] = $partition;
    }
    return $partitions;
}

/** @return array<int,array<string,mixed>> */
function bankTxnDuplicateClusters(PDO $pdo, int $tenantId, int $bankAccountId): array
{
    if (!bankTxnHasColumn($pdo, 'accounting_bank_statement_lines', 'duplicate_of_line_id')) return [];

    $identityColumns = [];
    foreach (['external_id', 'source_system', 'bank_reference'] as $column) {
        if (bankTxnHasColumn($pdo, 'accounting_bank_statement_lines', $column)) $identityColumns[] = $column;
    }
    $stmt = $pdo->prepare(
        'SELECT id, posted_date, description, amount, fitid, match_status,
                matched_je_id, created_at' . ($identityColumns ? ', ' . implode(', ', $identityColumns) : '') . '
           FROM accounting_bank_statement_lines
          WHERE tenant_id = :t AND bank_account_id = :a
            AND duplicate_of_line_id IS NULL
          ORDER BY posted_date DESC, id ASC'
    );
    $stmt->execute(['t' => $tenantId, 'a' => $bankAccountId]);

    $clusters = [];
    foreach (bankTxnPartitionRowsByIdentity($stmt->fetchAll(PDO::FETCH_ASSOC)) as $partition) {
        if (count($partition) > 1) $clusters[] = bankTxnDescribeDuplicateCluster($pdo, $tenantId, $partition);
    }

    usort($clusters, static fn(array $a, array $b): int => strcmp($b['posted_date'], $a['posted_date']));
    return $clusters;
}

/** @param array<int,array<string,mixed>> $rows */
function bankTxnDescribeDuplicateCluster(PDO $pdo, int $tenantId, array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $aMatched = (($a['match_status'] ?? '') === 'matched' && (int) ($a['matched_je_id'] ?? 0) > 0) ? 0 : 1;
        $bMatched = (($b['match_status'] ?? '') === 'matched' && (int) ($b['matched_je_id'] ?? 0) > 0) ? 0 : 1;
        if ($aMatched !== $bMatched) return $aMatched <=> $bMatched;
        $aJe = (int) ($a['matched_je_id'] ?? PHP_INT_MAX) ?: PHP_INT_MAX;
        $bJe = (int) ($b['matched_je_id'] ?? PHP_INT_MAX) ?: PHP_INT_MAX;
        if ($aJe !== $bJe) return $aJe <=> $bJe;
        return (int) $a['id'] <=> (int) $b['id'];
    });

    $canonical = $rows[0];
    $safe = 0; $reversible = 0; $conflicts = 0;
    $details = [];
    foreach (array_slice($rows, 1) as $row) {
        $classification = bankTxnClassifyDuplicateRow($pdo, $tenantId, $row, $canonical);
        if ($classification['action'] === 'mark_duplicate') $safe++;
        elseif ($classification['action'] === 'reverse_and_mark') $reversible++;
        else $conflicts++;
        $details[] = [
            'line_id' => (int) $row['id'],
            'description' => (string) $row['description'],
            'fitid' => (string) ($row['fitid'] ?? ''),
            'external_id' => (string) ($row['external_id'] ?? ''),
            'source_system' => (string) ($row['source_system'] ?? ''),
            'bank_reference' => (string) ($row['bank_reference'] ?? ''),
            'matched_je_id' => (int) ($row['matched_je_id'] ?? 0) ?: null,
            'action' => $classification['action'],
            'reason' => $classification['reason'],
        ];
    }

    return [
        'posted_date' => (string) $canonical['posted_date'],
        'description' => (string) $canonical['description'],
        'amount' => (float) $canonical['amount'],
        'canonical_line_id' => (int) $canonical['id'],
        'canonical_fitid' => (string) ($canonical['fitid'] ?? ''),
        'canonical_external_id' => (string) ($canonical['external_id'] ?? ''),
        'canonical_source_system' => (string) ($canonical['source_system'] ?? ''),
        'canonical_bank_reference' => (string) ($canonical['bank_reference'] ?? ''),
        'canonical_je_id' => (int) ($canonical['matched_je_id'] ?? 0) ?: null,
        'row_count' => count($rows),
        'excess_rows' => count($rows) - 1,
        'safe_rows' => $safe,
        'reversible_rows' => $reversible,
        'conflict_rows' => $conflicts,
        'duplicates' => $details,
        '_rows' => $rows,
    ];
}

function bankTxnClassifyDuplicateRow(PDO $pdo, int $tenantId, array $row, array $canonical): array
{
    $jeId = (int) ($row['matched_je_id'] ?? 0);
    $canonicalJeId = (int) ($canonical['matched_je_id'] ?? 0);
    if (($row['match_status'] ?? '') === 'matched'
        && $canonicalJeId > 0 && $canonicalJeId === $jeId) {
        return ['action' => 'mark_duplicate', 'reason' => 'duplicate row points to canonical journal entry'];
    }
    // Date, amount and similar narration identify a review candidate, not
    // proof that the bank recorded one event. Different source journals may
    // represent two real receipts even when their ledger legs are identical.
    return ['action' => 'conflict', 'reason' => $jeId > 0
        ? 'different linked journal entry; verify both source documents'
        : 'no shared journal entry; verify the bank transaction identities'];
}

/**
 * Compare manual journal economics for diagnostic tests only. Matching ledger
 * legs do not prove that two bank lines describe the same bank event, so this
 * comparison must not authorize automatic bank-line merging or JE reversal.
 */
function bankTxnIsSingleLegExactJournalDuplicate(
    PDO $pdo,
    int $tenantId,
    int $duplicateJeId,
    int $canonicalJeId,
    array $duplicateJe
): bool {
    $groupId = trim((string) ($duplicateJe['intercompany_group_id'] ?? ''));
    if ($groupId === '') return false;

    $group = $pdo->prepare(
        'SELECT COUNT(*) FROM accounting_journal_entries
          WHERE tenant_id = :t AND intercompany_group_id = :g AND status = "posted"'
    );
    $group->execute(['t' => $tenantId, 'g' => $groupId]);
    if ((int) $group->fetchColumn() !== 1) return false;

    $headers = $pdo->prepare(
        'SELECT id, status, entity_id, currency
           FROM accounting_journal_entries
          WHERE tenant_id = :t AND id IN (:duplicate, :canonical)'
    );
    $headers->execute([
        't' => $tenantId,
        'duplicate' => $duplicateJeId,
        'canonical' => $canonicalJeId,
    ]);
    $byId = [];
    foreach ($headers->fetchAll(PDO::FETCH_ASSOC) as $header) {
        $byId[(int) $header['id']] = $header;
    }
    if (count($byId) !== 2) return false;
    foreach ([$duplicateJeId, $canonicalJeId] as $id) {
        if (($byId[$id]['status'] ?? '') !== 'posted') return false;
    }
    if ((int) $byId[$duplicateJeId]['entity_id'] !== (int) $byId[$canonicalJeId]['entity_id']) return false;
    if (strtoupper((string) $byId[$duplicateJeId]['currency']) !== strtoupper((string) $byId[$canonicalJeId]['currency'])) return false;

    $duplicateSignature = bankTxnJournalLineSignature($pdo, $tenantId, $duplicateJeId);
    $canonicalSignature = bankTxnJournalLineSignature($pdo, $tenantId, $canonicalJeId);
    return $duplicateSignature !== [] && $duplicateSignature === $canonicalSignature;
}

/** @return array<int,string> */
function bankTxnJournalLineSignature(PDO $pdo, int $tenantId, int $jeId): array
{
    $stmt = $pdo->prepare(
        'SELECT l.account_id, l.debit, l.credit,
                COALESCE(l.counterparty_company_id, 0) AS counterparty_company_id,
                COALESCE(l.counterparty_person_id, 0) AS counterparty_person_id,
                COALESCE(l.counterparty_entity_id, 0) AS counterparty_entity_id,
                COALESCE(l.dim_json, "") AS dim_json
           FROM accounting_journal_entry_lines l
           JOIN accounting_journal_entries je
             ON je.id = l.je_id AND je.tenant_id = :tenant_id
          WHERE l.je_id = :je'
    );
    $stmt->execute(['tenant_id' => $tenantId, 'je' => $jeId]);
    $signature = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $signature[] = implode('|', [
            (int) $line['account_id'],
            number_format((float) $line['debit'], 2, '.', ''),
            number_format((float) $line['credit'], 2, '.', ''),
            (int) $line['counterparty_company_id'],
            (int) $line['counterparty_person_id'],
            (int) $line['counterparty_entity_id'],
            trim((string) $line['dim_json']),
        ]);
    }
    sort($signature, SORT_STRING);
    return $signature;
}

function bankTxnDuplicatePreview(PDO $pdo, int $tenantId, int $bankAccountId): array
{
    $clusters = bankTxnDuplicateClusters($pdo, $tenantId, $bankAccountId);
    $latestReviews = bankTxnLatestReviews($pdo, $tenantId, $bankAccountId);
    $preview = [
        'cluster_count' => count($clusters),
        'duplicate_rows' => 0,
        'safe_rows' => 0,
        'reversible_rows' => 0,
        'conflict_rows' => 0,
        'review_available' => bankTxnReviewTableExists($pdo),
        'unreviewed_pairs' => 0,
        'reviewed_pairs' => 0,
        'review_pairs' => [],
        'clusters' => [],
    ];
    foreach ($clusters as $cluster) {
        $preview['duplicate_rows'] += (int) $cluster['excess_rows'];
        $preview['safe_rows'] += (int) $cluster['safe_rows'];
        $preview['reversible_rows'] += (int) $cluster['reversible_rows'];
        $preview['conflict_rows'] += (int) $cluster['conflict_rows'];
        $rows = $cluster['_rows'];
        for ($i = 0; $i < count($rows); $i++) {
            for ($j = $i + 1; $j < count($rows); $j++) {
                if (!bankTxnDescriptionsEquivalent((string) $rows[$i]['description'], (string) $rows[$j]['description'])) continue;
                $first = $rows[$i]; $second = $rows[$j];
                if ((int) $first['id'] > (int) $second['id']) [$first, $second] = [$second, $first];
                $key = (int) $first['id'] . ':' . (int) $second['id'];
                $fingerprint = bankTxnReviewFingerprint($first, $second);
                $review = $latestReviews[$key] ?? null;
                $isReviewed = $review && $review['decision'] === 'distinct'
                    && hash_equals((string) $review['fingerprint'], $fingerprint);
                $preview[$isReviewed ? 'reviewed_pairs' : 'unreviewed_pairs']++;
                $preview['review_pairs'][] = [
                    'posted_date' => (string) $first['posted_date'],
                    'amount' => (float) $first['amount'],
                    'fingerprint' => $fingerprint,
                    'status' => $isReviewed ? 'distinct' : 'needs_review',
                    'shared_journal' => (int) ($first['matched_je_id'] ?? 0) > 0
                        && (int) $first['matched_je_id'] === (int) ($second['matched_je_id'] ?? 0),
                    'first' => bankTxnReviewLineSummary($first),
                    'second' => bankTxnReviewLineSummary($second),
                    'review' => $isReviewed ? [
                        'id' => (int) $review['id'],
                        'reason' => (string) $review['reason'],
                        'evidence_ref' => (string) ($review['evidence_ref'] ?? ''),
                        'decided_by_user_id' => (int) $review['decided_by_user_id'],
                        'decided_at' => (string) $review['decided_at'],
                    ] : null,
                ];
            }
        }
        unset($cluster['_rows']);
        $preview['clusters'][] = $cluster;
    }
    return $preview;
}
