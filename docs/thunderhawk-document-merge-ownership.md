# Document ownership through merges

Candidate work on November 12, 2026, after the standalone-accounting access reply. That reply made no integration progress. The prior implementation was revalidated: `5fd51079` completed all eleven CI jobs in run `43233586038`; installed runtime remains `24e1cf2b` with the document-recovery manifest's 255 pins. This candidate has not been installed.

Person merges previously reassigned document rows without reconciling storage ownership. A download then failed the exact-person storage check. The JobDiva configuration consolidation helper contained the same raw placement-document reassignment, although the outer repair normally keeps the placement with documents and refuses competing downstream activity.

Migration 194 adds per-document transfer receipts. An authorized merge retains the original document ID, object registry, storage key, recorded physical metadata, bytes and effective dates. It records the source owner, survivor, operating company, directory, operator and ordered predecessor fingerprint. Downloads require the exact document binding and complete transfer chain; an unrelated record, tenant, changed object or inconsistent history cannot acquire access. These fingerprints detect inconsistency; they are not digital signatures or protection against a privileged database administrator rewriting history.

The writer rechecks current directory ownership and both active records under transaction locks. It transfers retired document history without reviving access. A retry moves no already-transferred document, and a failure rolls back the full enclosing merge. The native person route also uses its freshly resolved directory for writes, prevents provider-ID substitution through field resolution, and commits its audit with the merge. The existing employment-link guard remains.

Onboarding can review a file on its surviving person while retaining the original physical file. A transferred file gets a new evidence revision; a merge does not carry over an employer approval. Existing evidence revisions for unmerged documents retain their format.

The fifteen receipt fields are included in the ownership inventory as internal Core evidence with no provider replication. No connector is enabled and no real record, provider identity, financial posting, payroll or payment is changed by this candidate.

Validation must cover the actual database, physical-file identity and native authenticated merge/download routes, including shared directories, chained merges, retry, retirement, altered history, cross-tenant refusal and rollback on a later receipt or final audit failure. Installation remains gated on successful candidate CI and an exact pilot preflight.

This work does not establish complete person/placement merge behavior. Remaining acceptance includes placement and provider relationships, custom-value collisions, prior onboarding cases, and employment identity reconciliation. JobDiva's existing downstream-activity merge restrictions have not been relaxed. The wider integration completion checklist remains open.
