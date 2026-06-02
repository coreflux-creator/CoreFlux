-- =============================================================================
-- Migration 100 — RBAC closeout for CPA layer
-- =============================================================================
-- Extends the persona model in three ways:
--
-- 1) Adds CPA-firm-specific persona_type values to tenant_memberships so a
--    CPA user can be modelled as a `cpa` / `cpa_partner` / `cpa_staff` /
--    `bookkeeper` / `client_advisor` / `external_auditor` inside any
--    client tenant. The existing `_ALLOWED_PERSONA_TYPES` whitelist in
--    api/admin/memberships.php gets the same expansion.
--
-- 2) Adds `rbac_permission_profiles` — named bundles of module grants that
--    can be applied to a newly-created membership. Removes the "I just
--    created a new CPA membership, now I have to click admin/write on 9
--    modules" toil. Seeded with CoreFlux-standard profiles per persona.
--
-- 3) Adds `cpa_firm_client_links` — lightweight pointer from a CPA-firm
--    master tenant to each CLIENT master tenant the firm manages. Many CPA
--    operators need a "My clients" landing page across all their client
--    businesses; this is the table that powers it. Memberships still live
--    on tenant_memberships (one row per CPA user × per client tenant).
--
-- All idempotent. Safe to re-run.
-- =============================================================================

-- 1) Extend persona_type ENUM (idempotent — MySQL has no IF NOT EXISTS on
--    ENUM members, so we MODIFY the column to the new set every time).
ALTER TABLE tenant_memberships
    MODIFY COLUMN persona_type
        ENUM('master_admin','tenant_admin','admin','manager',
             'employee','contractor','client','vendor',
             'platform_staff','custom',
             -- CPA-firm-side personas, added by migration 100.
             'cpa','cpa_partner','cpa_staff',
             'bookkeeper','client_advisor','external_auditor')
        NOT NULL DEFAULT 'employee';

