# CoreFlux Deployment Runbook — Cloudways

Fresh-deploy and update procedure for Cloudways. Every step is a single copy-paste command.

---

## ⚙️ One-time first-deploy (≈10 min)

### Step 1 — Pull the latest code

SSH into your Cloudways app, then:

```bash
cd ~/applications/<your-app>/public_html
git pull origin main
```

### Step 2 — Create the local config

```bash
cp core/config.local.example.php core/config.local.php
nano core/config.local.php          # (or your editor of choice)
```

Fill in:

- **`COREFLUX_DATA_KEY`** — a freshly generated value you'll keep forever. Generate on any host:
  ```bash
  php -r 'echo base64_encode(random_bytes(32));'
  ```
  Paste the output in. **Do not change this value once anything is encrypted with it** — rotating orphans all existing ciphertext.
- **`AI_SIDECAR_URL`** — where PHP can reach the Python AI sidecar (see Step 4).
- **`AI_SIDECAR_SECRET`** — the same value as `AI_SIDECAR_SECRET` in `/app/backend/.env`. Copy the value that's already in that file.

> The file is gitignored (`config.local.php`). It never gets committed.

### Step 3 — Run database migrations

```bash
php deploy/run_migrations.php --status      # see what's pending
php deploy/run_migrations.php               # apply everything pending
```

The runner is idempotent — safe to run on every deploy. It tracks applied migrations in the `schema_migrations` table.

### Step 4 — Stand up the Python AI sidecar

The sidecar is `/app/backend/server.py` (FastAPI). It needs Python 3.11+ and the dependencies in `backend/requirements.txt`.

**Cloudways options (pick one):**

**(a) Same VM, separate port** — simplest. Cloudways supports custom daemons via their SSH terminal:
```bash
cd ~/applications/<your-app>/private_html   # or a directory outside public_html
cp -r <repo>/backend ai-sidecar
cd ai-sidecar
python3 -m venv .venv && . .venv/bin/activate
pip install -r requirements.txt
cat > .env <<EOF
OPENAI_API_KEY=sk-proj-...                  # your key
AI_SIDECAR_SECRET=<same-secret-you-put-in-config.local.php>
AI_MODEL_SUMMARY=gpt-5.4-mini
AI_MODEL_NARRATIVE=gpt-5.4
AI_MODEL_DRAFT=gpt-5.4
AI_MODEL_CLASSIFICATION=gpt-5.4-mini
AI_MODEL_DEEP_REASONING=gpt-5.4-thinking
AI_FALLBACK_MODEL=gpt-5.2
EOF
# Run it with supervisord, systemd --user, or a simple nohup:
nohup .venv/bin/uvicorn server:app --host 127.0.0.1 --port 8001 > sidecar.log 2>&1 &
```
Set `AI_SIDECAR_URL` in `config.local.php` to `http://127.0.0.1:8001/api/ai/chat`.

**(b) Separate tiny service** — Fly.io / Railway / Render. Cheapest plan is fine for light traffic. Point `AI_SIDECAR_URL` at the public HTTPS URL. Make sure only your PHP host can reach it (Cloudflare IP allowlist, or keep the `X-AI-Secret` header as the sole auth).

### Step 5 — Verify everything is wired

```bash
php deploy/post_deploy_smoke.php
```

This runs 5 checks:
1. `COREFLUX_DATA_KEY` is set + valid base64(32 bytes)
2. DB is reachable + every expected table exists + migrations are current
3. Encryption round-trip works with the configured key
4. AI sidecar responds healthy + answers an auth-checked chat call
5. SMTP connect succeeds (does NOT send an email)

Expect:
```
✓ data key present (32 bytes)
✓ DB connected: <your-db>
✓ required tables present (12)
✓ schema_migrations has N applied rows
✓ encryption round-trip with configured key
✓ AI sidecar healthy at http://127.0.0.1:8001/api/ai/health
✓ AI sidecar auth + live model roundtrip
✓ SMTP reachable: smtp.mail.yahoo.com:587

All post-deploy checks passed.
```

### Step 6 — UI smoke test

Log in → navigate to **People → Directory**:

1. Click **Add employee**, create a test record (first name, last name, hire date)
2. Open the record — the **Payroll readiness** banner should list gaps (SSN, tax, bank, I-9)
3. Click **Draft setup email** — AI writes a body, `AISuggestion` shows it with Edit/Accept/Reject
4. Click **Accept** — the email sends; the banner shows "Email sent to …"
5. Check the recipient inbox + `people_emails_sent` table for the audit row

---

## 🔁 Routine updates (every deploy after that)

```bash
cd ~/applications/<your-app>/public_html
git pull origin main
php deploy/run_migrations.php                    # applies any new .sql files
# (restart the sidecar only if backend/requirements.txt or server.py changed)
php deploy/post_deploy_smoke.php                 # confirm nothing regressed
```

---

## 🔑 Secrets map

| Secret | Where to set | Who reads it |
|---|---|---|
| `COREFLUX_DATA_KEY` | `core/config.local.php` | `core/encryption.php` (PHP) |
| `AI_SIDECAR_URL` | `core/config.local.php` | `core/ai_service.php` (PHP) |
| `AI_SIDECAR_SECRET` | `core/config.local.php` **and** `backend/.env` | both ends of the PHP↔Python call |
| `OPENAI_API_KEY` | `backend/.env` (sidecar host only) | `backend/server.py` |
| DB + SMTP | `core/config.php` (already set) | core + mailer |

Never commit `config.local.php`, `backend/.env`, or any file containing the above values. Both are in `.gitignore`.

---

## 🚨 Rollback

If `post_deploy_smoke.php` fails after an update:

```bash
git log --oneline -10                            # find the last known-good commit
git reset --hard <sha>                           # reset code
php deploy/run_migrations.php --status           # migrations are additive; usually safe to leave
```

If a migration caused the failure, reverse it manually against the DB and delete its row from `schema_migrations` before re-running.

---

## 💡 Recommended next hardening (P1, not blocking)

- Switch `config.local.php` secrets to Cloudways env vars (reduces blast radius if the repo is exposed).
- Put the AI sidecar behind nginx with TLS + IP allowlist.
- Add a cron entry that emails if `post_deploy_smoke.php` ever fails.
