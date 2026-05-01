# CoreFlux — AI Touch Points Roadmap

A complete map of where AI / LLM extraction can save user time across the platform. Items are tagged by:

- **Status**: ✅ shipped · 🟡 in scope of next phase · ⏳ backlog
- **Cost**: 💰 (cheap / mini-model) · 💰💰 (vision or larger) · 💰💰💰 (deep reasoning)
- **Trust level**: 🔒 review-mandatory (suggestions only) · ✅ auto-apply (high-confidence + reversible)

Architecture rule: **all AI calls go through `aiAsk()` (narrative) or `aiExtract()` (structured) in `core/ai_service.php`.** Tenant + feature-class gates apply. Audit log retains hash + (optionally) full prompt/response.

---

## 1. AP / Vendor side

| # | Touch point | Status | Cost | Trust |
|---|---|---|---|---|
| 1.1 | **Extract bill from vendor invoice PDF** — vendor name, bill #, dates, PO, line items (description/qty/unit/price), totals | ✅ shipped (this phase) | 💰💰 | 🔒 |
| 1.2 | **Receipt OCR for expense lines** — drop a receipt photo on a line → fill description/qty/unit_price/date/merchant/GL guess | ⏳ | 💰💰 | 🔒 |
| 1.3 | **W-9 / W-8BEN extraction** — drop the form → vendor name, tax ID, address, classification | ⏳ | 💰💰 | 🔒 |
| 1.4 | **GL classification suggestion** — given a line description + vendor history, predict the GL account | ⏳ | 💰 | 🔒 |
| 1.5 | **Anomaly detection on bills** — flag bills that look unusual vs that vendor's prior history (amount, frequency, line-mix) | ⏳ | 💰 | 🔒 |
| 1.6 | **Smart approval routing** — predict the right approver based on vendor + amount + GL + historical patterns | ⏳ | 💰 | ✅ (with override) |
| 1.7 | **Vendor inbox triage / summarisation** — for emails forwarded to AP inbox, classify (bill / statement / spam / question) and summarise | 🟡 (Phase B Slice 2c on roadmap) | 💰 | 🔒 |
| 1.8 | **AI-drafted reply for AP/AR collections** — draft a polite payment-status reply | ⏳ | 💰 | 🔒 |
| 1.9 | **Inline vendor name normalisation** — semantic match against existing companies before creating duplicate | ⏳ | 💰 | 🔒 |

## 2. Billing side (mirrors AP)

| # | Touch point | Status | Cost | Trust |
|---|---|---|---|---|
| 2.1 | **Generate invoice from time bundle** — already deterministic; AI draft of cover-letter narrative | ⏳ | 💰 | 🔒 |
| 2.2 | **Smart memo / line description** — AI suggests prettier client-facing text from internal notes | ⏳ | 💰 | 🔒 |
| 2.3 | **Invoice PDF rendering review** — AI verifies all required fields present + correct before sending | ⏳ | 💰 | ✅ (warning only) |
| 2.4 | **Past-due reminder drafting** — tone-aware drip series 30/60/90 days | ⏳ | 💰 | 🔒 |
| 2.5 | **Client write-off reasoning** — AI summarises the dispute thread when proposing a credit memo | ⏳ | 💰💰 | 🔒 |

## 3. People / Recruiting

| # | Touch point | Status | Cost | Trust |
|---|---|---|---|---|
| 3.1 | **Resume parsing on Person create** — drop a resume → fill name, email, phone, work auth, skills, employment history, location | ⏳ | 💰💰 | 🔒 |
| 3.2 | **Driver's license / passport extraction (I-9 work auth)** — drop the doc → expiry, name, doc type, classification | ⏳ | 💰💰 | 🔒 |
| 3.3 | **Job description → candidate match** — paste a JD → ranked list of candidates with reasoning | ⏳ | 💰💰💰 | 🔒 |
| 3.4 | **Skills extraction** — auto-tag skills from resume + custom field defs | ⏳ | 💰 | 🔒 |
| 3.5 | **Recruiter-note summarisation** — TL;DR of a long thread of recruiter notes | ⏳ | 💰 | 🔒 |
| 3.6 | **Candidate pipeline next-step suggestions** — given stage + last touch → recommend follow-up | ⏳ | 💰 | 🔒 |
| 3.7 | **Outbound message drafting** — first-touch / pitch / interview-confirm templates personalised to candidate | ⏳ | 💰 | 🔒 |

