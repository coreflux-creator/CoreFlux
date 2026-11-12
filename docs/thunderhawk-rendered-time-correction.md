# Rendered Quanta to Core correction round trip

The November 12 synthetic correction completed through native Quanta and Core browser screens. Quanta source entry `3a18a0f9-6761-4a6e-be8f-0a5bcaa552c2`, person **P-387** and placement **PL-902** retain their identities. The current time is **120 minutes / 2.00 hours**, independently approved in both applications, with Core's accepted status returned to Quanta. This is invented test time, not real work or a payroll obligation.

## Operator journey and preserved history

The Quanta administrator unlocked the prior 90-minute week, corrected its existing entry, acknowledged the review and submitted it. A separate test approver approved the source. [Quanta worker-week acceptance](thunderhawk-quanta-worker-week-acceptance.md) records that release and the source preservation checks.

Core's native preview displayed the approved 1.50-hour entry **16**, its earlier approver and rate, the same source/person/placement identities and the newly approved 2.00-hour source. Temporary corrector **97** entered a reason and acknowledged the comparison. Core saved correction **2**, superseded entry 16, and created entry **17** pending review. Rendered correction history retained both the initial 1.00-to-1.50-hour correction and this 1.50-to-2.00-hour correction.

The corrector reviewed and returned **Awaiting Core review** to Quanta. Its attempt to approve entry 17 through Core's review queue was refused with the two-eye control message; the entry remained pending. After native sign-out, separate test approver **98** signed in and approved that entry. Core saved rate snapshot **23**, approver 98 and the approval time. Timesheet **8** and its artifact reconciled to approved, two hours. These are independent test identities, not a claim of independent human employment approval.

After the approver signed out, the corrector signed in again, reviewed **Accepted in Core**, acknowledged the destination and shared it. A fresh status review confirmed the existing return. The saved-history screen identified correction 2 for P-387 / PL-902. The corrector signed out again. All correction, approval and return mutations used rendered native controls; private helpers prepared accounts and verified state rather than carrying out those workflow actions.

## Verification and cleanup

- Core verification passed at **16:38:23 UTC**, covering **377 tables**. The 34,830 original normalized row fingerprints were retained. The two specifically excluded target rows—the source import pointer and weekly artifact—were checked separately, as were the allowed supersession/header fields and encrypted prior-entry/import evidence. The source import retains its original ID and now points to entry 17; all three source approvals remain recorded.
- New records: one time entry, one correction, one source-approval evidence row, two status deliveries, seven artifact events, six audits, two users/memberships/legacy memberships, eighteen membership audits and nine permission-comparison audit rows. No original historical paid entry or financial record changed.
- The independent provider check passed at **16:39:29 UTC** across all **71 Quanta** and **26 PurePay** collections. Source time and source approval were unchanged by the Core journey. Quanta review version **5** recorded pending review and version **6** recorded acceptance of Core entry 17. Both completed receipts match the exact source and assignment. Earlier return history remains intact. Only those two delivery receipts were appended; the target review and API-use/coordinator metadata are the explicitly accounted mutations. PurePay's records and indexes are identical.
- Read-only cleanup verification at **16:42:47 UTC** confirmed both users inactive, their passwords removed, memberships revoked, all temporary grants removed and the one-time credential file deleted. Ten protected October paid entries still total **80 hours**, and **PL-874** remains ended.
- The provider-host coordination window was released. No provider deployment/configuration change, production change, Arabella change, real payroll, payment or financial posting occurred during this Core journey.

The provider verifier initially named the receipt collection incorrectly and compared a public history projection against a full database document. Both failures were inspected against the installed contract before correcting the verifier. The corrected checks still require exactly two source-specific completed receipts and exact prior public-history preservation. No correction, approval or delivery was repeated to resolve a verification failure. The cleanup verifier was similarly corrected to check the existing membership state `revoked`, rather than `inactive`; the native deactivation had already succeeded.

## Observed follow-up work

The correction receipt appears immediately, but the source preview continues to display its old conflict label until **Preview time** is refreshed. The saved correction and new status are correct after that refresh; automatic preview refresh is still a usability improvement to implement.

Core's time review queue and Staffing weekly list use separate permission grants. The time-admin test approver could approve entry 17, but without Staffing access the weekly list refused access. A scoped Staffing read grant correctly limited it to its own linked person, which the synthetic reviewer did not have. This is not evidence of an authorization bypass or a failed approval; reviewer navigation and read access still need a deliberate cross-screen policy and acceptance check. No existing permission policy was broadened in this walkthrough.

This completes the rendered correction/reapproval/status-return journey for this synthetic cohort. It does not establish full integration completion. Remaining field dispositions, broader onboarding/document/lifecycle and merge journeys, JobDiva cohort/legacy/correction work, AWS-origin/browser-upload acceptance and financial allocation/provenance reconciliation remain open.
