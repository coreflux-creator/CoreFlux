# Placement closure and source ownership

Verified in isolated Core staging on November 11, 2026. The three runtime files
from `70910ce79cd3a9b5af30a02dfbb199a39002e13b` were installed after all eleven
jobs in CI run `43106600958` passed. Production and provider runtimes were not
changed. The full integration goal remains open.

Ending a JobDiva-sourced placement in Core now retains existing ownership flags
and claims its status and actual end date. Normal JobDiva refresh and automatic
contract reconciliation preserve those two fields. The canonical writer and
configurable placement mapping writes lock the placement while checking its
ownership, so they serialize with native ending. Mappings still update
source-owned fields in a mixed record and return reasons for skipped Core
fields. A mapping cannot change the ownership flags themselves; malformed
ownership is refused rather than interpreted as an empty list.

The disposable database regression reproduced the old main-writer and mapped
field failures, then passed 17 ownership checks, 13 existing placement-ending
checks and 17 request checks with the corrected runtime. Its private containers,
network and volume were removed, preserving all 206 pre-existing containers.
The first earlier candidate failed the object-shaped JSON check; that failed
receipt remains alongside the corrected candidates. CI also exposed a smoke
assertion tied to the old decoder syntax; it was updated to require strict
JSON-list validation. The real database tests exercise the behavior.

Installation verified exact prior file hashes, file ownership, 26 unchanged
dependencies and private configuration. It preserved all 368 tables and 34,356
rows and removed its temporary web-cache probe. The installed native functions
then passed the same 17 ownership checks against synthetic records inside an
outer rollback transaction. Independent before/after fingerprints verified all
34,356 original rows and the removal of the test rows. Auto-increment sequence
gaps from rolled-back inserts are allowed. This was a deployed-function test,
not new browser or provider acceptance.

Evidence and fingerprints are recorded in
`deploy/thunderhawk-placement-ownership-20261111.json`. The explicit release test
removes both ownership flags directly inside the fixture; native ownership
release, bulk editing and partial status/date release still need their own
concurrency review. The separate JobDiva terminal-census update path also needs
to respect a Core-owned active status and dates. Other force-source contract
fields, complete field/custom-field alignment, portal closure and document
lifecycle, and financial reconciliation remain separate requirements.

No real payroll, payments, financial postings or Arabella changes occurred.
