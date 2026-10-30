-- Requires core/migrations/156_record_numbers.sql. Apply before serving the new code.
-- One-statement triggers work with both migration runners (no DELIMITER blocks).
-- Reapplying recreates only these triggers; existing numbers and merge aliases survive.

DROP TRIGGER IF EXISTS cf_number_person_insert;
CREATE TRIGGER cf_number_person_insert AFTER INSERT ON people
FOR EACH ROW INSERT INTO core_record_numbers
    (tenant_id, entity_type, record_id, record_number, canonical_record_id)
VALUES (NEW.tenant_id, 'person', NEW.id, CONCAT('P-', NEW.id), NEW.id);

INSERT INTO core_record_numbers (tenant_id, entity_type, record_id, record_number, canonical_record_id)
SELECT tenant_id, 'person', id, CONCAT('P-', id), id FROM people
ON DUPLICATE KEY UPDATE record_id = core_record_numbers.record_id;

DROP TRIGGER IF EXISTS cf_number_company_insert;
CREATE TRIGGER cf_number_company_insert AFTER INSERT ON companies
FOR EACH ROW INSERT INTO core_record_numbers
    (tenant_id, entity_type, record_id, record_number, canonical_record_id)
VALUES (NEW.tenant_id, 'company', NEW.id, CONCAT('CO-', NEW.id), NEW.id);

INSERT INTO core_record_numbers (tenant_id, entity_type, record_id, record_number, canonical_record_id)
SELECT tenant_id, 'company', id, CONCAT('CO-', id), id FROM companies
ON DUPLICATE KEY UPDATE record_id = core_record_numbers.record_id;
