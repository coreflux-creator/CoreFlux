<?php
/**
 * core/ai/knowledge_graph.php — Slice 7B Knowledge Graph helpers.
 *
 * Spec §7: documents + entities + edges so the LLM can cite back to
 * structured + unstructured knowledge.  pgvector / embedding similarity
 * is DEFERRED — user direction "keep Postgres for later".  This slice
 * lands the schema + FULLTEXT retrieval + entity/edge graph so the
 * upgrade path is one switch away.
 *
 * Public API:
 *   knowledgeDocumentUpsert  — register / refresh a doc by doc_uri
 *   knowledgeEntityUpsert    — register an entity (vendor, account, …)
 *   knowledgeEdgeCreate      — relate two entities
 *   knowledgeSearchFulltext  — MATCH (title, content) AGAINST :q
 *   knowledgeNeighbours      — BFS neighbours of an entity
 *   knowledgeEntityGet / List
 *
 * tenant-leak-allow: every read / write is parameterized on tenant_id.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

/**
 * Normalize a free-form entity key (vendor name, account label, etc.)
 * to a stable string suitable for the UNIQUE(tenant, type, key) index.
 * Same shape as Slice B's vendorAliasNormalize.
 */
function knowledgeNormalizeKey(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = rtrim($s, ".,;:!? ");
    return $s;
}

/**
 * Upsert a knowledge document. Idempotent on (tenant_id, doc_uri).
 *
 * @return array {id, action: 'created'|'updated'}
 */
function knowledgeDocumentUpsert(int $tenantId, array $opts): array
{
    if ($tenantId <= 0)             throw new \InvalidArgumentException('tenantId required');
    $docUri = trim((string) ($opts['doc_uri'] ?? ''));
    if ($docUri === '')             throw new \InvalidArgumentException('doc_uri required');
    $title = trim((string) ($opts['title'] ?? ''));
    if ($title === '')              throw new \InvalidArgumentException('title required');

    $pdo = getDB();
    $existing = $pdo->prepare(
        'SELECT id FROM knowledge_documents
          WHERE tenant_id = :t AND doc_uri = :u LIMIT 1'
    );
    $existing->execute(['t' => $tenantId, 'u' => $docUri]);
    $row = $existing->fetch(\PDO::FETCH_ASSOC);

    if ($row) {
        $pdo->prepare(
            'UPDATE knowledge_documents
                SET title         = :ti, content = :co,
                    doc_type      = :dt,
                    source_module = :sm, source_record_type = :srt, source_record_id = :sri,
                    tags_json     = :tg,
                    artifact_id   = :ar,
                    indexed_at    = NOW(),
                    updated_at    = NOW()
              WHERE id = :id AND tenant_id = :t'
        )->execute([
            'ti' => mb_substr($title, 0, 255),
            'co' => $opts['content']            ?? null,
            'dt' => mb_substr((string) ($opts['doc_type'] ?? 'note'), 0, 80),
            'sm' => $opts['source_module']      ?? null,
            'srt'=> $opts['source_record_type'] ?? null,
            'sri'=> isset($opts['source_record_id']) ? (int) $opts['source_record_id'] : null,
            'tg' => isset($opts['tags']) ? json_encode($opts['tags'], JSON_UNESCAPED_SLASHES) : null,
            'ar' => $opts['artifact_id']        ?? null,
            'id' => (int) $row['id'], 't' => $tenantId,
        ]);
        return ['id' => (int) $row['id'], 'action' => 'updated'];
    }

    $pdo->prepare(
        'INSERT INTO knowledge_documents
            (tenant_id, sub_tenant_id, doc_uri, doc_type, title, content,
             source_module, source_record_type, source_record_id,
             tags_json, artifact_id, indexed_at, created_by_user_id, created_at, updated_at)
         VALUES
            (:t, :st, :u, :dt, :ti, :co,
             :sm, :srt, :sri,
             :tg, :ar, NOW(), :cu, NOW(), NOW())'
    )->execute([
        't'  => $tenantId,
        'st' => isset($opts['sub_tenant_id'])     ? (int) $opts['sub_tenant_id']     : null,
        'u'  => $docUri,
        'dt' => mb_substr((string) ($opts['doc_type'] ?? 'note'), 0, 80),
        'ti' => mb_substr($title, 0, 255),
        'co' => $opts['content'] ?? null,
        'sm' => $opts['source_module']      ?? null,
        'srt'=> $opts['source_record_type'] ?? null,
        'sri'=> isset($opts['source_record_id']) ? (int) $opts['source_record_id'] : null,
        'tg' => isset($opts['tags']) ? json_encode($opts['tags'], JSON_UNESCAPED_SLASHES) : null,
        'ar' => $opts['artifact_id'] ?? null,
        'cu' => isset($opts['created_by_user_id']) ? (int) $opts['created_by_user_id'] : null,
    ]);
    return ['id' => (int) $pdo->lastInsertId(), 'action' => 'created'];
}

