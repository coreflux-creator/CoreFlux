# CoreFlux Backup Manifest

## 2026-02-13 — Pre-Live-Books-Rails snapshot

Created immediately before beginning the **Unified Operational Financial Graph** / **Live Books Rails** architectural overhaul described in `/app/memory/NORTH_STAR.md`.

### Snapshot
- **File:** `pre_live_books_rails_20260513_130735.tar.gz`
- **Size:** 468 MB
- **Files:** 2096
- **Git HEAD at snapshot:** `d272ba6  Auto-generated changes`
- **Excluded:** `.git/`, `dashboard/node_modules/`, `dashboard/dist/`, `spa-assets/`, `vendor/`, `mobile/node_modules/`, `memory/backups/` (self).
- **⚠️ DELETED from this repo on 2026-02-13:** The 468 MB tarball blocked the GitHub push (exceeds GitHub's 100 MB per-file ceiling). The file no longer exists on disk. The git commit hash `d272ba6` IS the canonical pre-Live-Books-Rails restore point — use Emergent's in-platform rollback to that commit if you need to undo.
- `.gitignore` now blacklists `memory/backups/*` (except this manifest) and `*.tar.gz` globally so this can't recur.

### Why this snapshot exists
The next several weeks of work refactor every module to emit canonical events and push all accounting consequences through the new event registry + AI interpretation rails. This is invasive — backwards-compat is preserved in code, but a physical snapshot of the working monolith pre-refactor lets us restore from absolute zero if needed.

### How to restore
**Use Emergent's in-platform rollback to commit `d272ba6`** — that's the canonical pre-Live-Books-Rails restore point and the only one that still exists.

The 468 MB tarball that previously lived in this folder was deleted on 2026-02-13 because GitHub rejected the push (exceeds the 100 MB per-file ceiling). If you need a forensic dump in the future, write the tarball to `/tmp/` or external storage — NOT to a git-tracked path.

### NOT in this snapshot
- Live MySQL data on `corefluxapp.com` (use the prod DB's own backup tooling)
- Plaid/Gusto/JobDiva tokens (those live in tenant-scoped DB tables, encrypted)
- Emergent platform commit history (use the platform's built-in rollback for that)

### Test suite status at snapshot
- **163/163 in-scope smoke tests pass.**
- Expected fails (need live API keys): `ai_platform_smoke.php`, `plaid_integration_smoke.php`.
- New tests added this session: `staffing_worker_mix_smoke.php` (24), `staffing_email_approval_smoke.php` (50), `bugfix_staffing_approvals_list_ambiguous_tenant_id_smoke.php` (8).

### What was just shipped before this snapshot
- W2-vs-1099 Worker Classification Mix dashboard (Staffing → Profitability tab 6).
- One-tap external approver email flow for staffing timesheets.
- Bugfix: ambiguous `tenant_id` in `?action=list` approvals query.
- Vite bundle hash: `index-D38hBIYY.js`.
