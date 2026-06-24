-- 112_people_graph.sql
--
-- People Graph MVP.
--
-- People Graph is the platform authority/responsibility layer. It does not
-- replace the existing people, users, companies, tenant_memberships, or
-- ai_workers source tables. It links those actors through tenant-scoped graph
-- edges, responsibilities, delegations, teams, roles, and audit records.
--
-- Idempotent. No foreign keys yet: this layer bridges existing legacy and
-- module-owned tables that may be deployed in different slices.

CREATE TABLE IF NOT EXISTS people_graph_organizations (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    org_key             VARCHAR(120) NULL,
    name                VARCHAR(200) NOT NULL,
    org_type            ENUM('tenant','legal_entity','client','vendor','department','location','cost_center','project','trust','fund','practice','external','other') NOT NULL DEFAULT 'other',
    source_table        VARCHAR(80) NULL,
    source_id           BIGINT UNSIGNED NULL,
    status              ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    metadata_json       LONGTEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_pg_org_key (tenant_id, org_key),
    KEY ix_pg_org_source (tenant_id, source_table, source_id),
    KEY ix_pg_org_type (tenant_id, org_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_actor_links (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    actor_type          ENUM('person','user','organization','company','team','role','ai_worker','external') NOT NULL,
    actor_id            BIGINT UNSIGNED NOT NULL,
    person_id           BIGINT UNSIGNED NULL,
    user_id             INT UNSIGNED NULL,
    organization_id     BIGINT UNSIGNED NULL,
    company_id          BIGINT UNSIGNED NULL,
    ai_worker_id        BIGINT UNSIGNED NULL,
    label               VARCHAR(200) NULL,
    status              ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    source              VARCHAR(80) NULL,
    metadata_json       LONGTEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_pg_actor (tenant_id, actor_type, actor_id),
    KEY ix_pg_actor_person (tenant_id, person_id),
    KEY ix_pg_actor_user (tenant_id, user_id),
    KEY ix_pg_actor_company (tenant_id, company_id),
    KEY ix_pg_actor_worker (ai_worker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_teams (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    team_key            VARCHAR(120) NOT NULL,
    name                VARCHAR(200) NOT NULL,
    module_scope        VARCHAR(80) NULL,
    description         VARCHAR(1000) NULL,
    status              ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    metadata_json       LONGTEXT NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_pg_team_key (tenant_id, team_key),
    KEY ix_pg_team_scope (tenant_id, module_scope, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_team_memberships (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    team_id             BIGINT UNSIGNED NOT NULL,
    member_actor_type   ENUM('person','user','organization','company','team','role','ai_worker','external') NOT NULL,
    member_actor_id     BIGINT UNSIGNED NOT NULL,
    membership_role     VARCHAR(80) NULL,
    status              ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    starts_at           DATETIME NULL,
    ends_at             DATETIME NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_pg_team_member (tenant_id, team_id, member_actor_type, member_actor_id),
    KEY ix_pg_team_member_actor (tenant_id, member_actor_type, member_actor_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_roles (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    role_key            VARCHAR(120) NOT NULL,
    label               VARCHAR(200) NOT NULL,
    module_scope        VARCHAR(80) NULL,
    description         VARCHAR(1000) NULL,
    status              ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    metadata_json       LONGTEXT NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_pg_role_key (tenant_id, role_key),
    KEY ix_pg_role_scope (tenant_id, module_scope, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_role_assignments (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    role_id             BIGINT UNSIGNED NOT NULL,
    actor_type          ENUM('person','user','organization','company','team','role','ai_worker','external') NOT NULL,
    actor_id            BIGINT UNSIGNED NOT NULL,
    context_module      VARCHAR(80) NULL,
    context_entity_type VARCHAR(80) NULL,
    context_entity_id   VARCHAR(120) NULL,
    status              ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    starts_at           DATETIME NULL,
    ends_at             DATETIME NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY ix_pg_role_actor (tenant_id, actor_type, actor_id, status),
    KEY ix_pg_role_context (tenant_id, context_module, context_entity_type, context_entity_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_relationships (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    source_actor_type   ENUM('person','user','organization','company','team','role','ai_worker','external') NOT NULL,
    source_actor_id     BIGINT UNSIGNED NOT NULL,
    relationship_type   ENUM('reports_to','manages','member_of','owns','accountable_to','approves_for','reviews_for','supervises_ai','notifies','escalates_to','delegates_to','primary_contact_for','works_for','custom') NOT NULL,
    target_actor_type   ENUM('person','user','organization','company','team','role','ai_worker','external') NOT NULL,
    target_actor_id     BIGINT UNSIGNED NOT NULL,
    context_module      VARCHAR(80) NULL,
    context_entity_type VARCHAR(80) NULL,
    context_entity_id   VARCHAR(120) NULL,
    status              ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    starts_at           DATETIME NULL,
    ends_at             DATETIME NULL,
    metadata_json       LONGTEXT NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY ix_pg_rel_source (tenant_id, source_actor_type, source_actor_id, relationship_type, status),
    KEY ix_pg_rel_target (tenant_id, target_actor_type, target_actor_id, relationship_type, status),
    KEY ix_pg_rel_context (tenant_id, context_module, context_entity_type, context_entity_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_responsibility_assignments (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    object_module       VARCHAR(80) NOT NULL,
    object_type         VARCHAR(80) NOT NULL,
    object_id           VARCHAR(120) NOT NULL,
    responsibility_type ENUM('owner','accountable','approver','reviewer','ai_supervisor','notifier','operator','viewer','escalation_contact') NOT NULL,
    actor_type          ENUM('person','user','organization','company','team','role','ai_worker','external') NOT NULL,
    actor_id            BIGINT UNSIGNED NOT NULL,
    priority            INT NOT NULL DEFAULT 100,
    status              ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    starts_at           DATETIME NULL,
    ends_at             DATETIME NULL,
    conditions_json     LONGTEXT NULL,
    source              VARCHAR(80) NULL,
    assigned_by_user_id BIGINT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY ix_pg_resp_object (tenant_id, object_module, object_type, object_id, responsibility_type, status),
    KEY ix_pg_resp_actor (tenant_id, actor_type, actor_id, responsibility_type, status),
    KEY ix_pg_resp_status (tenant_id, status, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_delegations (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    from_actor_type     ENUM('person','user','organization','company','team','role','ai_worker','external') NOT NULL,
    from_actor_id       BIGINT UNSIGNED NOT NULL,
    to_actor_type       ENUM('person','user','organization','company','team','role','ai_worker','external') NOT NULL,
    to_actor_id         BIGINT UNSIGNED NOT NULL,
    delegation_type     ENUM('approval','review','notification','ownership','supervision','all') NOT NULL DEFAULT 'all',
    object_module       VARCHAR(80) NULL,
    object_type         VARCHAR(80) NULL,
    object_id           VARCHAR(120) NULL,
    status              ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
    starts_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ends_at             DATETIME NULL,
    reason              VARCHAR(1000) NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    revoked_by_user_id  BIGINT UNSIGNED NULL,
    revoked_at          DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY ix_pg_del_from (tenant_id, from_actor_type, from_actor_id, delegation_type, status, starts_at, ends_at),
    KEY ix_pg_del_to (tenant_id, to_actor_type, to_actor_id, status),
    KEY ix_pg_del_object (tenant_id, object_module, object_type, object_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_permission_grants (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    subject_actor_type  ENUM('person','user','organization','company','team','role','ai_worker','external') NOT NULL,
    subject_actor_id    BIGINT UNSIGNED NOT NULL,
    action              VARCHAR(120) NOT NULL,
    resource_module     VARCHAR(80) NULL,
    resource_type       VARCHAR(80) NOT NULL,
    resource_id         VARCHAR(120) NULL,
    scope_type          VARCHAR(80) NULL,
    scope_id            VARCHAR(120) NULL,
    conditions_json     LONGTEXT NULL,
    status              ENUM('active','inactive','revoked') NOT NULL DEFAULT 'active',
    starts_at           DATETIME NULL,
    ends_at             DATETIME NULL,
    granted_by_user_id  BIGINT UNSIGNED NULL,
    revoked_by_user_id  BIGINT UNSIGNED NULL,
    revoked_at          DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY ix_pg_perm_subject (tenant_id, subject_actor_type, subject_actor_id, action, status),
    KEY ix_pg_perm_resource (tenant_id, resource_module, resource_type, resource_id, action, status),
    KEY ix_pg_perm_scope (tenant_id, scope_type, scope_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_approval_policies (
    id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                  BIGINT UNSIGNED NOT NULL,
    policy_key                 VARCHAR(120) NOT NULL,
    name                       VARCHAR(200) NOT NULL,
    resource_module            VARCHAR(80) NULL,
    resource_type              VARCHAR(80) NOT NULL,
    scope_type                 VARCHAR(80) NULL,
    scope_id                   VARCHAR(120) NULL,
    priority                   INT NOT NULL DEFAULT 100,
    status                     ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    requires_human_for_ai      TINYINT(1) NOT NULL DEFAULT 1,
    metadata_json              LONGTEXT NULL,
    created_by_user_id         BIGINT UNSIGNED NULL,
    created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_pg_approval_policy_key (tenant_id, policy_key),
    KEY ix_pg_approval_policy_resource (tenant_id, resource_module, resource_type, scope_type, scope_id, status),
    KEY ix_pg_approval_policy_priority (tenant_id, priority, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_approval_policy_rules (
    id                              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                       BIGINT UNSIGNED NOT NULL,
    policy_id                       BIGINT UNSIGNED NOT NULL,
    sequence_num                    INT NOT NULL DEFAULT 1,
    condition_json                  LONGTEXT NULL,
    approver_strategy               ENUM('role','relationship','responsibility','named_actor','manager_chain') NOT NULL,
    approver_role_id                BIGINT UNSIGNED NULL,
    approver_role_key               VARCHAR(120) NULL,
    relationship_type               ENUM('reports_to','manages','member_of','owns','accountable_to','approves_for','reviews_for','supervises_ai','notifies','escalates_to','delegates_to','primary_contact_for','works_for','custom') NULL,
    responsibility_type             ENUM('owner','accountable','approver','reviewer','ai_supervisor','notifier','operator','viewer','escalation_contact') NULL,
    approver_actor_type             ENUM('person','user','organization','company','team','role','ai_worker','external') NULL,
    approver_actor_id               BIGINT UNSIGNED NULL,
    scope_type                      VARCHAR(80) NULL,
    scope_id                        VARCHAR(120) NULL,
    minimum_approvals               INT NOT NULL DEFAULT 1,
    separation_of_duties_required   TINYINT(1) NOT NULL DEFAULT 0,
    status                          ENUM('active','inactive','deleted') NOT NULL DEFAULT 'active',
    metadata_json                   LONGTEXT NULL,
    created_by_user_id              BIGINT UNSIGNED NULL,
    created_at                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY ix_pg_approval_rule_policy (tenant_id, policy_id, status, sequence_num),
    KEY ix_pg_approval_rule_role (tenant_id, approver_role_id, approver_role_key, status),
    KEY ix_pg_approval_rule_resolution (tenant_id, approver_strategy, responsibility_type, relationship_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_notification_preferences (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    actor_type          ENUM('person','user','organization','company','team','role','ai_worker','external') NOT NULL,
    actor_id            BIGINT UNSIGNED NOT NULL,
    event_scope         VARCHAR(120) NOT NULL,
    channel             ENUM('email','in_app','push','sms','webhook') NOT NULL DEFAULT 'in_app',
    enabled             TINYINT(1) NOT NULL DEFAULT 1,
    metadata_json       LONGTEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_pg_notify (tenant_id, actor_type, actor_id, event_scope, channel),
    KEY ix_pg_notify_actor (tenant_id, actor_type, actor_id, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS people_graph_audit_log (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           BIGINT UNSIGNED NOT NULL,
    actor_user_id       BIGINT UNSIGNED NULL,
    event               VARCHAR(120) NOT NULL,
    target_table        VARCHAR(120) NULL,
    target_id           BIGINT UNSIGNED NULL,
    meta_json           LONGTEXT NULL,
    ip_address          VARCHAR(45) NULL,
    request_id          VARCHAR(64) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY ix_pg_audit_tenant_time (tenant_id, created_at),
    KEY ix_pg_audit_event (tenant_id, event, created_at),
    KEY ix_pg_audit_target (tenant_id, target_table, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
