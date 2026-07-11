-- 124_placement_commission_writable_targets.sql
--
-- Expose placement commission sibling rows to the Integration Field Mapping
-- Studio. The JobDiva projector creates the role rows; tenant mappings can
-- then enrich the correct row by linked_entity:
--   placement_commission_recruiter
--   placement_commission_account_manager
--   placement_commission_lead
--   placement_commission_team
--   placement_commission_other

INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('placements', 'placement_commissions', 'split_pct',      'number', 'Commission split percent stored as decimal', 'placement_commission_recruiter'),
    ('placements', 'placement_commissions', 'basis',          'string', 'Commission basis: net_margin, gross_margin, bill_rate, or flat', 'placement_commission_recruiter'),
    ('placements', 'placement_commissions', 'flat_amount',    'number', 'Flat commission amount', 'placement_commission_recruiter'),
    ('placements', 'placement_commissions', 'effective_from', 'date',   'Commission effective start date', 'placement_commission_recruiter'),
    ('placements', 'placement_commissions', 'effective_to',   'date',   'Commission effective end date', 'placement_commission_recruiter'),
    ('placements', 'placement_commissions', 'notes',          'string', 'Commission notes / source context', 'placement_commission_recruiter');