## 4. Placements

| # | Touch point | Status | Cost | Trust |
|---|---|---|---|---|
| 4.1 | **Contract clause extraction** — drop a placement contract / SOW / MSA → extract NTE, end date, rate cap, key terms | ⏳ | 💰💰💰 | 🔒 |
| 4.2 | **VMS portal screenshot → chain auto-fill** — extract submittal #, vendor portal name, fee % from a portal screenshot | ⏳ | 💰💰 | 🔒 |
| 4.3 | **Rate-card normalisation** — convert vendor rate card emails into structured rates per role | ⏳ | 💰💰 | 🔒 |
| 4.4 | **End-of-engagement narrative** — from time logs + chat, generate placement closure note | ⏳ | 💰💰 | 🔒 |

## 5. Time

| # | Touch point | Status | Cost | Trust |
|---|---|---|---|---|
| 5.1 | **AI parsing pipeline (`time_intake_events`)** — emails / Slack messages with hours → structured timecard rows | 🟡 (Phase B Slice 2b) | 💰 | 🔒 |
| 5.2 | **Inbox triage UI** — confidence-graded queue of AI-parsed time entries | 🟡 (Phase B Slice 2c) | 💰 | 🔒 |
| 5.3 | **Gmail / M365 driver** — pull from email → auto-feed timecard inbox | 🟡 (Phase B Slice 2d / 3) | 💰 | 🔒 |
| 5.4 | **Anomaly flag** — placement reports 80h on a 40h client → AI flags before approval | ⏳ | 💰 | 🔒 |
| 5.5 | **Receipt → expense → time merge** — link receipts to time periods automatically | ⏳ | 💰💰 | 🔒 |

## 6. Accounting (Phase 1+)

| # | Touch point | Status | Cost | Trust |
|---|---|---|---|---|
| 6.1 | **Bank statement OCR** — drop CSV/PDF/QFX → match against accounting_accounts.cash 1000 | 🟡 (Phase 1) | 💰💰 | 🔒 |
| 6.2 | **Reconciliation suggestion** — propose JE matches for unmatched bank lines | 🟡 (Phase 1) | 💰💰 | 🔒 |
| 6.3 | **Period close commentary** — natural-language exec summary of P&L / Balance Sheet | ⏳ | 💰💰 | 🔒 |
| 6.4 | **Variance analysis** — explain month-over-month or budget-vs-actual differences | ⏳ | 💰💰💰 | 🔒 |
| 6.5 | **Audit trail Q&A** — "Why did we pay $X to vendor Y on date Z?" → cite JE + bill + audit log | ⏳ | 💰💰💰 | 🔒 |

## 7. Cross-cutting

| # | Touch point | Status | Cost | Trust |
|---|---|---|---|---|
| 7.1 | **Universal "ask the data"** — natural-language questions over the user's own tenant | ⏳ | 💰💰💰 | 🔒 |
| 7.2 | **Dashboard narrative** — TL;DR for any list view | ⏳ | 💰 | ✅ (advisory) |
| 7.3 | **Custom field admin assistant** — "Add a field for visa expiry" → suggest field_type + options | ⏳ | 💰 | 🔒 |
| 7.4 | **Permissions explainer** — "Why can't user X see this?" → traces RBAC | ⏳ | 💰 | ✅ |
| 7.5 | **Audit log search** — natural-language filter over audit events | ⏳ | 💰 | ✅ |
| 7.6 | **Inline tooltips / docs** — context-aware help text generated from current screen state | ⏳ | 💰 | ✅ |

---

## Implementation pattern (canonical)

For any new "extract from document" touch point, follow the BillCreate model:

1. Backend endpoint `?action=extract_from_<thing>` → calls `aiExtract()` with a JSON schema hint.
2. Backend returns `{draft, model, latency_ms, interaction_id, review_required: true}`.
3. Frontend uploads the doc to S3 first (presigned POST), then posts the storage_key.
4. Frontend merges only **non-empty** fields, **never overwrites identity selections** (vendor, client, person), **never auto-picks GL accounts** (the system of record is the user's choice).
5. UI shows model name + line-count confirmation banner so users know the source.
6. Audit row written via `aiAuditWrite()`; manifest declares the `<module>.<entity>.extracted_from_<thing>` event.
