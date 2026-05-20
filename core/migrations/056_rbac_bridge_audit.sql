-- =======================================================================
-- Core migration 056 — RBAC B4 bridge disagreement audit
-- -----------------------------------------------------------------------
-- Tracks moments when the legacy `RBAC::hasPermission()` and the new
-- `RBACResolver::can()` disagree about a request. The dual-check bridge
-- (CF_RBAC_BRIDGE_MODE=dual, the default) ANDs both layers — so a
-- disagreement is harmless at runtime (the user is denied or the request
-- proceeds as legacy intended), but it tells us where the two layers
-- drift so we can fix the divergence before flipping the bridge to
-- `CF_RBAC_BRIDGE_MODE=new`.
--
-- Append-only. One row per disagreement. Bounded by the bridge writer:
-- only emits when legacy_ok != new_ok, so steady-state traffic produces
-- zero rows on a well-aligned tenant.
--
-- Index supports the /api/admin/rbac_bridge_health.php aggregation query
-- (last-24h count + top-N perms + recent samples).
--
-- All idempotent. Safe to re-run.
-- =======================================================================

CREATE TABLE IF NOT EXISTS rbac_bridge_audit (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT UNSIGNED NULL,
    user_id       INT UNSIGNED NULL,
    perm          VARCHAR(120) NOT NULL,
    module_key    VARCHAR(60)  NOT NULL,
    action        VARCHAR(20)  NOT NULL,
    legacy_ok     TINYINT(1)   NOT NULL,
    new_ok        TINYINT(1)   NOT NULL,
    occurred_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_rba_occurred (occurred_at),
    KEY ix_rba_perm     (perm, occurred_at),
    KEY ix_rba_tenant   (tenant_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
