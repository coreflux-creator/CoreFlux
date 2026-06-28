-- Migration 078 — integration_writable_targets catalog.
--
-- DB-driven replacement for the hardcoded PHP allow-list in
-- tenantIntegrationFieldMapAllowedInternalFields(). Lists every
-- (module, table, column) tenants can map external payload fields
-- into via the Integration Settings Field Mapping UI.
--
-- Why a DB table instead of code:
--   • Adding a column shouldn't require a deploy. Tenant ops can
--     seed an `integration_writable_targets` row for a freshly-
--     added column on `placements` and have it appear in the
--     picker on the next page load.
--   • The list lives next to the data it permits, not in a
--     separate PHP file that has to be kept in sync.
--   • Per-tenant overrides are possible (future) by adding a
--     nullable tenant_id column; the global rows are tenant_id=NULL.
--
-- Seed below covers the highest-traffic columns across each module.
-- Custom field targets are exposed via a single magic row
-- (target_table='custom_field_values', target_column='*') — the
-- apply step interprets it as "any custom field code on this
-- module's entity is mappable".

CREATE TABLE IF NOT EXISTS integration_writable_targets (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- NULL = global (visible to all tenants). Reserved for future
    -- per-tenant overrides; nothing in core writes a non-NULL value yet.
    tenant_id       BIGINT UNSIGNED DEFAULT NULL,
    target_module   VARCHAR(64)  NOT NULL,
    target_table    VARCHAR(64)  NOT NULL,
    target_column   VARCHAR(96)  NOT NULL,
    -- Coarse type for picker UX + transform compat checking.
    -- 'string' | 'number' | 'boolean' | 'date' | 'enum' | 'json' | 'cents'.
    value_type      VARCHAR(16)  NOT NULL DEFAULT 'string',
    -- Optional ENUM allowed values, JSON-encoded. NULL = no constraint.
    enum_values     JSON         DEFAULT NULL,
    -- Soft-disable without delete so audit + history survive.
    enabled         BOOLEAN      NOT NULL DEFAULT 1,
    -- Free-text picker hint shown next to the column name.
    description     VARCHAR(255) DEFAULT NULL,
    -- Default `linked_entity` proposal for this target — the UI can
    -- pre-fill the join hint based on the column's natural owner.
    -- E.g. column='end_client_name' on placements would suggest
    -- linked_entity='end_client_company' so it routes to companies.
    default_linked_entity VARCHAR(64) DEFAULT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenant_target (tenant_id, target_module, target_table, target_column),
    KEY ix_module_table (target_module, target_table, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============ SEED (global rows; tenant_id=NULL) ============

-- ---------- PEOPLE module ----------
INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('people', 'people', 'external_id',          'string', 'Source-system id (used for upsert key)', 'self'),
    ('people', 'people', 'first_name',           'string', 'Legal first name', 'self'),
    ('people', 'people', 'middle_name',          'string', 'Middle name (optional)', 'self'),
    ('people', 'people', 'last_name',            'string', 'Legal last name', 'self'),
    ('people', 'people', 'preferred_name',       'string', 'Display / nickname', 'self'),
    ('people', 'people', 'email_primary',        'string', 'Primary email', 'self'),
    ('people', 'people', 'email_secondary',      'string', 'Secondary email', 'self'),
    ('people', 'people', 'phone_primary',        'string', 'Primary phone', 'self'),
    ('people', 'people', 'phone_secondary',      'string', 'Secondary phone', 'self'),
    ('people', 'people', 'classification',       'string', 'W2 / 1099 / contractor / employee', 'self'),
    ('people', 'people', 'status',               'string', 'active / inactive / terminated', 'self'),
    ('people', 'people', 'work_auth_status',     'string', 'Work authorization status', 'self'),
    ('people', 'people', 'work_auth_expiry',     'date',   'Work auth expiration date', 'self'),
    ('people', 'people', 'home_address_line1',   'string', 'Home street line 1', 'self'),
    ('people', 'people', 'home_address_line2',   'string', 'Home street line 2', 'self'),
    ('people', 'people', 'home_city',            'string', 'Home city', 'self'),
    ('people', 'people', 'home_state',           'string', 'Home state', 'self'),
    ('people', 'people', 'home_postal_code',     'string', 'Home ZIP / postal', 'self'),
    ('people', 'people', 'home_country',         'string', 'Home country (ISO-2)', 'self'),
    ('people', 'people', 'employment_type',      'string', 'Full-time / part-time / contract', 'self'),
    ('people', 'people', 'hire_date',            'date',   'Hire date', 'self'),
    ('people', 'people', 'termination_date',     'date',   'Termination date', 'self'),
    ('people', 'people', 'pay_frequency',        'string', 'weekly / biweekly / semi / monthly', 'self'),
    ('people', 'people', 'worker_class',         'string', 'Worker classification code', 'self'),
    ('people', 'people', 'linkedin_url',         'string', 'LinkedIn profile URL', 'self'),
    ('people', 'people', 'source',               'string', 'Source / referrer slug', 'self'),
    ('people', 'people', 'recruiter_notes',      'string', 'Free-text recruiter notes', 'self');

-- ---------- PLACEMENTS module — placements table ----------
INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('placements', 'placements', 'external_id',           'string', 'Source-system Assignment id', 'self'),
    ('placements', 'placements', 'title',                 'string', 'Job title for the assignment', 'self'),
    ('placements', 'placements', 'status',                'string', 'open / closed / pending', 'self'),
    ('placements', 'placements', 'start_date',            'date',   'Assignment start date', 'self'),
    ('placements', 'placements', 'end_date',              'date',   'Planned assignment end date', 'self'),
    ('placements', 'placements', 'actual_end_date',       'date',   'Actual end date (terminated)', 'self'),
    ('placements', 'placements', 'due_date',              'date',   'Renewal / decision due date', 'self'),
    ('placements', 'placements', 'engagement_type',       'string', 'W2 / 1099 / C2C', 'self'),
    ('placements', 'placements', 'remote_policy',         'string', 'onsite / hybrid / remote', 'self'),
    ('placements', 'placements', 'worksite_state',        'string', 'Worksite state', 'self'),
    ('placements', 'placements', 'worksite_country',      'string', 'Worksite country (ISO-2)', 'self'),
    ('placements', 'placements', 'end_client_name',       'string', 'End-client name (denorm snapshot)', 'self'),
    ('placements', 'placements', 'client_approver_name',  'string', 'Client-side approver name', 'self'),
    ('placements', 'placements', 'client_approver_email', 'string', 'Client-side approver email', 'self'),
    ('placements', 'placements', 'jobdiva_job_id',        'string', 'JobDiva Job id (cross-ref)', 'self'),
    ('placements', 'placements', 'recruiter_name',        'string', 'Recruiter name', 'self'),
    ('placements', 'placements', 'recruiter_email',       'string', 'Recruiter email', 'self'),
    ('placements', 'placements', 'account_manager_name',  'string', 'Account manager name', 'self'),
    ('placements', 'placements', 'account_manager_email', 'string', 'Account manager email', 'self'),
    ('placements', 'placements', 'notes',                 'string', 'Free-text placement notes', 'self'),
    ('placements', 'placements', 'client_bill_cycle',     'string', 'weekly / biweekly / semi / monthly', 'self'),
    ('placements', 'placements', 'client_bill_cycle_anchor', 'date', 'Bill cycle anchor', 'self'),
    ('placements', 'placements', 'vendor_pay_cycle',      'string', 'weekly / biweekly / semi / monthly', 'self'),
    ('placements', 'placements', 'vendor_pay_cycle_anchor','date',  'Pay cycle anchor', 'self');

-- ---------- PLACEMENTS module — placement_rates table ----------
INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('placements', 'placement_rates', 'bill_rate',       'number', 'Client bill rate per unit', 'placement_rates'),
    ('placements', 'placement_rates', 'bill_rate_unit',  'string', 'hourly / daily / weekly / monthly', 'placement_rates'),
    ('placements', 'placement_rates', 'pay_rate',        'number', 'Worker pay rate per unit', 'placement_rates'),
    ('placements', 'placement_rates', 'pay_rate_unit',   'string', 'hourly / daily / weekly / monthly', 'placement_rates'),
    ('placements', 'placement_rates', 'currency',        'string', 'ISO-4217 currency code', 'placement_rates'),
    ('placements', 'placement_rates', 'ot_multiplier',   'number', 'Overtime multiplier (e.g. 1.5)', 'placement_rates'),
    ('placements', 'placement_rates', 'dt_multiplier',   'number', 'Double-time multiplier (e.g. 2.0)', 'placement_rates');

-- ---------- STAFFING module - staffing_jobs table ----------
INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('staffing', 'staffing_jobs', 'title',            'string', 'Job / role title', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'status',           'string', 'open / active / hold / filled / closed', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'description',      'string', 'Job description', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'department',       'string', 'Department / division', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'location_city',    'string', 'Worksite city', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'location_state',   'string', 'Worksite state', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'location_country', 'string', 'Worksite country (ISO-2)', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'remote_policy',    'string', 'onsite / hybrid / remote', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'opened_at',        'date',   'Job opened date', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'closed_at',        'date',   'Job closed date', 'staffing_job');

-- ---------- COMPANIES module ----------
INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('companies', 'companies', 'name',         'string', 'Company name', 'self'),
    ('companies', 'companies', 'external_id',  'string', 'Source-system Company id', 'self'),
    ('companies', 'companies', 'industry',     'string', 'Industry classification', 'self'),
    ('companies', 'companies', 'website',      'string', 'Company website', 'self'),
    ('companies', 'companies', 'phone',        'string', 'Main phone', 'self'),
    ('companies', 'companies', 'address_line1','string', 'Street line 1', 'self'),
    ('companies', 'companies', 'address_line2','string', 'Street line 2', 'self'),
    ('companies', 'companies', 'city',         'string', 'City', 'self'),
    ('companies', 'companies', 'state',        'string', 'State / region', 'self'),
    ('companies', 'companies', 'postal_code',  'string', 'ZIP / postal', 'self'),
    ('companies', 'companies', 'country',      'string', 'Country (ISO-2)', 'self');
-- NOTE: cross-module mappings (e.g. placement payload → end-client's
-- companies.industry) reuse the same writable-target rows above;
-- the operator overrides default_linked_entity at mapping time to
-- 'end_client_company' or 'vendor_company' as appropriate.

-- ---------- AP module ----------
INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('ap', 'ap_vendors', 'name',           'string', 'Vendor display name', 'vendor_company'),
    ('ap', 'ap_vendors', 'payment_terms',  'string', 'NET30 / NET45 / PWP / PWP_NET30 / etc.', 'vendor_company'),
    ('ap', 'ap_vendors', 'tax_id',         'string', 'Vendor TIN / EIN', 'vendor_company'),
    ('ap', 'ap_vendors', 'remit_email',    'string', 'Remittance email', 'vendor_company'),
    ('ap', 'ap_vendors', 'currency',       'string', 'Vendor preferred currency', 'vendor_company');

-- ---------- BILLING module ----------
INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('billing', 'billing_clients', 'name',           'string', 'Billing client display name', 'end_client_company'),
    ('billing', 'billing_clients', 'payment_terms',  'string', 'NET15 / NET30 / NET45 / etc.', 'end_client_company'),
    ('billing', 'billing_clients', 'tax_id',         'string', 'Client TIN', 'end_client_company'),
    ('billing', 'billing_clients', 'currency',       'string', 'Client preferred currency', 'end_client_company');

-- ---------- CUSTOM FIELD VALUES (magic row) ----------
-- target_table='custom_field_values' with target_column='*' is the
-- escape hatch — the apply step interprets it as "any custom_fields.code
-- on the linked_entity's module is a valid target". Operators wire a
-- specific mapping by setting target_column to the desired code.
INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('people',     'custom_field_values', '*', 'string', 'Any custom field on people', 'self'),
    ('placements', 'custom_field_values', '*', 'string', 'Any custom field on placements', 'self'),
    ('companies',  'custom_field_values', '*', 'string', 'Any custom field on a company (use linked_entity to pick which one)', 'self');
