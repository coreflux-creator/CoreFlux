# CoreFlux Deployment Runbook — Cloudways

Fresh-deploy and update procedure. Single-stack: PHP + MySQL only. The React SPA is built into static assets; OpenAI is called directly from PHP. **No Python anywhere.**

---

## ⚙️ One-time first-deploy (≈8 min)

### Step 1 — Pull the latest code

SSH into your Cloudways app, then:

```bash
cd ~/applications/<APP_NAME>/public_html
git pull origin main
```

> If `git pull` asks for a password, you need a GitHub Personal Access Token configured. Cloudways has docs for this — or use Emergent's "Save to GitHub" feature plus SFTP if you'd rather skip git.

### Step 2 — Create the host-only config file

```bash
cp core/config.local.example.php core/config.local.php
nano core/config.local.php
```

Fill in two values:

- **`COREFLUX_DATA_KEY`** — 32 random bytes, base64-encoded. Encrypts SSN + bank accounts. Generate with:
  ```bash
  php -r 'echo base64_encode(random_bytes(32)) . PHP_EOL;'
  ```
  Paste the output. **Never change this once data is encrypted with it** — rotating orphans existing ciphertext.
- **`OPENAI_API_KEY`** — your OpenAI key from <https://platform.openai.com/api-keys>.

Save: `Ctrl+O`, `Enter`, `Ctrl+X`.

The file is gitignored so it stays on the server only.

### Step 3 — Run database migrations

```bash
php deploy/run_migrations.php --status      # see what's pending
php deploy/run_migrations.php               # apply pending
```

The runner is idempotent — safe on every deploy. It tracks applied files in `schema_migrations`.

### Step 4 — Run the smoke test

```bash
php deploy/post_deploy_smoke.php
```

Expect 5 ✓ lines:
```
✓ data key present (32 bytes)
✓ OpenAI key configured
✓ DB connected: <your-db>
✓ required tables present (12)
✓ schema_migrations has N applied rows
✓ encryption round-trip with configured key
✓ OpenAI direct call works (model=gpt-5.4-mini, NNNms)
✓ SMTP reachable: smtp.mail.yahoo.com:587

All post-deploy checks passed.
```

If any line is `✗`, the message tells you which dependency to fix. Re-run after fixing.

### Step 5 — Browser smoke test

Log in to your CoreFlux URL and:

1. **People → Directory** — empty.
2. **Add employee** — name + hire date.
3. The detail page shows a **Payroll readiness** banner with gaps (SSN, tax, bank, I-9).
4. Click **Draft setup email** — AI writes a body. Click **Accept**.
5. The email sends. Check the recipient inbox + the `people_emails_sent` table:
   ```bash
   php -r "require 'core/config.php'; \$p=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS); foreach (\$p->query('SELECT id,kind,to_email,status,created_at FROM people_emails_sent ORDER BY id DESC LIMIT 5') as \$r) print_r(\$r);"
   ```

---

## 🔁 Routine updates

```bash
cd ~/applications/<APP_NAME>/public_html
git pull origin main
php deploy/run_migrations.php
php deploy/post_deploy_smoke.php
```

That's it.

---

## 🔑 Secrets map

| Secret | Where to set | Who reads it |
|---|---|---|
| `COREFLUX_DATA_KEY` | `core/config.local.php` | `core/encryption.php` |
| `OPENAI_API_KEY` | `core/config.local.php` | `core/ai_service.php` |
| DB + SMTP | `core/config.php` (already set) | core + mailer |

Never commit `config.local.php`. It's gitignored.

---

## 🚨 Rollback

If `post_deploy_smoke.php` fails after an update:

```bash
git log --oneline -10                            # find last known-good commit
git reset --hard <sha>
```

Migrations are additive; usually safe to leave applied. If a specific migration broke things, reverse it manually and remove its row from `schema_migrations` before re-running.

---

## 💡 Optional hardening (P1, not blocking)

- Cron `post_deploy_smoke.php` hourly; email yourself if any ✓ flips to ✗.
- Move secrets from `config.local.php` to Cloudways environment variables.
- Add CloudFlare in front for caching + WAF.
