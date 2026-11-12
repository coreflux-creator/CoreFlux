# Native participant creation and removal

The Contract participant creation and removal endpoints now use the placement row
lock and one transaction for their company roles, vendor, cycle, participant,
corporate source, placement summary, and required audit receipt. A late validation
or audit failure rolls back the complete operation. The API keeps its existing
placement-edit permission and uses the authenticated company's placement catalog.
Missing, deleted, and foreign placements cannot be changed.

Removing an already inactive manual participant returns unchanged success and
does not add another audit receipt. Source-managed projections still require
their source workflow. Historical company, vendor and participant IDs remain
available after a manual participant is deactivated.

The database fixture covers rollback after company/role creation, rollback after
all corporate writes, successful source/summary alignment, audit failure on
removal, foreign and deleted placements, removal replay, parent-lock contention
and no financial posting. Its first CI candidate passed all 21 checks. The native
HTTP fixture also exercises creation and removal permissions, foreign IDs,
failure injection, authenticated audit receipts and removal replay.

The first candidate's module smoke lane found one source-location expectation
that still looked for company-directory validation in the API file. The check now
follows the API's delegation to the native participant writer, while retaining the
directory validation and company-creation assertions. Full corrected CI and
isolated hosted acceptance remain pending.

This change does not complete participant identity/source ownership policy,
one-time item transactions, the separate cycle configuration/assignment endpoint,
or the full integration. Those remain explicit follow-up work.
