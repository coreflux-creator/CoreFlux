<?php
/**
 * core/ai/vendor_aliases.php — vendor name normalisation + resolution.
 *
 * Spec §11: the Classification Graph asks "is this bank-feed payee the
 * SAME vendor as something we've seen?" — this is the persistent store
 * that answers it.  Lookup is cheap (single indexed query on
 * normalised alias) so the graph can call it on every transaction.
 *
 * Three public helpers:
 *   vendorAliasNormalize(string $payee): string
 *       Pure function. UPPERCASE + collapse internal whitespace +
 *       strip trailing punctuation. Same input → same output.
 *
 *   vendorAliasResolve(int $tenantId, string $payee): ?array
 *       Returns the alias row (incl. canonical_vendor_id + label +
 *       confidence) for an exact-normalised match.  Bumps `hits` +
 *       `last_hit_at` as a side-effect so the queue UI can sort by
 *       frequency.
 *
 *   vendorAliasRecord(int $tenantId, string $payee, array $opts): array
 *       Idempotent upsert.  $opts: {canonical_vendor_id?,
 *       canonical_label?, source?, confidence?, pinned?,
 *       created_by_user_id?, created_by_ai_run?}.
 *       Enforces exactly one of (vendor_id, label) populated.
 *       Returns the resulting row.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

function vendorAliasNormalize(string $payee): string
{
    $s = strtoupper(trim($payee));
    // Collapse runs of internal whitespace.
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    // Strip trailing punctuation that varies between feeds ("ACME Co.,").
    $s = rtrim($s, ".,;:!? \t\n");
    return $s;
}

/**
 * @return array|null  The alias row when found, else null.
 */
