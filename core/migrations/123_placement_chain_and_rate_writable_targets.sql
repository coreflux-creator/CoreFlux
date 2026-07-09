-- 123_placement_chain_and_rate_writable_targets.sql
--
-- Expose the rest of placement economics to the Integration Field Mapping
-- Studio. These columns already exist in the canonical placement graph:
-- placement_rates owns rate economics; placement_client_chain owns the
-- end-client/MSP/vendor stack and portal/discount fees.

INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('placements', 'placement_rates', 'adder_pct', 'number', 'Employer burden / adder percent stored as decimal', 'placement_rates'),
    ('placements', 'placement_rates', 'background_fee_total', 'number', 'One-time background / screening fee', 'placement_rates');

INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('placements', 'placement_client_chain', 'party_name', 'string', 'MSP / prime-vendor / sub-vendor party name', 'placement_chain_prime_vendor'),
    ('placements', 'placement_client_chain', 'party_role', 'string', 'end_client / msp / prime_vendor / sub_vendor / direct', 'placement_chain_prime_vendor'),
    ('placements', 'placement_client_chain', 'portal_fee_pct', 'number', 'Portal, MSP, vendor, or discount fee percent stored as decimal', 'placement_chain_prime_vendor'),
    ('placements', 'placement_client_chain', 'portal_fee_flat', 'number', 'Flat portal, MSP, vendor, or discount fee', 'placement_chain_prime_vendor'),
    ('placements', 'placement_client_chain', 'submittal_id', 'string', 'VMS / MSP submittal id', 'placement_chain_prime_vendor'),
    ('placements', 'placement_client_chain', 'vms_job_id', 'string', 'VMS / client job id', 'placement_chain_prime_vendor');
