-- 126_staffing_economic_graph.sql
--
-- Canonical staffing economics:
--   * purpose-specific operating cycles for AR, AP, and payroll
--   * one placement economic-party graph for every receivable/payable party
--
-- Companies/people/users remain the identity roots. AP vendors are a payable
-- projection of those roots. Existing placement chain/referral/corp/commission
-- tables remain source facets and reconcile into placement_economic_parties.

CREATE TABLE IF NOT EXISTS staffing_operating_cycles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('billing','ap','payroll') NOT NULL,
    name VARCHAR(160) NOT NULL,
    cadence ENUM('weekly','biweekly','semimonthly','monthly','adhoc') NOT NULL,
    anchor_date DATE NULL,
    settlement_offset_days SMALLINT NOT NULL DEFAULT 0,
    default_payment_terms VARCHAR(40) NULL,
    payroll_pay_cycle_id BIGINT UNSIGNED NULL,
    source_system VARCHAR(40) NULL,
    external_id VARCHAR(160) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_soc_tenant_purpose_name (tenant_id, purpose, name),
    UNIQUE KEY uq_soc_tenant_source (tenant_id, purpose, source_system, external_id),
    INDEX idx_soc_tenant_purpose_active (tenant_id, purpose, active),
    INDEX idx_soc_payroll_bridge (tenant_id, payroll_pay_cycle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS placement_economic_parties (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    source_ref VARCHAR(160) NOT NULL,
    source_type ENUM('placement','worker','chain','corp','referral','commission','manual','integration') NOT NULL,
    source_id BIGINT UNSIGNED NULL,
    role VARCHAR(60) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    person_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    ap_vendor_id BIGINT UNSIGNED NULL,
    money_flow ENUM('receivable','payable','informational') NOT NULL DEFAULT 'informational',
    settlement_channel ENUM('ar','ap','payroll','none') NOT NULL DEFAULT 'none',
    fee_basis ENUM(
        'none','pay_rate','portal_fee_pct','portal_fee_flat','per_hour','per_invoice',
        'one_time','pct_bill','pct_margin','net_margin','gross_margin','bill_rate','flat'
    ) NOT NULL DEFAULT 'none',
    fee_pct DECIMAL(8,6) NULL,
    fee_flat DECIMAL(12,4) NULL,
    payment_terms VARCHAR(40) NULL,
    payment_terms_overridden TINYINT(1) NOT NULL DEFAULT 0,
    pwp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    pwp_overridden TINYINT(1) NOT NULL DEFAULT 0,
    operating_cycle_id BIGINT UNSIGNED NULL,
    cycle_overridden TINYINT(1) NOT NULL DEFAULT 0,
    effective_from DATE NULL,
    effective_to DATE NULL,
    source_system VARCHAR(40) NULL,
    source_external_id VARCHAR(160) NULL,
    source_managed TINYINT(1) NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pep_source (tenant_id, placement_id, source_ref),
    INDEX idx_pep_placement_active (tenant_id, placement_id, active),
    INDEX idx_pep_company (tenant_id, company_id),
    INDEX idx_pep_person (tenant_id, person_id),
    INDEX idx_pep_vendor (tenant_id, ap_vendor_id),
    INDEX idx_pep_cycle (tenant_id, operating_cycle_id),
    INDEX idx_pep_payable (tenant_id, settlement_channel, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A retry after a partial deployment must also add the override markers.
-- These keep relationship terms, PWP, and cycle inheritance independent:
-- changing one setting must not silently freeze the other two.
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_parties');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_parties' AND COLUMN_NAME='payment_terms_overridden');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_economic_parties ADD COLUMN payment_terms_overridden TINYINT(1) NOT NULL DEFAULT 0','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_parties' AND COLUMN_NAME='pwp_overridden');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_economic_parties ADD COLUMN pwp_overridden TINYINT(1) NOT NULL DEFAULT 0','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_parties' AND COLUMN_NAME='cycle_overridden');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_economic_parties ADD COLUMN cycle_overridden TINYINT(1) NOT NULL DEFAULT 0','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS placement_economic_obligations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    economic_party_id BIGINT UNSIGNED NOT NULL,
    source_type ENUM('time_bundle','time_entry','ar_invoice','manual') NOT NULL,
    source_ref_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NULL,
    period_end DATE NULL,
    quantity DECIMAL(12,4) NOT NULL DEFAULT 0,
    basis_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    status ENUM('projected','billed','payroll','paid','void') NOT NULL DEFAULT 'projected',
    ap_bill_id BIGINT UNSIGNED NULL,
    payroll_ref_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_peo_source_party (tenant_id, source_type, source_ref_id, economic_party_id),
    INDEX idx_peo_placement (tenant_id, placement_id, status),
    INDEX idx_peo_party (tenant_id, economic_party_id, status),
    INDEX idx_peo_ap_bill (tenant_id, ap_bill_id),
    INDEX idx_peo_payroll_ref (tenant_id, payroll_ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_economic_obligations' AND INDEX_NAME='idx_peo_payroll_ref');
SET @sql := IF(@idx=0,'CREATE INDEX idx_peo_payroll_ref ON placement_economic_obligations (tenant_id, payroll_ref_id)','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Economic settlement is a first-class AP source, not a mislabeled manual or
-- referral bill. Keep legacy values while widening both source enums.
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_bills');
SET @sql := IF(@tbl>0, "UPDATE ap_bills SET source='manual' WHERE source='' OR source IS NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@tbl>0,
  "ALTER TABLE ap_bills MODIFY COLUMN source ENUM('mail_inbox','manual','time_bundle','time_entries','time_settlement','staffing_economics','recurring','expense_report','referral') NOT NULL DEFAULT 'manual'",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ap_bill_lines');
SET @sql := IF(@tbl>0, "UPDATE ap_bill_lines SET source_type='time' WHERE source_type='' OR source_type IS NULL", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(@tbl>0,
  "ALTER TABLE ap_bill_lines MODIFY COLUMN source_type ENUM('time','time_entry','economic_party','manual','recurring','expense','referral') NOT NULL DEFAULT 'manual'",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- New purpose-specific pointers. The older *_cycle_id columns are retained as
-- compatibility pointers to payroll_pay_cycles until all callers migrate.
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='billing_operating_cycle_id');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placements ADD COLUMN billing_operating_cycle_id BIGINT UNSIGNED NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='ap_operating_cycle_id');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placements ADD COLUMN ap_operating_cycle_id BIGINT UNSIGNED NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='payroll_operating_cycle_id');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placements ADD COLUMN payroll_operating_cycle_id BIGINT UNSIGNED NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='vendor_payment_terms_override');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placements ADD COLUMN vendor_payment_terms_override VARCHAR(40) NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='vendor_pwp_enabled');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placements ADD COLUMN vendor_pwp_enabled TINYINT(1) NOT NULL DEFAULT 0','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Source facets retain relationship-level overrides so imports/exports and
-- source-specific editors can round-trip the same economic contract.
SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_client_chain');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_client_chain' AND COLUMN_NAME='payment_terms_override');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_client_chain ADD COLUMN payment_terms_override VARCHAR(40) NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_client_chain' AND COLUMN_NAME='pwp_enabled');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_client_chain ADD COLUMN pwp_enabled TINYINT(1) NOT NULL DEFAULT 0','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_client_chain' AND COLUMN_NAME='is_payable');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_client_chain ADD COLUMN is_payable TINYINT(1) NOT NULL DEFAULT 0','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_referrals');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_referrals' AND COLUMN_NAME='payment_terms_override');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_referrals ADD COLUMN payment_terms_override VARCHAR(40) NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_referrals' AND COLUMN_NAME='pwp_enabled');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_referrals ADD COLUMN pwp_enabled TINYINT(1) NOT NULL DEFAULT 0','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_corp_details');
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_corp_details' AND COLUMN_NAME='company_id');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_corp_details ADD COLUMN company_id BIGINT UNSIGNED NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_corp_details' AND COLUMN_NAME='ap_vendor_id');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_corp_details ADD COLUMN ap_vendor_id BIGINT UNSIGNED NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_corp_details' AND COLUMN_NAME='payment_terms_override');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_corp_details ADD COLUMN payment_terms_override VARCHAR(40) NULL','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_corp_details' AND COLUMN_NAME='pwp_enabled');
SET @sql := IF(@tbl>0 AND @col=0,'ALTER TABLE placement_corp_details ADD COLUMN pwp_enabled TINYINT(1) NOT NULL DEFAULT 0','DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Preserve existing cycle choices by projecting every referenced payroll
-- cycle into a purpose-specific operating cycle, then filling the new links.
SET @payroll_tables := (
    SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA=DATABASE()
       AND TABLE_NAME IN ('payroll_pay_cycles','payroll_pay_schedules')
);
SET @sql := IF(@payroll_tables=2,
  "INSERT IGNORE INTO staffing_operating_cycles
      (tenant_id, purpose, name, cadence, anchor_date, settlement_offset_days,
       payroll_pay_cycle_id, source_system, external_id, active)
   SELECT pc.tenant_id, purposes.purpose,
          CONCAT(pc.name, ' - ', UPPER(purposes.purpose)),
          ps.frequency,
          COALESCE(pc.anchor_date_override, ps.period_start_anchor),
          COALESCE(pc.pay_date_offset_days_override, ps.pay_date_offset_days, 0),
          pc.id, 'payroll_cycle', CAST(pc.id AS CHAR), pc.active
     FROM payroll_pay_cycles pc
     JOIN payroll_pay_schedules ps ON ps.id = pc.schedule_id AND ps.tenant_id = pc.tenant_id
     JOIN (
           SELECT 'billing' AS purpose
           UNION ALL SELECT 'ap'
           UNION ALL SELECT 'payroll'
     ) purposes",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements');
SET @billing_cols := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME IN ('billing_cycle_id','billing_operating_cycle_id'));
SET @sql := IF(@tbl>0 AND @billing_cols=2,
  "UPDATE placements p
      JOIN staffing_operating_cycles c
        ON c.tenant_id = p.tenant_id AND c.purpose = 'billing'
       AND c.source_system = 'payroll_cycle' AND c.payroll_pay_cycle_id = p.billing_cycle_id
       SET p.billing_operating_cycle_id = c.id
     WHERE p.billing_operating_cycle_id IS NULL AND p.billing_cycle_id IS NOT NULL",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ap_cols := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME IN ('ap_cycle_id','ap_operating_cycle_id'));
SET @sql := IF(@tbl>0 AND @ap_cols=2,
  "UPDATE placements p
      JOIN staffing_operating_cycles c
        ON c.tenant_id = p.tenant_id AND c.purpose = 'ap'
       AND c.source_system = 'payroll_cycle' AND c.payroll_pay_cycle_id = p.ap_cycle_id
       SET p.ap_operating_cycle_id = c.id
     WHERE p.ap_operating_cycle_id IS NULL AND p.ap_cycle_id IS NOT NULL",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @payroll_cols := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME IN ('payroll_cycle_id','payroll_operating_cycle_id'));
SET @sql := IF(@tbl>0 AND @payroll_cols=2,
  "UPDATE placements p
      JOIN staffing_operating_cycles c
        ON c.tenant_id = p.tenant_id AND c.purpose = 'payroll'
       AND c.source_system = 'payroll_cycle' AND c.payroll_pay_cycle_id = p.payroll_cycle_id
       SET p.payroll_operating_cycle_id = c.id
     WHERE p.payroll_operating_cycle_id IS NULL AND p.payroll_cycle_id IS NOT NULL",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('placements', 'placement_client_chain', 'payment_terms_override', 'string', 'Placement relationship payment terms such as NET30 or PWP_NET10', 'placement_chain_prime_vendor'),
    ('placements', 'placement_client_chain', 'pwp_enabled', 'boolean', 'Pay-when-paid rule for this payable chain party', 'placement_chain_prime_vendor'),
    ('placements', 'placement_client_chain', 'is_payable', 'boolean', 'Whether this chain party receives an AP obligation', 'placement_chain_prime_vendor'),
    ('placements', 'placement_referrals', 'referrer_type', 'string', 'Referral recipient type: vendor, person, or user', 'placement_referral'),
    ('placements', 'placement_referrals', 'referrer_vendor_name', 'string', 'Referral vendor or agency name, normalized into the company/vendor graph', 'placement_referral'),
    ('placements', 'placement_referrals', 'fee_pct', 'number', 'Referral fee percentage stored as a decimal', 'placement_referral'),
    ('placements', 'placement_referrals', 'fee_flat', 'number', 'Referral flat or per-hour fee amount', 'placement_referral'),
    ('placements', 'placement_referrals', 'fee_basis', 'string', 'Referral fee basis', 'placement_referral'),
    ('placements', 'placement_referrals', 'payment_terms_override', 'string', 'Referral payee terms such as NET30 or PWP_NET10', 'placement_referral'),
    ('placements', 'placement_referrals', 'pwp_enabled', 'boolean', 'Pay-when-paid rule for this referral', 'placement_referral'),
    ('placements', 'placement_referrals', 'duration_months', 'integer', 'Referral fee duration in months', 'placement_referral'),
    ('placements', 'placement_referrals', 'start_date', 'date', 'Referral fee effective start', 'placement_referral'),
    ('placements', 'placement_referrals', 'end_date', 'date', 'Referral fee effective end', 'placement_referral'),
    ('placements', 'placement_corp_details', 'payment_terms_override', 'string', 'C2C vendor payment terms for this placement', 'placement_corp_details'),
    ('placements', 'placement_corp_details', 'pwp_enabled', 'boolean', 'C2C vendor pay-when-paid rule for this placement', 'placement_corp_details'),
    ('placements', 'placements', 'vendor_payment_terms_override', 'string', 'Primary placement vendor payment terms', 'self'),
    ('placements', 'placements', 'vendor_pwp_enabled', 'boolean', 'Primary placement vendor paid-when-paid flag', 'self');
