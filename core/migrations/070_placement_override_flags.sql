-- 070_placement_override_flags.sql
--
-- JobDiva Placements Edit — Slice 2 (backend-only).
--
-- Adds a JSON column to `placements` that tracks which fields have been
-- edited inside CoreFlux. The JobDiva sync writer (jobdivaSyncUpsertPlacement)
-- reads this list before issuing the UPDATE and removes overridden columns
-- from the SET clause, so subsequent JobDiva pulls don't silently revert
-- a CoreFlux operator's edit.
--
-- Shape:
--   coreflux_overridden_fields := JSON array of column names, e.g.
--     ["title", "end_client_name", "notes"]
--
-- Semantics:
--   - Empty / NULL → all JobDiva fields flow through (default).
--   - Field listed → JobDiva sync skips that column.
--   - "Revert" UI calls /api/placements/placements?action=clear_override
--     to drop the field from the list.

ALTER TABLE placements
    ADD COLUMN coreflux_overridden_fields JSON NULL
    AFTER external_id;
