-- AP — 010 — Bill approval workflows + per-bill approval steps
-- Idempotent. Adds:
--   1. ap_approval_workflows  — tenant-level rules ("over $5k → CFO")
--   2. ap_bill_approvals      — per-bill, per-step approval state
--
-- Workflow design:
--   • A workflow has 1+ rules ordered by min_amount.
--   • The rule whose [min_amount, max_amount) brackets the bill total decides
--     which approver_user_id signs off. Multi-step approval (e.g. manager
--     then CFO) is modelled by stacking rules on the same min_amount with
--     ascending step_no.

CREATE TABLE IF NOT EXISTS ap_approval_workflows (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT UNSIGNED NOT NULL,
    name               VARCHAR(120) NOT NULL,
    is_active          TINYINT(1)   NOT NULL DEFAULT 1,
    is_default         TINYINT(1)   NOT NULL DEFAULT 0,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_apaw_default (tenant_id, is_default),
    INDEX idx_apaw_tenant      (tenant_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_approval_workflow_rules (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT UNSIGNED NOT NULL,
    workflow_id        INT UNSIGNED NOT NULL,
    step_no            INT UNSIGNED NOT NULL DEFAULT 1,
    min_amount         DECIMAL(14,2) NOT NULL DEFAULT 0,
    max_amount         DECIMAL(14,2) NULL,                       -- null = no upper bound
    approver_user_id   INT UNSIGNED NOT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_apawr_workflow (tenant_id, workflow_id, step_no, min_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_bill_approvals (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT UNSIGNED NOT NULL,
    bill_id            INT UNSIGNED NOT NULL,
    workflow_id        INT UNSIGNED NULL,
    step_no            INT UNSIGNED NOT NULL DEFAULT 1,
    approver_user_id   INT UNSIGNED NOT NULL,
    state              ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    decision_at        TIMESTAMP NULL DEFAULT NULL,
    decision_note      VARCHAR(500) NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_apba_step (tenant_id, bill_id, step_no),
    INDEX idx_apba_pending (tenant_id, approver_user_id, state, created_at),
    INDEX idx_apba_bill    (tenant_id, bill_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
