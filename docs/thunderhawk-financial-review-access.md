# Financial review access — candidate

The previous goal turn completed and pushed native Quanta worker invitation, password setup/login, corrected-time presentation, scoped export and cleanup. Current inspection confirmed `d4e711b9` and all eleven CI jobs passed. The full integration remains open.

The next operator walkthrough exposed a concrete access gap: the saved placement/time/invoice/payroll comparison was reachable only within PurePay connection administration, and its read API required bank administration. Staff readers therefore needed authority to change unrelated connections merely to inspect saved evidence.

This change adds **Reports → Placement financial review**. The report requires the underlying accounting, receivables, payables, people, placements, time and payroll read grants. It does not grant connection, payment, journal-posting or payroll-execution rights. Workbook history and original downloads use the existing financial/document read scope; previews and saved/withdrawn workbook reviews retain the existing manager gate. The UI hides alteration controls for readers, rejects stale company contexts and discards an outstanding preview if management permission is removed.

The report remains a comparison of saved evidence. Unknown provider dates/currencies, absent original time references, different work periods and unproven allocations are not filled with inferred facts. Original workbook downloads retain the existing byte-size, company-header and SHA-256 verification. No schema migration is needed.

Local validation passed 23 rendered component checks, 38 reconciliation domain checks and 40 module registry checks. The frontend build and hook lint passed with existing repository warnings. Native cookie-session HTTP coverage now includes anonymous access, missing underlying read grants, a read-only financial reviewer, forbidden connection/evidence mutations, foreign tenant pins and same-session permission changes. Its execution result must come from CI. Installation and the signed-in financial/workbook walkthrough are pending.
