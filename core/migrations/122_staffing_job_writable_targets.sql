-- Migration 122 - expose CoreStaffing job/role fields to the
-- integration field-mapping target catalog.
--
-- Migration 078 seeded the original writable-target catalog before
-- staffing_jobs existed. Existing tenants need this additive seed so
-- JobDiva Job payloads can map into the clean staffing_jobs graph.

INSERT IGNORE INTO integration_writable_targets
    (target_module, target_table, target_column, value_type, description, default_linked_entity)
VALUES
    ('staffing', 'staffing_jobs', 'title',            'string', 'Job / role title', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'status',           'string', 'open / active / hold / filled / closed', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'description',      'string', 'Job description', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'department',       'string', 'Department / division', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'location_city',    'string', 'Worksite city', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'location_state',   'string', 'Worksite state', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'location_country', 'string', 'Worksite country (ISO-2)', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'remote_policy',    'string', 'onsite / hybrid / remote', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'opened_at',        'date',   'Job opened date', 'staffing_job'),
    ('staffing', 'staffing_jobs', 'closed_at',        'date',   'Job closed date', 'staffing_job');
