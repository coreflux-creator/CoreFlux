-- Requires core/migrations/156_record_numbers.sql. Apply before serving the new code.
-- One-statement triggers work with both migration runners (no DELIMITER blocks).
-- Reapplying recreates only these triggers; existing numbers and merge aliases survive.

DROP TRIGGER IF EXISTS cf_number_client_insert;
CREATE TRIGGER cf_number_client_insert AFTER INSERT ON staffing_clients
FOR EACH ROW INSERT INTO core_record_numbers
    (tenant_id, entity_type, record_id, record_number, canonical_record_id)
VALUES (NEW.tenant_id, 'client', NEW.id, CONCAT('CL-', NEW.id), NEW.id);

INSERT INTO core_record_numbers (tenant_id, entity_type, record_id, record_number, canonical_record_id)
SELECT tenant_id, 'client', id, CONCAT('CL-', id), id FROM staffing_clients
ON DUPLICATE KEY UPDATE record_id = core_record_numbers.record_id;