function vendorAliasResolve(int $tenantId, string $payee): ?array
{
    if ($tenantId <= 0 || $payee === '') return null;
    $key = vendorAliasNormalize($payee);
    if ($key === '') return null;

    $pdo = getDB();
    $st = $pdo->prepare(
        'SELECT id, tenant_id, alias_normalized, alias_raw,
                canonical_vendor_id, canonical_label,
                source, confidence, pinned, hits, last_hit_at,
                created_by_user_id, created_by_ai_run,
                created_at, updated_at
           FROM vendor_aliases
          WHERE tenant_id = :t AND alias_normalized = :k
          LIMIT 1'
    );
    $st->execute(['t' => $tenantId, 'k' => $key]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;

    // Bump hits asynchronously — failure to write the hit counter
    // must not break the resolution lookup.  Same call signature as
    // the rest of the codebase (silent on error, logged via errlog).
    try {
        $pdo->prepare(
            'UPDATE vendor_aliases
                SET hits = hits + 1, last_hit_at = NOW()
              WHERE id = :id AND tenant_id = :t LIMIT 1'
        )->execute(['id' => (int) $row['id'], 't' => $tenantId]);
        $row['hits']        = (int) $row['hits'] + 1;
        $row['last_hit_at'] = date('Y-m-d H:i:s');
    } catch (\Throwable $e) {
        error_log('[vendor_aliases] hit-counter update failed: ' . $e->getMessage());
    }

    if ($row['canonical_vendor_id'] !== null) $row['canonical_vendor_id'] = (int) $row['canonical_vendor_id'];
    if ($row['confidence']         !== null) $row['confidence']         = (float) $row['confidence'];
    $row['pinned'] = (int) $row['pinned'] === 1;
    return $row;
}

/**
 * Idempotent upsert. Pinned rows are NOT silently overwritten by an
 * AI re-suggestion — caller must pass `pinned=true` explicitly OR the
 * row's `pinned` flag must already be false.
 *
 * @return array {row, action}  action ∈ {created, updated, pinned_skip}
 */
function vendorAliasRecord(int $tenantId, string $payee, array $opts = []): array
{
    if ($tenantId <= 0)  throw new \InvalidArgumentException('tenantId required');
    if ($payee === '')   throw new \InvalidArgumentException('payee required');

    $vendorId = isset($opts['canonical_vendor_id']) ? (int) $opts['canonical_vendor_id'] : null;
    $label    = isset($opts['canonical_label']) ? trim((string) $opts['canonical_label']) : '';
    $hasVendor = $vendorId !== null && $vendorId > 0;
    $hasLabel  = $label !== '';
    if ($hasVendor === $hasLabel) {
        throw new \InvalidArgumentException(
            'Provide EXACTLY one of (canonical_vendor_id) or (canonical_label)'
        );
    }
    $source = (string) ($opts['source'] ?? 'ai_suggestion');
    if (!in_array($source, ['ai_suggestion', 'manual', 'imported'], true)) {
        throw new \InvalidArgumentException("source '{$source}' is not valid");
    }

    $key = vendorAliasNormalize($payee);
    if ($key === '') throw new \InvalidArgumentException('payee normalises to empty string');

    $pdo = getDB();
    $existing = vendorAliasResolveRaw($pdo, $tenantId, $key);

    // Pinned guard: refuse silent override from AI sources.
    if ($existing && (int) $existing['pinned'] === 1
        && empty($opts['pinned']) && $source === 'ai_suggestion') {
        return ['row' => vendorAliasNormalizeRow($existing), 'action' => 'pinned_skip'];
    }

    if ($existing) {
        $sets   = [
            'canonical_vendor_id = :cv',
            'canonical_label     = :cl',
            'source              = :s',
            'confidence          = :c',
            'alias_raw           = :ar',
            'updated_at          = NOW()',
        ];
        $params = [
            'cv' => $hasVendor ? $vendorId : null,
            'cl' => $hasLabel  ? $label   : null,
            's'  => $source,
            'c'  => isset($opts['confidence']) ? (float) $opts['confidence'] : null,
            'ar' => substr($payee, 0, 255),
            'id' => (int) $existing['id'],
        ];
        if (array_key_exists('pinned', $opts)) {
            $sets[]          = 'pinned = :pin';
            $params['pin']   = !empty($opts['pinned']) ? 1 : 0;
        }
        $pdo->prepare(
            'UPDATE vendor_aliases SET ' . implode(', ', $sets) .
            ' WHERE id = :id LIMIT 1'
        )->execute($params);
        $row    = vendorAliasResolveRaw($pdo, $tenantId, $key);
        return ['row' => vendorAliasNormalizeRow($row), 'action' => 'updated'];
    }

    $pdo->prepare(
        'INSERT INTO vendor_aliases
            (tenant_id, sub_tenant_id, alias_normalized, alias_raw,
             canonical_vendor_id, canonical_label,
             source, confidence, pinned,
             created_by_user_id, created_by_ai_run,
             created_at, updated_at)
         VALUES
            (:t, :st, :ak, :ar, :cv, :cl, :s, :c, :pin, :u, :ar2, NOW(), NOW())'
    )->execute([
        't'   => $tenantId,
        'st'  => $opts['sub_tenant_id'] ?? null,
        'ak'  => $key,
        'ar'  => substr($payee, 0, 255),
        'cv'  => $hasVendor ? $vendorId : null,
        'cl'  => $hasLabel  ? $label   : null,
        's'   => $source,
        'c'   => isset($opts['confidence']) ? (float) $opts['confidence'] : null,
        'pin' => !empty($opts['pinned']) ? 1 : 0,
        'u'   => $opts['created_by_user_id'] ?? null,
        'ar2' => $opts['created_by_ai_run']  ?? null,
    ]);

    $row = vendorAliasResolveRaw($pdo, $tenantId, $key);
    return ['row' => vendorAliasNormalizeRow($row), 'action' => 'created'];
}

function vendorAliasList(int $tenantId, array $filters = []): array
{
    $where  = ['tenant_id = :t'];
    $params = ['t' => $tenantId];
    if (!empty($filters['source'])) {
        $where[] = 'source = :src'; $params['src'] = (string) $filters['source'];
    }
    if (array_key_exists('pinned', $filters)) {
        $where[] = 'pinned = :pin'; $params['pin'] = !empty($filters['pinned']) ? 1 : 0;
    }
    if (!empty($filters['q'])) {
        $where[] = '(alias_normalized LIKE :q OR alias_raw LIKE :q)';
        $params['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    $limit = max(1, min(500, (int) ($filters['limit'] ?? 200)));

    $st = getDB()->prepare(
        'SELECT id, alias_normalized, alias_raw, canonical_vendor_id,
                canonical_label, source, confidence, pinned, hits,
                last_hit_at, created_at, updated_at
           FROM vendor_aliases
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY hits DESC, updated_at DESC
          LIMIT ' . $limit
    );
    $st->execute($params);
    return array_map('vendorAliasNormalizeRow', $st->fetchAll(\PDO::FETCH_ASSOC) ?: []);
}

/** Internal — raw row read by id-after-write or normalized key. */
function vendorAliasResolveRaw(\PDO $pdo, int $tenantId, string $key): ?array
{
    $st = $pdo->prepare(
        'SELECT * FROM vendor_aliases
          WHERE tenant_id = :t AND alias_normalized = :k LIMIT 1'
    );
    $st->execute(['t' => $tenantId, 'k' => $key]);
    return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
}

/** Internal — coerce SQL string-ish ints into PHP ints for the API. */
function vendorAliasNormalizeRow(?array $row): ?array
{
    if (!$row) return null;
    foreach (['id', 'tenant_id', 'sub_tenant_id', 'canonical_vendor_id',
              'hits', 'created_by_user_id'] as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null) {
            $row[$k] = (int) $row[$k];
        }
    }
    if (array_key_exists('confidence', $row) && $row['confidence'] !== null) {
        $row['confidence'] = (float) $row['confidence'];
    }
    if (array_key_exists('pinned', $row)) {
        $row['pinned'] = (int) $row['pinned'] === 1;
    }
    return $row;
}
