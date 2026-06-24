# Restaurant Vertical Alignment

Restaurant is a future native CoreFlux module. It must be built inside the
shared CoreFlux React/API/module platform, not as a separate Cloudways,
Laravel, JWT, or sidecar application.

## Boundary

Restaurant is a vertical operating layer and consumer-orchestrator over the
shared platform. It may compose restaurant-specific workflows, dashboards,
presets, metrics, and checklists, but it must consume the canonical source
modules for shared business primitives.

Restaurant consumes:

- Core AP for official bills, vendor payments, payment approval, vendor risk,
  evidence bundles, and AP audit trails.
- Core Accounting for the GL, journals, posting, reversals, dimensions,
  reconciliations, period close, consolidation, and accounting integrations.
- Core People for worker identity, profiles, roles, access, PII controls,
  onboarding state, and People Graph authority.
- Core Payroll for payroll profiles, payroll runs, labor-to-payroll readiness,
  submission state, and payroll auditability.
- Core Reporting for report builder datasets, exports, saved reports,
  schedules, and governed analytics.
- Core Documents, Notifications, Audit, Custom Fields, Layouts, Workflow Graph,
  Artifact Graph, and enterprise controls rather than rebuilding local copies.

Restaurant may own restaurant-specific operating records, including:

- Menu items, product catalog overlays, recipes, modifiers, units, and yields.
- Vendor item mappings, price books, purchase guides, par levels, and item
  substitutions.
- POS and PMIX imports, sales mix snapshots, daypart/channel/store operating
  facts, and restaurant-specific metric snapshots.
- Inventory sessions, counts, adjustments, transfers, waste, prep, theoretical
  usage, actual-vs-theoretical variance, COGS analysis, and bar variance.
- Restaurant close checklists, operating exceptions, purchasing
  recommendations, and restaurant-specific report presets.

Restaurant must not own or duplicate:

- Official AP bills, payment approvals, payment execution, or vendor payment
  ledgers.
- Official GL accounts, journal posting, reversals, reconciliations, period
  close, or financial statements.
- Worker/person source records, payroll runs, tax setup, payroll submissions, or
  payroll payment state.
- RBAC, audit logging, custom-field engines, layout engines, export engines,
  report builder engines, approval token systems, Workflow Graph, People Graph,
  or enterprise-control frameworks.

## Required Future Manifest Shape

When `modules/restaurant/manifest.php` is introduced, it must declare:

- A consumer-orchestrator mode.
- Dependencies on `ap`, `accounting`, `people`, `payroll`, and `reports`.
- People Graph consumption for owner, requester, preparer, reviewer, approver,
  escalation contact, AI creator, and AI supervisor roles.
- Workflow Graph usage for material inventory adjustments, purchasing,
  restaurant close, AI recommendations, and any AP/accounting/payroll handoff.
- Audit events for every material import, adjustment, recommendation, workflow
  transition, export, and source-module handoff.
- Export and report datasets only as governed datasets registered through the
  shared export and report-builder platform.
- Custom fields and layouts only through shared custom-field/layout services.

## Enterprise Controls

Restaurant AI and automation may draft, classify, recommend, compare, and route
work. It may not post inventory adjustments, create official AP bills, approve
payments, post journals, submit payroll, execute payments, or alter material
financial state without Workflow Graph, People Graph, source-module RBAC,
separation-of-duties checks, tenant/entity scope, and auditable before/after
state. The resulting source-module action must preserve auditable before/after state
and link back to the Restaurant operating fact that triggered it.

The end-to-end process must remain reconstructable:

1. Restaurant captures or imports the restaurant operating fact.
2. Restaurant links the fact to source modules through governed identifiers.
3. People Graph resolves owner, preparer, reviewer, approver, and escalation
   roles.
4. Workflow Graph enforces state, approvals, separation of duties, and retries.
5. Source modules perform official AP, accounting, payroll, payment, or report
   actions.
6. Audit and Artifact Graph preserve provenance, lineage, evidence, and
   before/after material state.

This makes Restaurant a first-class vertical experience while keeping the core
platform primitives authoritative and reusable.
