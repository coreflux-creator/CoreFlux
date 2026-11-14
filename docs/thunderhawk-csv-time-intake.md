# CSV time intake and external approval evidence

This change is in source validation; it is not installed or a production cutover.

The old `already_approved=1` switch stamped the importing operator as the
approver. It now means that each row must supply external approval evidence:
the reviewer email, a timestamp including timezone, and a traceable reference.
The original CSV is retained encrypted, with its hash, source row, importer,
options and resulting time identities. An external assertion does not prove
that a client reviewed it; a different Core reviewer makes the Core decision.
The worker, creator and current importer cannot approve their own time.

The three-column placement/date/hours template still works. Optional source
identities and explicit Entry IDs support reviewed corrections to editable
time. Core placement numbers must have the placement prefix. Ambiguous source
placement IDs are refused. Provider-controlled or financially consumed time
must retain its original history and use its correction workflow.

Preview and commit use the original time workspace and current shared People
and Placements owners. A confirmation binds the exact file, mapping, options,
current entries, weekly headers, periods, identities and existing source
evidence. A changed basis requires a fresh displayed preview. Default imports
are atomic; Skip invalid is an explicit reviewed option. Audit or storage
failure rolls back the import. An identical completed request returns its
original result without rewriting time, including after approval.

Both the dedicated time importer and bulk-import wizard carry the preview
revision. Raw CSV callers may use `X-Time-Review-Revision`; JSON callers send
`review_revision`. Original source downloads require authenticated Time read
permission in the exact owning workspace. Source evidence appears during
Core review and is bound into the approval snapshot and decision revision.

Hours above 24 are retained as weekly totals, never silently distributed over
dates. Approval of those totals still needs a dated breakdown. Completing
weekly aggregate review with accurate effective rates, category and overtime
semantics remains an integration requirement; this increment does not claim
that capability is complete. No financial execution occurs during intake.

Validation includes native authenticated HTTP requests, independent database
connection races, source-download isolation, forced audit failure, unchanged
replay, importer separation, changed-evidence refusal, and the existing
synthetic time-to-invoice-to-bank-receipt journey. Record exact CI results after
the final candidate passes; local syntax or UI checks alone are insufficient.
