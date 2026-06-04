-- 105_ai_phase1_tool_registry_and_artifact_layer.sql
--
-- Phase 1 — Foundation: persist the Tool Registry + ship the
-- First-Class Artifact Layer per AI-Native Extension spec §2A.
--
-- Tool Registry today lives as a PHP array in
-- `core/ai/tool_gateway.php::aiToolRegistry()`. Spec demands a
-- DB-backed catalog so registrations can be admin-managed, versioned,
-- and per-tenant gated.  The PHP array becomes the SEED for
-- `tool_registry` on first read (idempotent upsert) so existing tool
-- handlers don't need to move.
--
-- Artifact Layer (spec §2A) is the spec's novel ask: every durable
-- AI-generated or workflow-generated output users review, approve,
-- export, file, or rely on becomes a first-class object with identity,
-- lifecycle, version, provenance, permissions, relationships, and
-- audit history.  We ship the three tables required to power that
-- layer; module-level wiring (close packets → artifact, recon packets
-- → artifact, etc.) lands in Slices C/D.
--
-- Idempotent. MySQL 5.7+ (Cloudways), utf8mb4_unicode_ci.

-- ─────────────────────────────────────────────────────────────────
-- tool_registry — durable catalog above ai_tool_invocations.
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tool_registry (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tool_name            VARCHAR(120) NOT NULL,
    description          TEXT NULL,
    -- RBAC code the caller must hold (legacy_map.php → permission).
    permission_required  VARCHAR(120) NOT NULL,
    -- Risk classification per spec §3 — drives the approval gate.
    risk_level           ENUM('read','draft','transactional','irreversible')
                             NOT NULL DEFAULT 'read',
    -- JSON-schema-light args (matches the PHP array shape).
    args_schema          JSON NOT NULL,
    -- Symbolic handler reference; the PHP gateway resolves this.
    handler_ref          VARCHAR(180) NOT NULL,
    -- Args whose values form the idempotency key for this tool.
    idempotency_args     JSON NULL,
    -- Tool can be disabled platform-wide without dropping the row.
    active               TINYINT(1) NOT NULL DEFAULT 1,
    -- Seed source — `php_array_seed` for tools mirrored from the
    -- PHP array, `admin_ui` for ones registered through the admin
    -- UI, `migration` for ones seeded directly by a migration.
    source               VARCHAR(40) NOT NULL DEFAULT 'php_array_seed',
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tool_name (tool_name),
    KEY ix_tr_active (active, tool_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- tool_permissions — per-tenant per-tool override of the catalog.
-- Absence of a row means "fall back to tool_registry.permission_required".
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tool_permissions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    tool_name           VARCHAR(120) NOT NULL,
    -- Hard-disable a tool for this tenant (e.g. compliance lock).
    allowed             TINYINT(1) NOT NULL DEFAULT 1,
    -- Force approval for this tool/tenant pair even if the spec
    -- classification says it shouldn't need one.
    approval_required   TINYINT(1) NOT NULL DEFAULT 0,
    -- Soft rate limit; NULL = unlimited.
    max_calls_per_hour  INT UNSIGNED NULL,
    reason              VARCHAR(255) NULL,
    set_by_user_id      INT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tp_tenant_tool (tenant_id, tool_name),
    KEY ix_tp_tenant (tenant_id, allowed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- artifact_objects — first-class platform objects above the AI layer.
-- ─────────────────────────────────────────────────────────────────
-- ai_runs already declared `artifact_id CHAR(36) NULL` (mig 090); we
-- use UUIDv4 here so the forward link stays consistent.
CREATE TABLE IF NOT EXISTS artifact_objects (
    id                  CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    sub_tenant_id       INT UNSIGNED NULL,
    -- Spec §2A enumerated kinds — extensible. Examples:
    --   close_packet, reconciliation, je_draft, cash_forecast,
    --   workpaper, tax_return, payroll_review, ap_invoice_review,
    --   exception_packet, ai_analysis, export_bundle.
    artifact_type       VARCHAR(80) NOT NULL,
    title               VARCHAR(255) NULL,
    -- Lifecycle. Transitions enforced in PHP (core/ai/artifacts.php).
    status              ENUM('draft','review','approved','final','archived','rejected')
                            NOT NULL DEFAULT 'draft',
    -- Optimistic version counter; bumps on every write.
    version             INT UNSIGNED NOT NULL DEFAULT 1,
    -- Provenance — which CoreFlux module emitted this artifact.
    source_module       VARCHAR(60) NULL,
    source_record_type  VARCHAR(60) NULL,
    source_record_id    INT UNSIGNED NULL,
    -- Compact JSON projection of the artifact body (line items, totals).
    -- For large binary payloads use storage_uri instead.
    payload_json        JSON NULL,
    -- Optional object-storage pointer (S3 / GCS / local disk).
    storage_uri         VARCHAR(512) NULL,
    storage_bytes       BIGINT UNSIGNED NULL,
    storage_mime        VARCHAR(80) NULL,
    -- Provenance — who/what produced this artifact.
    created_by_user_id  INT UNSIGNED NULL,
    created_by_ai_run   CHAR(36) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
    archived_at         TIMESTAMP NULL DEFAULT NULL,
    KEY ix_ao_tenant_type      (tenant_id, artifact_type, status),
    KEY ix_ao_tenant_recent    (tenant_id, created_at),
    KEY ix_ao_source_record    (source_module, source_record_type, source_record_id),
    KEY ix_ao_created_by_ai    (created_by_ai_run)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- artifact_events — immutable lifecycle ledger per artifact.
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS artifact_events (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    artifact_id         CHAR(36) NOT NULL,
    -- Spec §2A event kinds.  Free-form by design so module-specific
    -- events (e.g. 'tax_return.efiled') don't need schema migrations.
    event_type          VARCHAR(60) NOT NULL,
    prior_status        VARCHAR(40) NULL,
    new_status          VARCHAR(40) NULL,
    -- Provenance — exactly one of the actor_* fields will be set.
    actor_user_id       INT UNSIGNED NULL,
    actor_ai_run        CHAR(36) NULL,
    actor_worker_id     CHAR(36) NULL,
    -- Compact JSON diff / context. Full payloads stay in artifact_objects.
    payload             JSON NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_ae_artifact_recent (tenant_id, artifact_id, created_at),
    KEY ix_ae_event_type      (tenant_id, event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────
-- artifact_relationships — the artifact network edges.
-- An edge can target ANOTHER artifact OR an arbitrary source record
-- (journal_entry, ap_bill, ar_invoice, etc.) — the former case is a
-- "true artifact network" edge; the latter is provenance back to a
-- legacy domain row.
-- ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS artifact_relationships (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            INT UNSIGNED NOT NULL,
    source_artifact_id   CHAR(36) NOT NULL,
    -- Exactly one of {target_artifact_id} or {target_table+target_record_id}
    -- is populated; enforced by the artifactLink() helper.
    target_artifact_id   CHAR(36) NULL,
    target_table         VARCHAR(60) NULL,
    target_record_id     INT UNSIGNED NULL,
    relationship_type    VARCHAR(60) NOT NULL,
    -- e.g. 'derived_from', 'includes', 'approves', 'produces',
    --       'references', 'exported_as', 'attached_to'
    metadata             JSON NULL,
    created_by_user_id   INT UNSIGNED NULL,
    created_by_ai_run    CHAR(36) NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_ar_source     (tenant_id, source_artifact_id, relationship_type),
    KEY ix_ar_target_art (tenant_id, target_artifact_id),
    KEY ix_ar_target_row (tenant_id, target_table, target_record_id),
    KEY ix_ar_type       (tenant_id, relationship_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
