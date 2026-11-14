# Weekly external timesheet review

## Candidate behavior

The weekly email path uses the original time workspace, current People and Placements ownership, stable person/placement numbers and the same protected decision writers as native time review. An authorized Staffing approver delegates a complete submitted week to a designated external email address. A worker or entry preparer cannot receive authority to approve their own entries.

Issuance stores the complete issued week, original identities, selected recipient and source facts as a versioned review fingerprint. The random credential remains hashed in `approval_tokens`; raw URLs are delivery inputs, never part of the dispatcher API response. Resending replaces earlier unused weekly links atomically with a required issuance receipt. Migration `201_staffing_email_review_basis.sql` adds nullable issued evidence without changing other approval-token subjects. Older weekly links require a fresh request because their original reviewed facts cannot be established.

Opening the email link is read-only, including legacy `confirm=1` URLs. The public page shows the worker, stable numbers, week, every entry, its source and status, full-week hours, and hours awaiting this decision. It excludes pay and bill rates. A POST must carry the revision of that displayed approval or rejection. Rates and other decision evidence are checked again under locks. Changed entries, new siblings, identities, periods, recipients or rates refuse stale approval without consuming the credential. Rejection requires a reason and does not require an approved rate.

The pending entries, weekly header, artifact history, token consumption and mandatory audit receipts commit together. Already approved siblings retain their actual prior reviewer and snapshots. An external reviewer is recorded by email with a null internal user ID. Expired, used and replaced links disclose no entries. Replay cannot create a second decision.

Email delivery retains tenant Timesheets sender settings, suppression and disabled-delivery controls. The dispatcher checks the actual delivery result, treats log-only output as undelivered and receives no secret fallback link. Outbox redactions remove the approval and rejection URLs from stored message content. The screen prevents duplicate dispatch while a request is pending and ignores a late response after a workspace change.

## Validation and installation

Exact source `311567fb` passed all eleven [CI jobs](https://github.com/coreflux-creator/CoreFlux/actions/runs/43550038483): **898 independent-connection checks**, including **37 weekly-email checks**; **953 native-session checks**; and **865 native HTTP checks**, including **33 weekly-email checks**. Temporary actor, server and private-session cleanup passed. [Pinned evidence](../deploy/thunderhawk-weekly-email-review-preparation-20261113.json). This source is not installed. Local checks passed: six rendered dispatch-screen behaviors, 14 weekly source checks, 29 first-class artifact checks, 48 mail shim checks, 84 tenant mail setting checks, changed PHP parsing and the 15-check recursive SPA bundle verification. The rebuilt screen includes both required JavaScript chunks. Native HTTP and independent-session race fixtures exercise real MariaDB transactions; the disposable business journey also checks external approval through one posted synthetic journal and unchanged replay.

The first candidate's module lane found that the direct MailService call bypassed the established tenant Timesheets routing. The follow-up preserves the existing tenant-aware sender and adds native HTTP coverage of disabled Timesheets delivery. No test expectation was weakened to remove that requirement.

This candidate is not installed. Read-only verification at **2026-11-14T03:03:30+00:00** confirmed the isolated pilot's 367 pinned files, 378 tables and all 35,304 rows remain unchanged, with configuration and temporary-access cleanup preserved. Production, providers and Arabella remain unchanged.

## Remaining integration work

The existing weekly accounting emission still happens after decision commit. This release reports a posting warning accurately and refuses calling that wrapper from an unfinished outer transaction, but it does not supply a durable financial retry mechanism. Durable handoff and frozen-rate enforcement remain required work.

The time CSV importer still permits its importer to mark rows approved, uses separate unlocked placement/ownership lookups, and writes approval metadata without the shared review fingerprint and dimension freeze. It needs an explicit model for imported third-party approval evidence, independent Core review where that evidence is absent, stable identity resolution, current ownership and category/period checks, and atomic replay-safe imports. Arbitrarily removing preapproved import would not meet the requested operational integration.

Full financial corrections/provenance, remaining field/reference/category/period semantics, required migrations, guarded installation and the complete signed-in Quanta/PurePay/PurePayroll round trip remain open. This weekly-link improvement does not establish completion of the full integration goal.
