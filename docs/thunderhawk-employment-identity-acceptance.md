# Employment identity acceptance

The preceding standalone-accounting reply answered the access question but made no integration progress. This continuation revalidated the authoritative worktree and successful eleven-job CI run 43199314655 before completing the pending employment identity implementation.

The native synthetic correction journey exposed a real identity defect: creating P-387 with the same name as P-368 caused the old W-2 bridge to skip employment creation. The old bridge treated name or email matches as identity. Payroll economics separately selected the first email/account match. Neither is a permanent person-to-employment relationship.

The candidate creates a one-to-one reviewed link inside the owning People directory, preserves existing employment and provider numbers, and resolves person-based payroll through that link. Existing records are never adopted by name/email. New-person creation operates only on the returned new person ID, creates pending employment with unknown employment type and overtime classification, and saves the link transactionally. A matching existing email/account requires explicit review. Directory reads no longer create records.

The person overview now supports searching, selecting, previewing and confirming an existing employment record or creating a pending one. The review binds installation, company/directory, operator, person and employment snapshot. A lost response can retry the original encrypted review and recover its receipt. Changed identities, duplicate links and account conflicts require review. The employment details route is reachable from the saved link. Linked duplicate people cannot be merged away silently.

Migration 192 makes employment type and overtime classification nullable without changing existing values, then creates the link receipt table with owner-scoped uniqueness. It does not backfill links. The field dictionary includes each link column, protects encrypted review evidence and grants no provider writes. New fields remain unreviewed.

Local rendered component checks cover explicit selection/confirmation, pending setup, exact retry, stale review, company and person switches, and read-only access. Native database checks cover migration preservation, shared names, explicit legacy adoption, isolation, owner-policy changes, atomic rollback, receipt replay and payroll resolution after contact changes. Native HTTP permission tests exercise anonymous, ungranted, read-only, write and foreign-company requests.

This document describes the candidate and its verification scope, not hosted acceptance. Deployment requires passing CI and an isolated-pilot preflight. Existing pilot employment links must be reviewed against creation/source evidence before changing payroll resolution. Production and Arabella are outside this release. No payroll, payments, financial posting or provider synchronization is authorized by this workflow.
