-- Repair structural placement graph fields after the owning module tables exist.
-- Historical core migrations conditionally skipped absent module tables; an
-- applied ledger entry alone does not prove the columns exist on an older app.
-- Definitions match core migrations 126/127/128/136/161. Existing columns and
-- values are untouched. Legacy cycle IDs are NOT copied into a different table's
-- identity space; unresolved operating-cycle references remain NULL for review.
-- Do not edit the earlier applied migrations or manufacture economic parties.

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='billing_operating_cycle_id');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placements ADD COLUMN billing_operating_cycle_id BIGINT UNSIGNED NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='ap_operating_cycle_id');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placements ADD COLUMN ap_operating_cycle_id BIGINT UNSIGNED NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='payroll_operating_cycle_id');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placements ADD COLUMN payroll_operating_cycle_id BIGINT UNSIGNED NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='client_payment_terms_override');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placements ADD COLUMN client_payment_terms_override VARCHAR(40) NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='vendor_payment_terms_override');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placements ADD COLUMN vendor_payment_terms_override VARCHAR(40) NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='vendor_pwp_enabled');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placements ADD COLUMN vendor_pwp_enabled TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='client_bill_cycle');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placements ADD COLUMN client_bill_cycle ENUM(''weekly'',''biweekly'',''semimonthly'',''monthly'',''adhoc'') NOT NULL DEFAULT ''monthly''', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='client_bill_cycle_anchor');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placements ADD COLUMN client_bill_cycle_anchor DATE NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='vendor_pay_cycle');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placements ADD COLUMN vendor_pay_cycle ENUM(''weekly'',''biweekly'',''semimonthly'',''monthly'',''adhoc'') NOT NULL DEFAULT ''biweekly''', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placements' AND COLUMN_NAME='vendor_pay_cycle_anchor');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placements ADD COLUMN vendor_pay_cycle_anchor DATE NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_client_chain' AND COLUMN_NAME='payment_terms_override');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_client_chain ADD COLUMN payment_terms_override VARCHAR(40) NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_client_chain' AND COLUMN_NAME='pwp_enabled');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_client_chain ADD COLUMN pwp_enabled TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_client_chain' AND COLUMN_NAME='is_payable');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_client_chain ADD COLUMN is_payable TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_referrals' AND COLUMN_NAME='payment_terms_override');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_referrals ADD COLUMN payment_terms_override VARCHAR(40) NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_referrals' AND COLUMN_NAME='pwp_enabled');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_referrals ADD COLUMN pwp_enabled TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_corp_details' AND COLUMN_NAME='company_id');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_corp_details ADD COLUMN company_id BIGINT UNSIGNED NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_corp_details' AND COLUMN_NAME='ap_vendor_id');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_corp_details ADD COLUMN ap_vendor_id BIGINT UNSIGNED NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_corp_details' AND COLUMN_NAME='payment_terms_override');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_corp_details ADD COLUMN payment_terms_override VARCHAR(40) NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_corp_details' AND COLUMN_NAME='pwp_enabled');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_corp_details ADD COLUMN pwp_enabled TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='bill_adder_pct');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_rates ADD COLUMN bill_adder_pct DECIMAL(8,6) NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='bill_adder_flat');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_rates ADD COLUMN bill_adder_flat DECIMAL(12,4) NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='bill_discount_pct');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_rates ADD COLUMN bill_discount_pct DECIMAL(8,6) NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='bill_discount_flat');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_rates ADD COLUMN bill_discount_flat DECIMAL(12,4) NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='workers_comp_pct');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_rates ADD COLUMN workers_comp_pct DECIMAL(8,6) NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='benefits_load_pct');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_rates ADD COLUMN benefits_load_pct DECIMAL(8,6) NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='c2c_overhead_pct');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_rates ADD COLUMN c2c_overhead_pct DECIMAL(8,6) NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='other_cost_per_hour');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_rates ADD COLUMN other_cost_per_hour DECIMAL(12,4) NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='other_cost_flat');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_rates ADD COLUMN other_cost_flat DECIMAL(12,2) NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

SET @graph_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='placement_rates' AND COLUMN_NAME='economics_snapshot_json');
SET @graph_sql := IF(@graph_col=0, 'ALTER TABLE placement_rates ADD COLUMN economics_snapshot_json JSON NULL', 'DO 0');
PREPARE graph_stmt FROM @graph_sql; EXECUTE graph_stmt; DEALLOCATE PREPARE graph_stmt;

