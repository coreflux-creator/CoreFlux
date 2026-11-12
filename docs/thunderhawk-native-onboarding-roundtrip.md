# Native onboarding round trip

On November 12, 2026 the existing isolated Thunderhawk person P-387 and placement PL-902 completed an employer requirement → worker submission → Core review → worker decision round trip. The records are synthetic. No real payroll, payment, journal, production cutover or Arabella change was made.

Core operator 100 created and confirmed one attestation and one document requirement. Publication 108 delivered them to the existing Quanta worker and assignment without replacing provider IDs. The worker used the normal password-reset and sign-in forms with the isolated dev outbox, submitted the attestation, then uploaded the supplied 1,852-byte synthetic PDF. Its original request remained pending when Quanta returned a storage error.

## Storage correction and release

The application's coordinated database wrapper was incompatible with Motor GridFS. Private file IO now uses the underlying driver outside transactions. Upload attempts retain their durable reservation, while the final receipt revalidates the worker, identity, assignment and exact requirement inside a coordinated commit. Revocation or a changed case cannot publish stale evidence during file IO. Inherited transactions and a lost coordination setting are refused.

Quanta source `6b48c0d87e3349e4ff9ba4b28c9722c16cea15dc` passed [CI 43304801568](https://github.com/coreflux-creator/Quanta/actions/runs/43304801568): 399 full-application tests against real MongoDB, 11 native transaction tests and 98 portal tests. Local application checks passed 389 with 10 native-only skips. The first native run exposed an incorrectly applied mock GridFS patch; the test fixture was corrected. The concurrent receipt test was also corrected to overlap after verified file storage, since an active upload lease may legitimately return a retryable response.

A fresh backup restored all 72 collections and 1,875 rows, with indexes, into a temporary unserved namespace. That namespace was removed. The shutdown drain appended 59 API-usage metadata rows; all prior business rows were preserved. The guarded three-file installation completed at 21:00 UTC, verified 182 backend and 82 unchanged frontend files, preserved the database except for its startup coordination counter, and left every other service unchanged. No settings were changed. A native GridFS constructor and active-snapshot refusal probe passed without writes or external connections.

## Observed result

The worker clicked Retry on the original pending submission. Quanta retained exactly one reservation, one physical file attempt and one document receipt. The file SHA-256 is `855ace556ace5a7f485be0ef4754506374be3a1b3034dae2cc23f7280b7d644b`.

Operator 100 imported both receipts. Core stored document 3 in the private vault and left both requirements awaiting review. Reviewer 101 signed in independently, opened the PDF from that vault and approved both requirements. Core case `c642d96a-e28f-43b0-8aeb-22d18a0b4e19` reached revision 8, complete. Publication 109 returned both approvals to Quanta. The worker's native assignment page displayed 2 of 2 required tasks satisfied and both employer approvals.

The deployed Core service replayed each original receipt without changing approval state. Altered receipts and a foreign-company case read were refused. These five service-boundary checks preserved all 35,040 then-current Core rows. They were not simulated browser authorization tests.

After native sign-outs, both Core test actors were retired and their credentials removed. Core's full comparison preserved all 34,986 preexisting rows across 378 tables. Quanta's existing worker identity returned to disabled, with both password fields null and no active sessions. Its original assignment and time records were preserved; the retained changes are the new onboarding evidence, test authentication/audit metadata and coordination counter. PurePay's 104 original rows remained identical. The shared-host deployment hold was released to the coordinated PurePay chat after the installation completed; its separate public canary work does not alter this pilot.

The release and evidence are recorded in [the manifest](../deploy/thunderhawk-onboarding-roundtrip-20261112.json).

## Remaining work

The overall integration is incomplete. P-387/PL-902 still needs publication to PurePay and the held two-hour payroll-input/result continuation. The original protected real payroll remains excluded from resubmission. Wider JobDiva field ownership, historical source evidence and the other operational flows remain governed by the full integration goal.

This test used guarded fixture reactivation of a previously retired synthetic identity before native sign-in; native staff reactivation is not established by it. The portal required a separate assignment refresh after refreshing the onboarding details. Core's evidence caption currently shows the worker submission timestamp beside the staff-import label, although the stored timestamps are distinct and correct. Those interface issues and the other Quanta import/export GridFS call sites remain open.
