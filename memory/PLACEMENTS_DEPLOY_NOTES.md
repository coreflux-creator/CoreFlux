# Placements Module — Cloudways Deploy Notes (Phase A)

Built fresh against `/app/modules/placements/SPEC.md` Phase A scope (§13 MVP cut list). Legacy folder was empty (only SPEC + manifest existed) so nothing to refactor; backup at `/app/legacy/placements_pre_spec_<date>/`.

## What's in this drop

- 9 new tables (idempotent, `utf8mb4_unicode_ci`, additive)
- 10 SPEC-aligned API endpoints
- 7 React components (List, Expiring, Reports, PlacementCreate, PlacementDetail with 9 tabs, CsvImport)
- CSV import via `Core\CsvImportService` primitive (people lookup by email)
- Margin formula deterministic per SPEC §4 (additive vendor-fee stacking)
- Rate draft → approve flow with snapshot lock and audit
- Encrypted EIN for C2C corp details
- 96 contract-level smoke tests passing locally

## Deploy steps

### 1. Push from preview to GitHub
"Save to GitHub" button.

### 2. Run the migration on Cloudways
Click `/update.php` → "Update now" (your runner picks up `modules/placements/migrations/001_init.sql`).

If the runner doesn't auto-detect, run manually:
```bash
mysql -u <user> -p <db> < /path/to/coreflux/modules/placements/migrations/001_init.sql
```

Verify:
```sql
SHOW TABLES LIKE 'placement%';
SHOW TABLES LIKE 'tenant_vendor_portals';
SHOW TABLES LIKE 'tenant_end_clients';
```
Should return 9 tables.

### 3. Hard-refresh browser
The new bundle (`index-CbxU_clN.js`) replaces the previous one — clear cache to load it.

### 4. Smoke test (in this order)

| # | Where | What to check |
|---|---|---|
| 1 | Sidebar → Placements → Active Placements | Empty list, styled |
| 2 | "+ New Placement" → search Person → fill title/start date → Create | Redirects to detail; status=draft |
| 3 | Detail → Chain tab → add `Acme Inc` (end_client, position 0), then `Beeline` (msp, position 1, fee 0.02) | Both rows appear |
| 4 | Rates tab → enter effective_from, bill 100, pay 60 → Draft new rate | Row shows status=draft |
| 5 | Click **Approve** on the draft rate → confirm not a correction | Becomes approved; adjusted_bill_rate=98.00, net_to_vendor=38.00 |
| 6 | Margin tab → see $98 / $38 / 38% margin | Numbers match formula |
| 7 | Try to Approve again | Server returns 409 (already approved — snapshot locked) |
| 8 | Add another draft rate, this time backdated as a correction → Approve with reason | Old row's effective_to is auto-closed |
| 9 | Commissions tab → add recruiter @ 30% / net_margin | Row appears |
| 10 | Referrals tab → add vendor "X Recruiting" pct_bill 10% | Row appears |
| 11 | Approval tab → fill name + email, leave tokenized OFF (default) | Saves OK |
| 12 | Sidebar → Expiring Soon | Empty (or shows your placement if you set due_date < 30 days) |
| 13 | Sidebar → Reports | Two report tables |
| 14 | Sidebar → Active Placements → "Import CSV" → Download template, fill 2 rows with valid `person_email`, upload, dry-run, commit | 2 placements created (status=draft) |
| 15 | Audit log — check `audit_log` table for `placement.created`, `placement.rate.approved`, `placement.rate.superseded` | Rows present |

### Common errors

- **"placement_id required" / 400** — endpoint expects `?placement_id=N` query param.
- **"Database table 'placements' does not exist"** — migration didn't run. Re-run.
- **"person_id not found in this tenant"** when creating — Person you picked got soft-deleted, or wrong tenant context. Re-search.
- **CSV import: "person_email not found in this tenant's People"** — the email doesn't match any active person. Add the person first, or fix the email.
- **Rate approve: "Already approved (snapshot is locked; create a correction)"** — by design. Draft a new rate row marked `is_correction=true` with a reason.

## Rollback

Drops the 9 new tables only. Other modules untouched:
```sql
DROP TABLE IF EXISTS
  placement_documents,
  placement_corp_details,
  placement_referrals,
  placement_commissions,
  placement_rates,
  placement_client_chain,
  placements,
  tenant_end_clients,
  tenant_vendor_portals;
```

## What's NOT in Phase A (deferred)

- Custom fields per SPEC §3.9 (Phase B)
- Commission plans / templates per SPEC §3.4 (Phase B; per-placement inline only for now)
- Margin/commissions reports beyond list views (Phase B)
- AI rate-sheet OCR / anomaly detection (Phase C)
- Full multi-tier CSV import (only end-client tier auto-created on import)
- Document upload UI (endpoints exist; UI ships next pass — same as People)
- Time tab (read-only; will populate from `time/` when that module ships)

## Files of reference

- `/app/modules/placements/SPEC.md` — locked spec
- `/app/modules/placements/manifest.php` — RBAC + audit + actions
- `/app/modules/placements/migrations/001_init.sql` — 9-table schema
- `/app/modules/placements/api/*.php` — 10 endpoints
- `/app/modules/placements/lib/placements.php` — cross-module read interface
- `/app/modules/placements/ui/*.jsx` — 7 React components
- `/app/tests/placements_spec_smoke.php` — 96 contract assertions
