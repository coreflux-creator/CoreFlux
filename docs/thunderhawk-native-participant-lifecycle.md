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
directory validation and company-creation assertions.

Runtime source 3d7c35a735b3b921ce1d8670a00747516778e79e passed all 11 jobs in
[CI run 43136548296](https://github.com/coreflux-creator/CoreFlux/actions/runs/43136548296),
including 21 participant database checks and 111 native HTTP checks. The latter
retired its synthetic actor, stopped its test server, and removed session files.

The initial isolated installation hit an undefined cURL constant introduced by
an overly broad deployment-script prefix replacement. The runtime files were
restored; a separate recovery verified every original file and all 34,455 original
database rows, refreshed the web cache, and confirmed probe cleanup. Correction
9bce052e preserves the cURL constant and requires that completed recovery before
the next attempt. The runtime source is unchanged from the successful CI run.

The corrected two-file update installed at 2026-11-12 01:54:32 UTC after 39
dependency hashes matched. It preserved 368 tables, all 34,455 rows and the private
configuration. All 21 rollback-only checks then passed against the installed
runtime, again leaving every original row unchanged. The first failure, recovery,
and distinct v2 installation/verification receipts are retained in
deploy/thunderhawk-participant-lifecycle-20261111.json.

This change does not complete participant identity/source ownership policy,
one-time item transactions, the separate cycle configuration/assignment endpoint,
or the full integration. Those remain explicit follow-up work.

The next ownership regression should cover an existing JobDiva-managed corporate
participant changed in Core, followed by a source refresh. The current corporate
source updater writes company and terms before the caller suppresses overridden
graph options, while the participant upsert retains its prior source-management
flag. Both the source row and its projection need an explicit, consistent decision;
transaction atomicity alone does not establish that ownership policy.
