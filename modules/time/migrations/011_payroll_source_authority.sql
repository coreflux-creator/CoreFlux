-- Requires core 166. Quoted compound statements work with both SQL splitters.
-- The locking read sees a newly committed claim even in an older transaction.
SET @extras := CONCAT(
 IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='source_system'), ' OR NOT (NEW.source_system <=> OLD.source_system)', ''),
 IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='external_id'), ' OR NOT (NEW.external_id <=> OLD.external_id)', ''),
 IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='time_entries' AND column_name='payable'), ' OR NOT (NEW.payable <=> OLD.payable)', '')
);
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='cf_payroll_source_time_update'), 'DO 0', CONCAT(
'CREATE TRIGGER cf_payroll_source_time_update BEFORE UPDATE ON time_entries FOR EACH ROW
BEGIN
 DECLARE held CHAR(68) DEFAULT NULL;
 IF NOT (NEW.id <=> OLD.id) OR NOT (NEW.tenant_id <=> OLD.tenant_id)
 OR NOT (NEW.person_id <=> OLD.person_id) OR NOT (NEW.placement_id <=> OLD.placement_id)
 OR NOT (NEW.work_date <=> OLD.work_date) OR NOT (NEW.category <=> OLD.category)
 OR NOT (NEW.custom_category_id <=> OLD.custom_category_id) OR NOT (NEW.hours <=> OLD.hours)
 OR NOT (NEW.status <=> OLD.status) OR NOT (NEW.approved_at <=> OLD.approved_at)
 OR NOT (NEW.approved_by_user_id <=> OLD.approved_by_user_id) OR NOT (NEW.approved_via <=> OLD.approved_via)
 OR NOT (NEW.rate_snapshot_id <=> OLD.rate_snapshot_id) OR NOT (NEW.superseded_by_id <=> OLD.superseded_by_id)
 OR NOT (NEW.source <=> OLD.source) OR NOT (NEW.source_ref_id <=> OLD.source_ref_id)
 OR NOT (NEW.payroll_extracted_at <=> OLD.payroll_extracted_at)
 OR NOT (NEW.payroll_extracted_ref <=> OLD.payroll_extracted_ref)
 OR NOT (NEW.payroll_extracted_by_user_id <=> OLD.payroll_extracted_by_user_id)', @extras, ' THEN
  SET held = (SELECT authority_id FROM payroll_source_claims WHERE time_tenant_id=OLD.tenant_id AND time_entry_id=OLD.id LOCK IN SHARE MODE);
  IF held IS NOT NULL THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''Time is reserved for PurePayroll. Reconcile and release that reservation before changing payroll source.''; END IF;
 END IF;
END'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name='cf_payroll_source_time_delete'), 'DO 0',
'CREATE TRIGGER cf_payroll_source_time_delete BEFORE DELETE ON time_entries FOR EACH ROW
BEGIN
 DECLARE held CHAR(68) DEFAULT NULL;
 SET held = (SELECT authority_id FROM payroll_source_claims WHERE time_tenant_id=OLD.tenant_id AND time_entry_id=OLD.id LOCK IN SHARE MODE);
 IF held IS NOT NULL THEN SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT=''Time is reserved for PurePayroll. Reconcile and release that reservation before deleting source time.''; END IF;
END');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