/**
 * Upsert an entity. Idempotent on (tenant_id, entity_type, normalized_key).
 *
 * @return array {id, action: 'created'|'updated'}
 */
function knowledgeEntityUpsert(int $tenantId, string $entityType, string $label, array $opts = []): array
{
    if ($tenantId <= 0)            throw new \InvalidArgumentException('tenantId required');
    $entityType = trim($entityType);
    $label      = trim($label);
    if ($entityType === '')        throw new \InvalidArgumentException('entity_type required');
    if ($label === '')             throw new \InvalidArgumentException('label required');

    $normKey = knowledgeNormalizeKey($opts['normalized_key'] ?? $label);
    if ($normKey === '') $normKey = knowledgeNormalizeKey($label);

    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id FROM knowledge_entities
          WHERE tenant_id = :t AND entity_type = :et AND normalized_key = :k LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'et' => $entityType, 'k' => $normKey]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($row) {
        $pdo->prepare(
            'UPDATE knowledge_entities
                SET label              = :l,
                    source_module      = :sm,
                    source_record_type = :srt,
                    source_record_id   = :sri,
                    payload_json       = :p,
                    updated_at         = NOW()
              WHERE id = :id AND tenant_id = :t'
        )->execute([
            'l'  => mb_substr($label, 0, 255),
            'sm' => $opts['source_module']      ?? null,
            'srt'=> $opts['source_record_type'] ?? null,
            'sri'=> isset($opts['source_record_id']) ? (int) $opts['source_record_id'] : null,
            'p'  => isset($opts['payload']) ? json_encode($opts['payload'], JSON_UNESCAPED_SLASHES) : null,
            'id' => (int) $row['id'], 't' => $tenantId,
        ]);
        return ['id' => (int) $row['id'], 'action' => 'updated'];
    }

    $pdo->prepare(
        'INSERT INTO knowledge_entities
            (tenant_id, entity_type, label, normalized_key,
             source_module, source_record_type, source_record_id,
             payload_json, created_by_user_id, created_at, updated_at)
         VALUES
            (:t, :et, :l, :k, :sm, :srt, :sri, :p, :cu, NOW(), NOW())'
    )->execute([
        't'  => $tenantId,
        'et' => mb_substr($entityType, 0, 80),
        'l'  => mb_substr($label, 0, 255),
        'k'  => mb_substr($normKey, 0, 255),
        'sm' => $opts['source_module']      ?? null,
        'srt'=> $opts['source_record_type'] ?? null,
        'sri'=> isset($opts['source_record_id']) ? (int) $opts['source_record_id'] : null,
        'p'  => isset($opts['payload']) ? json_encode($opts['payload'], JSON_UNESCAPED_SLASHES) : null,
        'cu' => isset($opts['created_by_user_id']) ? (int) $opts['created_by_user_id'] : null,
    ]);
    return ['id' => (int) $pdo->lastInsertId(), 'action' => 'created'];
}

/**
 * Create (or upsert) an edge between two entities. Idempotent on
 * (tenant, from, to, relation).
 */
function knowledgeEdgeCreate(int $tenantId, int $fromEntityId, int $toEntityId, string $relation, array $opts = []): array
{
    if ($tenantId <= 0)            throw new \InvalidArgumentException('tenantId required');
    if ($fromEntityId <= 0 || $toEntityId <= 0) throw new \InvalidArgumentException('entity ids required');
    $relation = trim($relation);
    if ($relation === '')          throw new \InvalidArgumentException('relation required');

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO knowledge_edges
            (tenant_id, from_entity_id, to_entity_id, relation, weight, payload_json, created_at)
         VALUES (:t, :f, :to, :r, :w, :p, NOW())
         ON DUPLICATE KEY UPDATE
            weight       = COALESCE(VALUES(weight), weight),
            payload_json = COALESCE(VALUES(payload_json), payload_json)'
    )->execute([
        't' => $tenantId,
        'f' => $fromEntityId, 'to' => $toEntityId,
        'r' => mb_substr($relation, 0, 80),
        'w' => isset($opts['weight']) ? round((float) $opts['weight'], 3) : null,
        'p' => isset($opts['payload']) ? json_encode($opts['payload'], JSON_UNESCAPED_SLASHES) : null,
    ]);
    $id = (int) $pdo->lastInsertId();
    return ['id' => $id, 'tenant_id' => $tenantId,
            'from_entity_id' => $fromEntityId, 'to_entity_id' => $toEntityId,
            'relation' => $relation];
}

/**
 * Full-text search over (title, content) of knowledge_documents.
 * MySQL ngram parser keeps short-token searches usable; default
 * matcher uses NATURAL LANGUAGE MODE.
 *
 * @return array  {results: [{id,title,doc_type,score}], query, total}
 */
