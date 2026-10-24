-- Requires core 166. Preserve the exact pay-rate approval while Core delegates it.
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='cf_payroll_source_rate_update'), 'DO 0',
'CREATE TRIGGER cf_payroll_source_rate_update BEFORE UPDATE ON placement_rates FOR EACH ROW
BEGIN
 DECLARE held CHAR(68) DEFAULT NULL;
 IF NOT (NEW.id <=> OLD.id) OR NOT (NEW.tenant_id <=> OLD.tenant_id)
 OR NOT (NEW.placement_id <=> OLD.placement_id) OR NOT (NEW.pay_rate <=> OLD.pay_rate)
 OR NOT (NEW.pay_rate_unit <=> OLD.pay_rate_unit) OR NOT (NEW.currency <=> OLD.currency)
 OR NOT (NEW.ot_multiplier <=> OLD.ot_multiplier) OR NOT (NEW.approved_at <=> OLD.approved_at)
 OR NOT (NEW.approved_by_user_id <=> OLD.approved_by_user_id) THEN
  SET held = (SELECT authority_id FROM payroll_source_claims WHERE placement_tenant_id=OLD.tenant_id AND rate_snapshot_id=OLD.id LIMIT 1 LOCK IN SHARE MODE);
  IF held IS NOT NULL THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''This pay rate is reserved for PurePayroll. Reconcile and release the source reservation before changing it.''; END IF;
 END IF;
END');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='cf_payroll_source_rate_delete'), 'DO 0',
'CREATE TRIGGER cf_payroll_source_rate_delete BEFORE DELETE ON placement_rates FOR EACH ROW
BEGIN
 DECLARE held CHAR(68) DEFAULT NULL;
 SET held = (SELECT authority_id FROM payroll_source_claims WHERE placement_tenant_id=OLD.tenant_id AND rate_snapshot_id=OLD.id LIMIT 1 LOCK IN SHARE MODE);
 IF held IS NOT NULL THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''This pay rate is reserved for PurePayroll. Reconcile and release the source reservation before deleting it.''; END IF;
END');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
