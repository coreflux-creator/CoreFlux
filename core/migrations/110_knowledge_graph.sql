-- 110_knowledge_graph.sql
--
-- Slice 7B — Knowledge Graph foundation.
--
-- Spec §7 ("Knowledge Graph"): give the agents a queryable graph of
-- entities (vendor, customer, employee, account, period, document)
-- + edges (vendor-paid-by-account, transaction-related-to-period)
-- + a document store with FULLTEXT retrieval for natural-language
-- citation.
--
-- pgvector / true embedding similarity is DEFERRED — the user's
-- earlier direction was "keep Postgres for later". We persist the
-- embedding bytes in a BLOB column so a Postgres migration can
-- re-hydrate them without re-running OpenAI. For now retrieval uses
-- MATCH ... AGAINST (FULLTEXT) over (title, content).
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS knowledge_documents (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            BIGINT UNSIGNED NOT NULL,
    sub_tenant_id        BIGINT UNSIGNED NULL,
    doc_uri              VARCHAR(500) NOT NULL,             -- canonical identity (s3://, coreflux://, https://)
    doc_type             VARCHAR(80) NOT NULL DEFAULT 'note', -- note, policy, contract, invoice, sop, transcript, …
    title                VARCHAR(255) NOT NULL,
    content              MEDIUMTEXT NULL,                   -- extracted text (may be empty for binary docs)
    source_module        VARCHAR(80) NULL,                  -- ap, accounting, billing, staffing
    source_record_type   VARCHAR(80) NULL,
    source_record_id     BIGINT UNSIGNED NULL,
    tags_json            LONGTEXT NULL,
    -- Optional Slice A artifact link.
    artifact_id          CHAR(36) NULL,
    -- Audit.
    indexed_at           DATETIME NULL,
    created_by_user_id   BIGINT UNSIGNED NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_kd_tenant_uri (tenant_id, doc_uri),
    KEY ix_kd_tenant_type (tenant_id, doc_type, id),
    FULLTEXT KEY ft_kd_text (title, content)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_entities (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            BIGINT UNSIGNED NOT NULL,
    entity_type          VARCHAR(80) NOT NULL,              -- vendor, customer, employee, account, period, etc.
    label                VARCHAR(255) NOT NULL,
    normalized_key       VARCHAR(255) NOT NULL,             -- vendorAliasNormalize() / lowercase / etc.
    -- Optional pointer back to the source record.
    source_module        VARCHAR(80) NULL,
    source_record_type   VARCHAR(80) NULL,
    source_record_id     BIGINT UNSIGNED NULL,
    payload_json         LONGTEXT NULL,                     -- arbitrary properties
    created_by_user_id   BIGINT UNSIGNED NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_ke_tenant_type_key (tenant_id, entity_type, normalized_key),
    KEY ix_ke_source (tenant_id, source_module, source_record_type, source_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_edges (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            BIGINT UNSIGNED NOT NULL,
    from_entity_id       BIGINT UNSIGNED NOT NULL,
    to_entity_id         BIGINT UNSIGNED NOT NULL,
    relation             VARCHAR(80) NOT NULL,              -- paid_by_account, related_to_period, supplies_to_vendor
    weight               DECIMAL(5,3) NULL,                 -- relevance / confidence
    payload_json         LONGTEXT NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_kx_edge (tenant_id, from_entity_id, to_entity_id, relation),
    KEY ix_kx_from (tenant_id, from_entity_id, relation),
    KEY ix_kx_to   (tenant_id, to_entity_id, relation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_embeddings (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            BIGINT UNSIGNED NOT NULL,
    document_id          BIGINT UNSIGNED NOT NULL,
    -- The vector itself — BLOB so a future pgvector migration can
    -- re-hydrate without re-running OpenAI / Gemini embeddings.
    -- Stored as little-endian float32 sequence to match pgvector's
    -- wire format.
    model                VARCHAR(120) NOT NULL,             -- e.g. "text-embedding-3-small"
    dimension            INT UNSIGNED NOT NULL,
    vector_bytes         LONGBLOB NOT NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY ix_kemb_doc (tenant_id, document_id),
    KEY ix_kemb_model (tenant_id, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
