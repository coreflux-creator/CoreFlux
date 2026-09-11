-- JobDiva C2C overhead = No is an explicit waiver, not a missing rate.
-- Repair existing source-managed drafts without mutating approved snapshots.
UPDATE placement_rates
   SET c2c_overhead_pct = 0
 WHERE approved_at IS NULL
   AND c2c_overhead_pct IS NULL
   AND JSON_VALID(economics_snapshot_json) = 1
   AND LOWER(COALESCE(
         JSON_UNQUOTE(JSON_EXTRACT(economics_snapshot_json, '$.source_system')),
         JSON_UNQUOTE(JSON_EXTRACT(economics_snapshot_json, '$.assignment_contract.source')),
         JSON_UNQUOTE(JSON_EXTRACT(economics_snapshot_json, '$.source_contract.source')),
         ''
       )) IN ('jobdiva', 'employeeassignmentrecordsdetail')
   AND LOWER(COALESCE(
         JSON_UNQUOTE(JSON_EXTRACT(economics_snapshot_json, '$.source_overheads.c2c')),
         JSON_UNQUOTE(JSON_EXTRACT(economics_snapshot_json, '$.assignment_contract.overheads.c2c')),
         JSON_UNQUOTE(JSON_EXTRACT(economics_snapshot_json, '$.source_contract.overheads.c2c')),
         JSON_UNQUOTE(JSON_EXTRACT(economics_snapshot_json, '$.assignment_contract.c2c_flag')),
         JSON_UNQUOTE(JSON_EXTRACT(economics_snapshot_json, '$.source_contract.c2c_flag')),
         ''
       )) IN ('false', '0', 'no', 'off', 'unchecked');