-- 2) Permission profiles (named bundles of module grants).
CREATE TABLE IF NOT EXISTS rbac_permission_profiles (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profile_key         VARCHAR(60)   NOT NULL,           -- e.g. 'cpa.default', 'bookkeeper.read_only'
    label               VARCHAR(120)  NOT NULL,
    description         VARCHAR(500)  NULL,
    -- Which persona_types this profile applies to. NULL = applies to any.
    applies_to_persona  VARCHAR(40)   NULL,
    -- JSON array of { module_key, access_level } objects.
    grants_json         JSON          NOT NULL,
    is_system           TINYINT(1)    NOT NULL DEFAULT 0, -- seeded profiles, can't be deleted
    tenant_id           INT UNSIGNED  NULL,               -- NULL = global profile; non-NULL = tenant-private
    created_by_user_id  INT UNSIGNED  NULL,
    created_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_profile (tenant_id, profile_key),
    KEY ix_profile_persona (applies_to_persona)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed CoreFlux-standard global profiles (tenant_id NULL = visible to all
-- tenants). Operators may override any of these per tenant by creating a
-- profile with the same profile_key under their own tenant_id.
-- INSERT IGNORE so re-running migration 100 doesn't conflict.
INSERT IGNORE INTO rbac_permission_profiles
    (profile_key, label, description, applies_to_persona, grants_json, is_system, tenant_id)
VALUES
    ('cpa.default',
     'CPA — full books access',
     'Read/write/admin across accounting, billing, AP, AR, treasury, reporting. Standard onboarding profile for the firm''s primary CPA on a client account.',
     'cpa',
     JSON_ARRAY(
       JSON_OBJECT('module_key','accounting',    'access_level','admin'),
       JSON_OBJECT('module_key','billing',       'access_level','admin'),
       JSON_OBJECT('module_key','ap',            'access_level','admin'),
       JSON_OBJECT('module_key','ar',            'access_level','admin'),
       JSON_OBJECT('module_key','treasury',      'access_level','admin'),
       JSON_OBJECT('module_key','cfo',           'access_level','admin'),
       JSON_OBJECT('module_key','reports',       'access_level','admin'),
       JSON_OBJECT('module_key','staffing',      'access_level','write'),
       JSON_OBJECT('module_key','time',          'access_level','write'),
       JSON_OBJECT('module_key','people',        'access_level','write'),
       JSON_OBJECT('module_key','integrations',  'access_level','admin')
     ),
     1, NULL),

    ('cpa_partner.default',
     'CPA Partner — review + admin',
     'Same as CPA but also includes period_close, JE post-back override, and tenant-admin level access on integrations + RBAC itself. Use for firm owners + reviewers.',
     'cpa_partner',
     JSON_ARRAY(
       JSON_OBJECT('module_key','accounting',    'access_level','admin'),
       JSON_OBJECT('module_key','billing',       'access_level','admin'),
       JSON_OBJECT('module_key','ap',            'access_level','admin'),
       JSON_OBJECT('module_key','ar',            'access_level','admin'),
       JSON_OBJECT('module_key','treasury',      'access_level','admin'),
       JSON_OBJECT('module_key','cfo',           'access_level','admin'),
       JSON_OBJECT('module_key','reports',       'access_level','admin'),
       JSON_OBJECT('module_key','staffing',      'access_level','admin'),
       JSON_OBJECT('module_key','time',          'access_level','admin'),
       JSON_OBJECT('module_key','people',        'access_level','admin'),
       JSON_OBJECT('module_key','integrations',  'access_level','admin'),
       JSON_OBJECT('module_key','rbac',          'access_level','admin')
     ),
     1, NULL),

    ('cpa_staff.default',
     'CPA Staff — books write, no admin',
     'Day-to-day bookkeeping access: write on accounting/billing/AP/AR/treasury, read on reports + CFO surfaces.',
     'cpa_staff',
     JSON_ARRAY(
       JSON_OBJECT('module_key','accounting',    'access_level','write'),
       JSON_OBJECT('module_key','billing',       'access_level','write'),
       JSON_OBJECT('module_key','ap',            'access_level','write'),
       JSON_OBJECT('module_key','ar',            'access_level','write'),
       JSON_OBJECT('module_key','treasury',      'access_level','write'),
       JSON_OBJECT('module_key','reports',       'access_level','read'),
       JSON_OBJECT('module_key','cfo',           'access_level','read'),
       JSON_OBJECT('module_key','time',          'access_level','read'),
       JSON_OBJECT('module_key','people',        'access_level','read')
     ),
     1, NULL),

    ('bookkeeper.default',
     'Bookkeeper — transactional only',
     'Write access to AP, AR, treasury, accounting. Read on reports. Useful for internal employees doing day-to-day data entry.',
     'bookkeeper',
     JSON_ARRAY(
       JSON_OBJECT('module_key','accounting',    'access_level','write'),
       JSON_OBJECT('module_key','ap',            'access_level','write'),
       JSON_OBJECT('module_key','ar',            'access_level','write'),
       JSON_OBJECT('module_key','treasury',      'access_level','write'),
       JSON_OBJECT('module_key','billing',       'access_level','write'),
       JSON_OBJECT('module_key','reports',       'access_level','read')
     ),
     1, NULL),

    ('client_advisor.default',
     'Client Advisor / Fractional CFO',
     'Admin on CFO + reports, write on accounting/billing/AP/AR/treasury, read on time/people. Designed for a fractional CFO doing variance analysis + reporting.',
     'client_advisor',
     JSON_ARRAY(
       JSON_OBJECT('module_key','cfo',           'access_level','admin'),
       JSON_OBJECT('module_key','reports',       'access_level','admin'),
       JSON_OBJECT('module_key','accounting',    'access_level','write'),
       JSON_OBJECT('module_key','billing',       'access_level','write'),
       JSON_OBJECT('module_key','ap',            'access_level','write'),
       JSON_OBJECT('module_key','ar',            'access_level','write'),
       JSON_OBJECT('module_key','treasury',      'access_level','write'),
       JSON_OBJECT('module_key','time',          'access_level','read'),
       JSON_OBJECT('module_key','people',        'access_level','read')
     ),
     1, NULL),

    ('external_auditor.default',
     'External Auditor — read-only',
     'Read across every audit-relevant module. Use only via the tokenized auditor URL or with an explicit start_date / end_date scope.',
     'external_auditor',
     JSON_ARRAY(
       JSON_OBJECT('module_key','accounting',    'access_level','read'),
       JSON_OBJECT('module_key','billing',       'access_level','read'),
       JSON_OBJECT('module_key','ap',            'access_level','read'),
       JSON_OBJECT('module_key','ar',            'access_level','read'),
       JSON_OBJECT('module_key','treasury',      'access_level','read'),
       JSON_OBJECT('module_key','cfo',           'access_level','read'),
       JSON_OBJECT('module_key','reports',       'access_level','read'),
       JSON_OBJECT('module_key','time',          'access_level','read'),
       JSON_OBJECT('module_key','people',        'access_level','read')
     ),
     1, NULL);

-- 3) CPA-firm ↔ client tenant link table.
-- One row per (firm_tenant_id, client_tenant_id). The firm picks which
-- of its clients to surface in the "My clients" CPA-nav landing page,
-- and which CPA users on the firm's roster have what default access.
CREATE TABLE IF NOT EXISTS cpa_firm_client_links (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firm_tenant_id              INT UNSIGNED NOT NULL,   -- master tenant of the CPA firm
    client_tenant_id            INT UNSIGNED NOT NULL,   -- master tenant of the client business
    relationship_type           ENUM('books_full','books_review_only','tax_only','advisory_only','custom') NOT NULL DEFAULT 'books_full',
    status                      ENUM('active','pending','paused','ended') NOT NULL DEFAULT 'active',
    primary_cpa_user_id         INT UNSIGNED NULL,       -- the lead CPA on the relationship
    engagement_start_date       DATE NULL,
    engagement_end_date         DATE NULL,
    notes                       VARCHAR(500) NULL,
    created_by_user_id          INT UNSIGNED NULL,
    created_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_firm_client (firm_tenant_id, client_tenant_id),
    KEY ix_firm   (firm_tenant_id, status),
    KEY ix_client (client_tenant_id, status),
    KEY ix_primary_cpa (primary_cpa_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
