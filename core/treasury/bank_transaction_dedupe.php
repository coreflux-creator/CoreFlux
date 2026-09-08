<?php
/** Transaction-level bank feed duplicate preview and repair. */
declare(strict_types=1);

require_once __DIR__ . '/bank_transaction_identity.php';

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

    $stmt = $pdo->prepare(
        'SELECT id, posted_date, description, amount, fitid, match_status,
                matched_je_id, created_at
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
    if (($row['match_status'] ?? '') !== 'matched' || $jeId <= 0) {
        return ['action' => 'mark_duplicate', 'reason' => 'unposted duplicate feed row'];
    }
    if ($canonicalJeId > 0 && $canonicalJeId === $jeId) {
        return ['action' => 'mark_duplicate', 'reason' => 'duplicate row points to canonical journal entry'];
    }

    $stmt = $pdo->prepare(
        'SELECT id, status, source_module, source_ref_type, source_ref_id
           FROM accounting_journal_entries
          WHERE tenant_id = :t AND id = :id LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'id' => $jeId]);
    $je = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$je || in_array((string) ($je['status'] ?? ''), ['reversed', 'void'], true)) {
        return ['action' => 'mark_duplicate', 'reason' => 'linked journal entry is already inactive'];
    }
    if (($je['status'] ?? '') === 'posted' && ($je['source_module'] ?? '') === 'treasury_feed') {
        $lineId = (int) $row['id'];
        $directSource = ($je['source_ref_type'] ?? '') === 'bank_statement_line'
            && (int) ($je['source_ref_id'] ?? 0) === $lineId;
        $linkedSource = false;
        if (!$directSource) {
            try {
                $link = $pdo->prepare(
                    'SELECT 1 FROM accounting_subledger_links
                      WHERE tenant_id = :t AND journal_entry_id = :je
                        AND source_module = "treasury_feed"
                        AND source_record_id IN (:single, :split)
                      LIMIT 1'
                );
                $link->execute([
                    't' => $tenantId, 'je' => $jeId,
                    'single' => 'bank_line:' . $lineId,
                    'split' => 'bank_line:split:' . $lineId,
                ]);
                $linkedSource = (bool) $link->fetchColumn();
            } catch (Throwable $_) {}
        }
        if ($directSource || $linkedSource) {
            return ['action' => 'reverse_and_mark', 'reason' => 'duplicate CoreFlux-generated journal entry'];
        }
    }
    return ['action' => 'conflict', 'reason' => 'manually matched or unrelated journal entry'];
}

function bankTxnDuplicatePreview(PDO $pdo, int $tenantId, int $bankAccountId): array
{
    $clusters = bankTxnDuplicateClusters($pdo, $tenantId, $bankAccountId);
    $preview = [
        'cluster_count' => count($clusters),
        'duplicate_rows' => 0,
        'safe_rows' => 0,
        'reversible_rows' => 0,
        'conflict_rows' => 0,
        'clusters' => [],
    ];
    foreach ($clusters as $cluster) {
        $preview['duplicate_rows'] += (int) $cluster['excess_rows'];
        $preview['safe_rows'] += (int) $cluster['safe_rows'];
        $preview['reversible_rows'] += (int) $cluster['reversible_rows'];
        $preview['conflict_rows'] += (int) $cluster['conflict_rows'];
        unset($cluster['_rows']);
        $preview['clusters'][] = $cluster;
    }
    return $preview;
}

function bankTxnRepairDuplicates(
    PDO $pdo,
    int $tenantId,
    int $bankAccountId,
    ?int $actorUserId = null
): array {
    require_once __DIR__ . '/../../modules/accounting/lib/accounting.php';

    $result = ['clusters' => 0, 'rows_marked' => 0, 'journal_entries_reversed' => 0, 'conflicts' => []];
    foreach (bankTxnDuplicateClusters($pdo, $tenantId, $bankAccountId) as $cluster) {
        $rows = $cluster['_rows'];
        $canonical = $rows[0];
        $clusterChanged = false;
        foreach (array_slice($rows, 1) as $row) {
            $classification = bankTxnClassifyDuplicateRow($pdo, $tenantId, $row, $canonical);
            if ($classification['action'] === 'conflict') {
                $result['conflicts'][] = [
                    'line_id' => (int) $row['id'],
                    'matched_je_id' => (int) ($row['matched_je_id'] ?? 0) ?: null,
                    'reason' => $classification['reason'],
                ];
                continue;
            }
            if ($classification['action'] === 'reverse_and_mark') {
                accountingReverseJe(
                    $tenantId,
                    (int) $row['matched_je_id'],
                    'Duplicate bank-feed transaction from provider history replay',
                    $actorUserId
                );
                $result['journal_entries_reversed']++;
            }

            try {
                $pdo->prepare(
                    'UPDATE accounting_bank_transaction_aliases
                        SET statement_line_id = :canonical
                      WHERE tenant_id = :t AND statement_line_id = :duplicate'
                )->execute([
                    'canonical' => (int) $canonical['id'], 't' => $tenantId,
                    'duplicate' => (int) $row['id'],
                ]);
            } catch (Throwable $_) {}

            $mark = $pdo->prepare(
                'UPDATE accounting_bank_statement_lines
                    SET duplicate_of_line_id = :canonical,
                        dedupe_reason = :reason,
                        deduplicated_at = NOW()
                  WHERE tenant_id = :t AND bank_account_id = :a AND id = :id
                    AND duplicate_of_line_id IS NULL'
            );
            $mark->execute([
                'canonical' => (int) $canonical['id'],
                'reason' => 'provider_history_replay',
                't' => $tenantId, 'a' => $bankAccountId, 'id' => (int) $row['id'],
            ]);
            $result['rows_marked'] += $mark->rowCount();
            $clusterChanged = true;
        }
        if ($clusterChanged) $result['clusters']++;
    }
    $result['remaining'] = bankTxnDuplicatePreview($pdo, $tenantId, $bankAccountId);
    return $result;
}
