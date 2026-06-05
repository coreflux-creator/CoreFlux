# Secrets sidecar — deploy & rotation playbook

## What this is

CoreFlux secrets (Resend API key, OpenAI API key, Plaid keys, QBO
OAuth secret, COREFLUX_DATA_KEY) live in **`/app/core/config.secrets.php`**,
which is **gitignored**. The committed file `config.local.php`
`@include`s the sidecar so downstream callers see one merged set of
constants.

## Files

| File | In git? | Holds |
| ---- | ------- | ----- |
| `core/config.local.php`            | ✅ committed | Non-secret config (PLAID_ENV, RESEND_FROM_EMAIL, RESEND_FROM_NAME, QBO_REDIRECT_URI, QBO_ENV, QBO_SCOPES). `@include`s the sidecar. |
| `core/config.secrets.php`          | ❌ gitignored | Real API keys / OAuth secrets / encryption key. |
| `core/config.secrets.example.php`  | ✅ committed | Template with `REPLACE_ME` placeholders. Copy to `config.secrets.php` on new hosts. |

## First-time provisioning (Cloudways)

On the Cloudways app's SSH terminal:

```bash
cd /home/<master>/applications/<app>/public_html/core

# 1) Copy the template into place
cp config.secrets.example.php config.secrets.php

# 2) Edit it — paste in real keys
nano config.secrets.php
#   - COREFLUX_DATA_KEY  (base64 32-byte, generate once and never lose it)
#   - OPENAI_API_KEY     (sk-...)
#   - PLAID_CLIENT_ID + PLAID_SECRET_SANDBOX + PLAID_SECRET_PRODUCTION
#   - RESEND_API_KEY     (re_...)
#   - QBO_CLIENT_ID + QBO_CLIENT_SECRET

# 3) Lock it down — never group/world-readable
chmod 600 config.secrets.php
chown <www-user>:<www-user> config.secrets.php

# 4) Reload PHP-FPM so the constants pick up on the next request
sudo systemctl reload php-fpm   # or via Cloudways UI → Application → Settings → Reset
```

## Rotation (e.g. rotating the Resend key)

```bash
ssh <cloudways-host>
cd /home/<master>/applications/<app>/public_html/core
nano config.secrets.php       # update RESEND_API_KEY=re_<new>
sudo systemctl reload php-fpm
```

**No git commit. No deploy. No env-var UI dance.** The next mail send
uses the new key. Audit row in `mail_outbox` shows the new
`provider_message_id`.

## Verification

After provisioning or rotation, verify from SSH:

```bash
php -r 'require_once "/home/<master>/applications/<app>/public_html/core/config.local.php";
        echo "RESEND_API_KEY: " . (defined("RESEND_API_KEY") ? "OK(".strlen(RESEND_API_KEY).")" : "MISSING") . "\n";'
```

Expected:

```
RESEND_API_KEY: OK(36)
```

**Faster verification via the live endpoint** (master_admin only):

```
GET https://www.corefluxapp.com/api/admin/secrets_health.php
```

Returns one JSON blob covering every integration. Look for:

- `"all_configured": true`
- Each integration block: `"configured": true`, `"loaded_from": "define"`
- `"sidecar_file": { "present": true, "path_hint": "core/config.secrets.php" }`

The endpoint NEVER returns raw secret values — only a 5-char `key_hint`
(first 5 chars + ellipsis) so you can confirm "is the key I just
rotated actually loaded?" without leaking anything exploitable.

If `all_configured` is `false`, the response's `next_steps` field
tells you what's wrong (typically: sidecar file not on the host or
PHP-FPM didn't pick up the reload).

## CI / dev pods

The preview pod (this Emergent sandbox) ships with a working
`config.secrets.php` populated by the secrets-sidecar split commit so
smokes run end-to-end. Production hosts that get only the git checkout
must run the provisioning step above before the first mail send.
