# Staffing onboarding

Core owns placement-linked employer requirements and review decisions. Quanta is
the intended worker interaction surface; PurePayroll retains payroll setup and
execution authority. None of these statuses can substitute for the others.

The initial case workflow is available in People > person > Onboarding. An
administrator selects an existing placement, adds the employer's requirements,
and explicitly confirms the list. No default legal, immigration, tax or payroll
requirements are invented. A case with no required tasks is unconfirmed, never
complete. Each task specifies its responsible party, instructions, evidence type
and whether it is required. Editing or retiring requirements invalidates plan
confirmation; editing a task also clears that task's prior evidence and review.

Staff can record an attestation or select a document from that person's existing
document vault. Staff entry is labelled `core_staff`; it never impersonates an
employee's attestation. Submitted evidence requires an explicit approve, reject
or waive decision, a reason, responsible reviewer and optional expiry. Waivers
are explicit decisions with history, not silent deletion of requirements.

Document ownership checks include operating workspace, authorized People
directory, person, storage module, storage entity and storage-key prefix. The
evidence revision covers document metadata plus the actual stored object's ETag,
version, size and last-modified value. Approval rechecks that revision. A later
change, deletion, inaccessible object or expiry prevents a complete status when
the case is read. Storage availability failures fail closed. No file contents,
storage keys or signed URLs are returned by the onboarding endpoint.

`complete` means the confirmed required tasks currently have valid approvals or
waivers. It is an observation at the returned timestamp. It neither activates a
person/placement nor grants portal access, checks payroll setup or authorizes a
payroll run. The existing workflow engine handles multi-step financial approvals;
these per-task evidence revisions and case history are not financial approvals.

Cases belong to the operating tenant independently of their People and Placement
directory owners. Every operation re-resolves those owners and checks the current
person/placement relationship. UUIDs identify cases, tasks and retry requests;
confirmation and task review also bind to the current placement setup (legal
entity, classification, dates, business unit, WC class, worksite and status).
Changing that setup requires renewed confirmation and review; reconfirming the
list alone cannot carry an approval from the prior context.
canonical person/placement numbers remain attached. Conditional case versions
prevent stale changes. Each mutation, immutable event and idempotent receipt
commits atomically. Reusing a retry identifier with another payload or actor
conflicts. A case contains at most 25 tasks including retired tasks; history is
retained and the view explicitly flags when only its latest 100 events are shown.

`people.onboarding.view`, `.manage` and `.review` currently map to People admin
authority through the existing RBAC resolver. Reading also requires Placement
view authority. The API supplies capabilities to the UI. These permissions do
not grant payroll, banking or worker access. The API does not send invitations,
messages or files. Migration 171 is additive and replayable.

Portal rollout remains separate: this initial workflow displays that the case is
not published to Quanta. A future approved projection must use verified worker
and assignment links and the durable delivery ledger. Worker submissions must
carry an authenticated actor, task revision and replay identity, and may only
move evidence into review. They cannot approve/waive tasks or change employer
requirements. Document uploads need their own restricted evidence flow; the
general metadata contract must not carry private document contents or signed URLs.
