-- Requires core/migrations/156_record_numbers.sql. Apply before serving the new code.
-- One-statement triggers work with both migration runners (no DELIMITER blocks).
-- Reapplying recreates only these triggers; existing numbers and merge aliases survive.

DROP TRIGGER IF EXISTS cf_number_placement_insert;
CREATE TRIGGER cf_number_placement_insert AFTER INSERT ON placements
FOR EACH ROW INSERT INTO core_record_numbers
    (tenant_id, entity_type, record_id, record_number, canonical_record_id)
VALUES (NEW.tenant_id, 'placement', NEW.id, CONCAT('PL-', NEW.id), NEW.id);

INSERT INTO core_record_numbers (tenant_id, entity_type, record_id, record_number, canonical_record_id)
SELECT tenant_id, 'placement', id, CONCAT('PL-', id), id FROM placements
ON DUPLICATE KEY UPDATE record_id = core_record_numbers.record_id;
