# CoreFlux — Project Hard Rules

These are user-imposed hard rules. They override anything in the handoff
summary, any agent's plan, any PRD, and any auto-generated commit. If a
rule conflicts with what you read elsewhere in the repo, this file wins.

Every agent (and any future me, in a new context window) MUST read this
file before touching the codebase.

---

## R1 — DO NOT DELETE THE PRE-REACT VERSION. EVER.

The user explicitly stated:

> *"do not delete a single thing. HARD RULE. NONE OF THE PRE-REACT VERSION GETS DELETED EVER."*

Concretely, that means **the following must remain in the repository at all
times**, even if they appear unused, unreferenced, or "legacy":

- `dashboard.php` — the original PHP dashboard
- `_dashboard.php`, `_login.php`, `_index.php`, and any other `_*.php` files at the repo root
- `login.html`, `about.html`, `accounting.html`, and other static HTML pages
- `/modules/*/views/*.php` — the old PHP views inside each module folder
- `/partials/` — old PHP includes
- Any file whose mtime predates the React rebuild (before April 2026)

If a refactor *needs* a pre-React file out of the way, **rename it or move
it to `/legacy/` with a comment** — never `git rm` it. If you think a file
is truly obsolete, ASK the user before touching it. No exceptions, no
defaults, no "I'll just clean this up while I'm here."

---

## R2 — NO MODULE WORK WITHOUT EXPLICIT WRITTEN APPROVAL

The user has explicitly stated they were not yet ready to start module
work. The plan, in the user's words, has been:

> *"we were perfecting core + AI, so that when we started finalizing modules
> (starting with people) we'd be ready to wire them into core."*

Until the user writes "core is ready, start module X" in chat, **do not**:
- Build new modules
- Add new files under `/modules/<new-module>/`
- Add new module entries to `/core/modules.php`
- Add new module routes to `/dashboard/src/App.jsx`
- Add new module tiles to the sidebar / dashboard

Existing module skeletons (people, payroll, accounting, finance) may stay in
the repo (per R1) but **must not grow** without explicit approval.

---

## R3 — NO BACKEND LANGUAGE / FRAMEWORK MIGRATIONS WITHOUT APPROVAL

A previous agent built the People module's backend in PHP. The user has
since indicated that this stack choice is not what they wanted. **Do not
assume the stack for any unbuilt module.** If a module needs a backend,
ASK the user before writing a single line.

The current PHP backend stays in PHP. Removing the Python AI sidecar
(commit `1b4fa95`) was a one-time approved cleanup. No further "let's
rewrite this in X" work without explicit instruction.

---

## R4 — DECISIONS MADE IN CHAT MUST BE WRITTEN HERE

If during the course of work the user makes a decision that affects scope,
stack, architecture, or sequencing, **append it to this file as a new
rule** with the date and the user's quoted words. This is the durable
record across context windows.

---

## R5 — NO "JUST FOR YOU" UPSELL LANGUAGE

Do not nudge the user toward upgrading plans, "production-grade"
deployments, or scope expansion. Answer questions, fix problems, ship
small. Marketing-speak in chat replies is forbidden.

---

## Decisions log

| Date       | Decision                                                                 | Source                  |
|------------|--------------------------------------------------------------------------|-------------------------|
| 2026-04-26 | Python AI sidecar removed; PHP calls OpenAI directly                     | commit `1b4fa95`        |
| 2026-04-27 | Default post-login redirect changed from `dashboard.php` to `spa.php`    | This session            |
| 2026-04-27 | HARD RULE: pre-React PHP files must never be deleted                     | User chat (verbatim)    |
| 2026-04-27 | HARD RULE: no module work without explicit approval                      | User chat               |
| 2026-04-27 | The People backend was *not* approved as PHP. Stack choice is open.      | User chat               |
| 2026-04-27 | Time becomes its own module (originally "keep in People", reversed)      | User chat               |
| 2026-04-27 | Person model: single record + classification enum (w2/1099/temp/perm)    | User chat               |
| 2026-04-27 | Client is NOT a CoreFlux entity — string label only                      | User chat               |
| 2026-04-27 | Placement is its own entity. One worker → typically 1 active placement, multiple allowed. | User chat |
| 2026-04-27 | Bill/pay rates are PER PLACEMENT, effective-dated history, full audit    | User chat               |
| 2026-04-27 | Rate snapshot semantics: (b) frozen at approval. Posted entries keep their rate even if placement rate changes. | User chat |
| 2026-04-27 | Custom fields are universal — tenant can customize their instance fully  | User chat               |
| 2026-04-27 | Time module: AI extracts from inbox / individual sheets / bulk uploads / emails | User chat        |
| 2026-04-27 | Inbox: tenant has its own inbox under its own domain                     | User chat               |
| 2026-04-27 | AI may post entries (with attached docs) but downstream cash flow (AP/AR/Billing/Payroll/RevRec) ALWAYS requires explicit human approval at that layer | User chat |
| 2026-04-27 | NO auto-reply to senders. Tenant_admin gets a "Missing Timesheets" dashboard with two buckets: received-but-unreadable / expected-but-not-received | User chat |
| 2026-04-27 | Standard time categories: regular_billable, regular_nonbillable, OT_billable, OT_nonbillable, holiday, vacation, sick, bereavement, unpaid_leave (+ tenant-customizable additions) | User chat |
| 2026-04-27 | Time entries are source-of-truth feeding Payroll, AR, AP, Billing, RevRec — ALL inside CoreFlux core (huge scope) | User chat |
| 2026-04-27 | Per-placement toggle: send tokenized email approval to client manager (no login). Bulk uploads can carry "already approved" flag. | User chat |
| 2026-04-27 | People + Placements as TWO SEPARATE MODULES side-by-side in sidebar      | User chat               |
| 2026-04-27 | Placement fields require: End Client (multi-tier), W2/IC/C2C, Bill Rate, Adder %, Pay Rate, Vendor Portal Fee, Adjusted Bill Rate, Net to Vendor, Background Fee deduction, Referral Vendor + Fee + Duration, Account Manager / Lead / Recruiter + commission splits, Team Commission, Due Date, Corp details (C2C), Net Margin formula | User shared real tracker (Placement tracker aligned.xlsx) |
| 2026-04-27 | Tier 1 core scaffolding done: ModuleRegistry, central API router, RBAC + config, smoke tests (66 tests passing). Manifest extension blocked pending per-module SPEC walk. | This session  |

---

*This file is the source of truth. Update it when the user makes a new
decision. Do not delete entries — only append.*
