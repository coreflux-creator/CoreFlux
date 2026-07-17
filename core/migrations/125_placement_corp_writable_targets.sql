-- 125_placement_corp_writable_targets.sql
--
-- Expose safe C2C corp-detail fields to the Integration Field Mapping
-- Studio. placement_corp_details is keyed by placement_id, so the runtime
-- writer handles it separately from normal id-based tables. Sensitive EIN
-- and storage-object ids stay out of generic mapping.

INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('placements', 'placement_corp_details', 'corp_legal_name',     'string', 'C2C corporation legal name', 'placement_corp_details'),
    ('placements', 'placement_corp_details', 'corp_address_line1',  'string', 'C2C corporation address line 1', 'placement_corp_details'),
    ('placements', 'placement_corp_details', 'corp_address_line2',  'string', 'C2C corporation address line 2', 'placement_corp_details'),
    ('placements', 'placement_corp_details', 'corp_city',           'string', 'C2C corporation city', 'placement_corp_details'),
    ('placements', 'placement_corp_details', 'corp_state',          'string', 'C2C corporation state / province', 'placement_corp_details'),
    ('placements', 'placement_corp_details', 'corp_postal_code',    'string', 'C2C corporation postal code', 'placement_corp_details'),
    ('placements', 'placement_corp_details', 'corp_country',        'string', 'C2C corporation country', 'placement_corp_details'),
    ('placements', 'placement_corp_details', 'corp_contact_name',   'string', 'C2C corporation contact name', 'placement_corp_details'),
    ('placements', 'placement_corp_details', 'corp_contact_email',  'string', 'C2C corporation contact email', 'placement_corp_details'),
    ('placements', 'placement_corp_details', 'corp_contact_phone',  'string', 'C2C corporation contact phone', 'placement_corp_details'),
    ('placements', 'placement_corp_details', 'coi_expiry',          'date',   'Certificate of insurance expiry date', 'placement_corp_details');
