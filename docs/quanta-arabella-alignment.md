# Arabella / Quanta alignment

Observed in the signed-in workspaces on 2026-09-28. This is a rollout checklist,
not an instruction to sync or alter production data automatically.

| Concept | Source / owner | CoreFlux handling |
| --- | --- | --- |
| Person | CoreFlux shared people directory | Canonical P-ID; Quanta worker and Connecteam user are separate reviewed source identities. |
| Assignment | CoreFlux placement | Effective-dated worker + worksite + Quanta dimension route; no assignment inferred from a client label. |
| Worksite | Quanta | Source context, including timezone. It is not automatically a CoreFlux client or legal entity. |
| Client/project/job dimension | Quanta / Connecteam source labels | Retain source IDs and route explicitly to placement economics; do not match by display name alone. |
| Clock and submitted hours | Quanta or Connecteam, one chosen path per source activity | Import to CoreFlux pending review with source ID and canonical weekly artifact. |
| Rate, billability, invoice, payables | CoreFlux | Apply only after the worker and placement graph is verified. |

Current readiness: Arabella CoreFlux has seven active placements. Its people
directory is shared with the Seven Generations parent workspace, so a P-ID can
be valid in Arabella without the people row having Arabella's tenant ID. The
CoreFlux Connecteam connection reports a disabled API key; the existing
capability sample is historical, not a live feed. Arabella's Quanta account has
no API key, worksite, time entry, or dimension configured yet and only the admin
user is active. Quanta's Connecteam integration is also disabled. None of
those gaps should be hidden by guessed mappings or sample time.

To activate time safely: restore and test the intended Connecteam access if it
will feed Quanta; populate Quanta workers, worksites, and dimensions; decide
which system owns each time clock; connect the Arabella Quanta key to Arabella
CoreFlux; review worker-to-person links and dated placement routes; inspect
real entry payloads in preview; then import selected entries and reconcile the
resulting timesheet artifacts. Leave Quanta-to-CoreFlux approval writes off
until write scopes and conflict handling are verified.
