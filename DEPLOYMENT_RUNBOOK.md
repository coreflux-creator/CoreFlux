# CoreFlux Deployment — Click & Run

CoreFlux deploys with **two URLs you visit in your browser**. No SSH for routine work, no commands to memorize.

| URL | When to use |
|---|---|
| **`https://<your-app>/install.php`** | First-time setup. Visit once, paste OpenAI key, click Install. |
| **`https://<your-app>/update.php`** | Every code update after that. Visit, click "Update now". |

Both pages require you to be logged in as a **master admin** (your existing CoreFlux account). They auto-redirect to the login page if you aren't.

---

## 🚀 First-time setup (≈3 min)

1. **Get your code on the server.** On Cloudways, push your repo via "Save to GitHub" from inside Emergent, then on Cloudways do a one-time `git clone` or use Cloudways' built-in Git deploy panel. After that, the **Update** page handles every future deploy without SSH.

2. **Visit `https://<your-app>/install.php`** in your browser.

3. **Log in** with your existing master admin account.

4. **Paste your OpenAI API key.** Get one at <https://platform.openai.com/api-keys>.

5. **Click "Install CoreFlux".**

You'll see a result page with green checkmarks for:
- Database migrations applied
- Encryption key generated and verified
- OpenAI direct call working
- SMTP reachable

That's it. The page also shows your `COREFLUX_DATA_KEY` once — copy it into your password manager as a backup.

---

## 🔁 Routine updates (≈30 sec)

Anytime you want the latest code in production:

1. Push new code to GitHub (`Save to GitHub` from Emergent, or your normal git workflow).
2. Visit `https://<your-app>/update.php`.
3. Click **Update now**.

The page runs:
- `git pull` (fetches the new code)
- Applies any new migrations
- Runs the smoke test

Green checkmarks = you're good. If anything is red, the message tells you what failed.

---

## 🧪 Smoke test on demand

Visit `/install.php` after install — it reports the current health (encryption, OpenAI, SMTP) without re-running anything. Useful 2 minutes of triage before raising a "the AI isn't working" alarm.

---

## 🔐 What's stored where

| File | Created by | Contains | Gitignored? |
|---|---|---|---|
| `core/config.local.php` | `install.php` | `COREFLUX_DATA_KEY`, `OPENAI_API_KEY` | ✅ |
| `core/config.php` | repo | DB + SMTP creds | ❌ (existing project file) |

`config.local.php` only exists on the server. It's regenerated from scratch any time you delete it and revisit `/install.php`.

---

## 🚧 If something goes wrong

| Symptom | Fix |
|---|---|
| `/install.php` redirects to login forever | You're not a master admin in CoreFlux. Log in as one. |
| "could not write … (check folder permissions)" | The web server user can't write `core/config.local.php`. SSH in once and `chmod g+w core/`. |
| `git pull` fails with auth error on `/update.php` | One-time GitHub PAT setup needed on the server. Cloudways docs cover this. |
| OpenAI check fails | The key is wrong, has run out of credits, or the model name in `core/ai_service.php` is unavailable on your account. |
| SMTP check fails | SMTP creds in `core/config.php` are wrong. |

In every case, the page shows the exact error message — copy it to me and I'll diagnose.

---

## ⚙️ Power-user CLI fallback (optional)

If you ever want to run things from SSH:

```bash
php deploy/run_migrations.php          # apply pending migrations
php deploy/run_migrations.php --status # see what's pending
php deploy/post_deploy_smoke.php       # 5-check verifier
```

Both scripts are equivalent to what the web pages do.

---

## 💡 Optional: make routine updates fully automatic

Add a GitHub webhook → Cloudways: every push to `main` calls `/update.php?action=auto` (we'd add a small token). Then deploys happen on push, no clicks. ~15 min to wire when you're ready.
