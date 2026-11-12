# Reviewed JobDiva staffing rule replacement

Runtime `74906e8ff43ddb4539f2547c96c9d6dbe933c73c` is installed in the isolated Core pilot. The new advanced Contract review lets an authorized operator replace a Core-owned vendor chain, referral set or commission set with a reviewed saved JobDiva proposal. It preserves chosen Core rule IDs and releases only that set's source hold. The source's positions, roles, dates and notes remain mutable match fields, not invented provider child IDs.

The review reuses the default source writers and configured field mappings in a rollback-only transaction. It validates the saved assignment, person and employment model, limits mappings to the selected table, checks repeat projection, and seals source evidence, mappings, current rows, graph settings and linked private records. Every proposal requires an explicit compatible existing-row choice or a new-row choice. Unmatched rows retire only when they have no linked documents, credentials, portal, plan or separately reviewed participant settings. Reused rows retain their linked records. Independently reviewed participant values remain visible and retained.

Apply verifies the seal under the placement lock, preserves reused row IDs, replays the source to check convergence, reconciles the current graph and writes a required audit receipt in the same transaction. A lost-response retry returns that receipt. A subsequent Core edit is disclosed rather than falsely claiming that the old receipt still establishes source ownership. Approved rates, time and historical financial records are unchanged. No provider request or financial execution occurs in this workflow.

Flat source commissions remain blocked because these legacy rows cannot retain independent currency and payment scheduling. Alexander's INR 7,500 one-time commission is not treated as a recurring USD rule. This limitation remains part of the wider integration work.

## Verification

- All eleven jobs passed in [CI 43276286272](https://github.com/coreflux-creator/CoreFlux/actions/runs/43276286272), including 36 real-database replacement checks and 18 match/private-field checks. Ten component behavior tests, hook lint and the interface build passed.
- The preceding run passed its first 33 replacement checks but failed two static assertions that expected the old mapping-call signature. Both now verify the new options argument; the multiline text assertion also normalizes Windows line endings.
- BrowserOS exercised the actual component locally with invented records and a fixture API: explicit matching, side-by-side changes, linked-record disclosure, confirmation and submission, and the consumed selection after success. This is not a hosted authenticated browser-to-database round trip.
- Installation at 18:13:34 UTC changed 24 application files/assets. The installed-runtime fixture then passed 25 native edit/source-hold checks and 36 reviewed replacement checks. Its synthetic business records were rolled back; sequence gaps are permitted.
- Final read-only verification at 18:14:32 UTC checked 283 runtime fingerprints, all 377 tables and all 34,883 original rows, plus the earlier retired document's preserved bytes. Configuration, production, providers and the separate Arabella pilot were unchanged.

The release preflight verified the prior 265 runtime pins. Some hosted editable UI source copies are older than the compiled interface. The served entry point matched the accepted document-recovery bundle from `24e1cf2b`; the new bundle changes only `PlacementCreate`, `PlacementDetail` and the new `SourceRuleReview` from that frontend source commit. The inspected older `PlacementCreate` copy omitted the bill-to picker and worksite city already present in the served interface. Its exact preimage is recorded, rather than treating every hosted source copy as the frontend build authority.

The manifest is [thunderhawk-source-rule-review-20261112.json](../deploy/thunderhawk-source-rule-review-20261112.json). It records the tested commit, installer and fixture hashes, private receipts, protected preimages, final verification and artifact fingerprints.

## Remaining acceptance

The hosted authenticated review and role-specific HTTP/browser journey remain to be demonstrated. Native field-dictionary classifications were not changed; the last verified inventory remains 2,110 fields with 437 native decisions. Browser document upload still requires the AWS origin correction. JobDiva time/cohort/source-approval/outbound work, broader onboarding/document/lifecycle/merge acceptance, financial allocation/provenance and independent commission terms remain open. This release does not complete the full integration objective.