function knowledgeSearchFulltext(int $tenantId, string $query, int $limit = 20): array
{
    if ($tenantId <= 0)        throw new \InvalidArgumentException('tenantId required');
    $query = trim($query);
    if ($query === '')         throw new \InvalidArgumentException('query required');
    $limit = max(1, min(100, $limit));

    try {
        $stmt = getDB()->prepare(
            'SELECT id, title, doc_uri, doc_type,
                    LEFT(content, 280) AS snippet,
                    MATCH(title, content) AGAINST (:q IN NATURAL LANGUAGE MODE) AS score
               FROM knowledge_documents
              WHERE tenant_id = :t
                AND MATCH(title, content) AGAINST (:q IN NATURAL LANGUAGE MODE)
              ORDER BY score DESC LIMIT ' . $limit
        );
        $stmt->execute(['t' => $tenantId, 'q' => $query]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        // FULLTEXT index missing (e.g. sandbox MyISAM/InnoDB version
        // quirks) — fall back to LIKE.
        $stmt = getDB()->prepare(
            'SELECT id, title, doc_uri, doc_type,
                    LEFT(content, 280) AS snippet,
                    NULL AS score
               FROM knowledge_documents
              WHERE tenant_id = :t
                AND (title LIKE :q OR content LIKE :q)
              ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute(['t' => $tenantId, 'q' => '%' . $query . '%']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    foreach ($rows as &$r) {
        $r['id']    = (int) $r['id'];
        $r['score'] = $r['score'] !== null ? round((float) $r['score'], 4) : null;
    } unset($r);

    return ['results' => $rows, 'query' => $query, 'total' => count($rows)];
}

/**
 * Read an entity by id (tenant-scoped).
 */
function knowledgeEntityGet(int $tenantId, int $entityId): ?array
{
    if ($tenantId <= 0 || $entityId <= 0) return null;
    $stmt = getDB()->prepare(
        'SELECT * FROM knowledge_entities
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $entityId, 't' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['id'] = (int) $row['id'];
    $row['payload'] = $row['payload_json']
        ? (json_decode((string) $row['payload_json'], true) ?: [])
        : [];
    return $row;
}

/**
 * One-hop neighbours of an entity (incoming OR outgoing edges).
 * @return array  {entity, edges_out:[{relation, neighbour}], edges_in:[…]}
 */
function knowledgeNeighbours(int $tenantId, int $entityId, int $limit = 50): array
{
    $entity = knowledgeEntityGet($tenantId, $entityId);
    if (!$entity) return ['entity' => null, 'edges_out' => [], 'edges_in' => []];
    $limit = max(1, min(200, $limit));

    // tenant-leak-allow: parent entity verified tenant-scoped above
    $out = getDB()->prepare(
        'SELECT e.id AS edge_id, e.relation, e.weight,
                n.id, n.entity_type, n.label
           FROM knowledge_edges e
           JOIN knowledge_entities n ON n.id = e.to_entity_id
          WHERE e.tenant_id = :t AND e.from_entity_id = :f
          ORDER BY e.id DESC LIMIT ' . $limit
    );
    $out->execute(['t' => $tenantId, 'f' => $entityId]);
    $edgesOut = $out->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    // tenant-leak-allow: parent entity verified tenant-scoped above
    $in = getDB()->prepare(
        'SELECT e.id AS edge_id, e.relation, e.weight,
                n.id, n.entity_type, n.label
           FROM knowledge_edges e
           JOIN knowledge_entities n ON n.id = e.from_entity_id
          WHERE e.tenant_id = :t AND e.to_entity_id = :to
          ORDER BY e.id DESC LIMIT ' . $limit
    );
    $in->execute(['t' => $tenantId, 'to' => $entityId]);
    $edgesIn = $in->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    foreach ($edgesOut as &$e) {
        $e['id']      = (int)   $e['id'];
        $e['edge_id'] = (int)   $e['edge_id'];
        $e['weight']  = $e['weight'] !== null ? (float) $e['weight'] : null;
    } unset($e);
    foreach ($edgesIn as &$e) {
        $e['id']      = (int)   $e['id'];
        $e['edge_id'] = (int)   $e['edge_id'];
        $e['weight']  = $e['weight'] !== null ? (float) $e['weight'] : null;
    } unset($e);

    return ['entity' => $entity, 'edges_out' => $edgesOut, 'edges_in' => $edgesIn];
}

/**
 * List entities, optionally filtered by type or label prefix.
 */
function knowledgeEntityList(int $tenantId, array $filters = []): array
{
    if ($tenantId <= 0) return [];
    $where  = ['tenant_id = :t'];
    $params = ['t' => $tenantId];
    if (!empty($filters['entity_type'])) {
        $where[] = 'entity_type = :et'; $params['et'] = (string) $filters['entity_type'];
    }
    if (!empty($filters['label_like'])) {
        $where[] = 'label LIKE :lk';    $params['lk'] = '%' . $filters['label_like'] . '%';
    }
    $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
    $stmt = getDB()->prepare(
        'SELECT id, entity_type, label, source_module, source_record_id, created_at
           FROM knowledge_entities
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY id DESC LIMIT ' . $limit
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['source_record_id'] = $r['source_record_id'] !== null ? (int) $r['source_record_id'] : null;
    } unset($r);
    return $rows;
}
