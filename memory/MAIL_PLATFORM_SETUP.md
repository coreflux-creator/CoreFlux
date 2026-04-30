# Platform Mail Setup — one-time DNS + Resend config (Model B)

This is a platform-operator setup doc (you), **not** a per-tenant doc. Do
this once on your Resend account + your `corefluxapp.com` DNS, and every
tenant automatically gets clean Model B email delivery (shared From from
your domain, their Reply-To from wherever they choose, no "via resend.com"
tag, SPF + DKIM both aligned).

Tenants never see Resend, never touch DNS, never see any of the records
below.

---

## Prerequisites

- [x] Resend account (https://resend.com). Free tier = 3,000 emails/month;
      upgrade once volume ramps.
- [x] Access to the DNS control panel for `corefluxapp.com` (Cloudways,
      Cloudflare, Namecheap, or wherever your domain's nameservers are).
- [x] SSH access to Cloudways to set env vars.

## Step 1 — Get your Resend API key

1. https://resend.com → sign in → **API Keys** → **Create API Key**
2. Name: `coreflux-production` (you only need one for the whole platform).
3. Permission: **Full access** (or "Sending access" — you don't need the
   domain-create or webhooks permissions for Model B).
4. Copy the key — starts with `re_`. **Save it somewhere safe; Resend
   only shows it once.**

## Step 2 — Verify `corefluxapp.com` as a sending domain in Resend

1. Resend dashboard → **Domains** → **Add Domain** → enter
   `corefluxapp.com` → **Add**.
2. Resend shows 4 DNS records:

   | Type  | Name                               | Value (example — use the ones Resend shows you) |
   |-------|------------------------------------|--------------------------------------------------|
   | TXT   | `send` (or `@` if that's the root) | `v=spf1 include:amazonses.com ~all`              |
   | CNAME | `resend._domainkey`                | `resend._domainkey.resend.com.`                  |
   | CNAME | `resend2._domainkey`               | `resend2._domainkey.resend.com.`                 |
   | CNAME | `resend3._domainkey`               | `resend3._domainkey.resend.com.`                 |

3. Paste them into `corefluxapp.com`'s DNS. DNS TTL: 1 hour is fine.
4. Back in Resend → click **Verify DNS records**. Propagation can take
   5-60 minutes; keep re-clicking until all 4 rows go green.

## Step 3 — Add a custom return path (kills "via resend.com")

Without this, Gmail shows a small "via resend.com" tag next to your
sender name. One CNAME fixes it for your entire platform.

1. Resend dashboard → your verified `corefluxapp.com` domain →
   **Custom return path** (sometimes called "Return-Path alignment" or
   "Bounce domain").
2. Resend gives you something like:

   | Type  | Name                   | Value                      |
   |-------|------------------------|----------------------------|
   | CNAME | `bounces`              | `feedback-smtp.resend.com.` |

3. Paste into `corefluxapp.com` DNS → verify in Resend.
4. After verification, all emails we send now use `Return-Path:
   bounces.corefluxapp.com` — SPF and DKIM both align with
   `corefluxapp.com` and the "via" tag disappears from Gmail.

## Step 4 — (Optional) Publish DMARC for bonus deliverability

Not required, but heavily recommended. One TXT record on your root domain:

| Type | Name     | Value                                                                |
|------|----------|----------------------------------------------------------------------|
| TXT  | `_dmarc` | `v=DMARC1; p=none; rua=mailto:postmaster@corefluxapp.com; pct=100`   |

Start with `p=none` (monitoring only). After a week of clean reports,
move to `p=quarantine`, then `p=reject` once you're confident.

## Step 5 — Add Cloudways environment variables

Cloudways → Application Settings → **Environment Variables** → add:

```
RESEND_API_KEY=re_your_real_key_from_step_1
RESEND_FROM_EMAIL=noreply@corefluxapp.com
RESEND_FROM_NAME=CoreFlux Notifications
APP_URL=https://www.corefluxapp.com
```

Save → Cloudways will prompt you to restart PHP-FPM → **confirm**.

Verify via SSH:
```bash
echo "<?php echo 'key=' . substr(getenv('RESEND_API_KEY'),0,6) . '... from=' . getenv('RESEND_FROM_EMAIL'); ?>" \
  | php
```
Should print `key=re_xxx... from=noreply@corefluxapp.com`.

## Step 6 — Run the tenant-mail migration

```bash
mysql -u <user> -p <db> < /path/to/coreflux/core/migrations/004_tenant_mail_settings.sql
```

Idempotent via `information_schema` guard — safe to re-run. Adds two
nullable columns to `tenants`.

## Step 7 — Smoke test end-to-end

1. Log in as a tenant admin on https://www.corefluxapp.com/.
2. **Settings → Email delivery** → set **Reply-To** to an inbox you
   control (e.g. a temp `+test` alias of your own Gmail).
3. Optional: set a display name like "Acme Staffing Timesheets".
4. Save. The preview card updates instantly.
5. Go to **Time → Review Queue** → pick a placement with tokenized
   email enabled + a valid client_approver_email → select at least
   one pending entry → **Request client approval**.
6. The email arrives. Inspect the headers:
   - `From:` = your display name + `noreply@corefluxapp.com`
   - `Reply-To:` = the tenant's inbox you set in Step 7.2
   - No "via resend.com" tag (because Step 3 is in place)
   - `Received-SPF: pass`
   - `Authentication-Results: ... dkim=pass ... spf=pass ... dmarc=pass`
7. Hit Reply in your mail client → confirm it pre-fills the tenant's
   Reply-To, not yours.

## What tenants see on their side

Tenants go to **Settings → Email delivery** and see two fields:
- **Reply-To address** (any valid email they own; no DNS required)
- **Sender display name** (free-form; replaces "CoreFlux Notifications"
  in the From header)

Plus a live preview of exactly how their outgoing emails will look.
They never see Resend, DNS records, or the API key.

## Rollback

- Unset `RESEND_API_KEY` in Cloudways env → platform falls back to
  `LogDriver` (emails written to `/app/storage/_dev/mail_outbox.log`,
  never delivered). Useful as a kill-switch if Resend has an outage.
- Schema rollback:
  ```sql
  ALTER TABLE tenants DROP COLUMN mail_from_name_override;
  ALTER TABLE tenants DROP COLUMN mail_reply_to;
  ```

## Phase 6b — Model C (tenant-verified From domains + deliverability health) — NOT in this drop

When a tenant asks for emails to *actually come from* their own domain
(e.g. `timesheets@acme-staffing.com`), we'll bolt on:
- `tenants.mail_from_email` + `tenants.mail_from_verified` +
  `tenants.mail_resend_domain_id` columns
  (`005_tenant_mail_model_c.sql`)
- Tenant-self-service domain verification flow:
  tenant pastes domain → we call Resend's `POST /domains` API → we surface
  the DNS records they need to add → tenant clicks "verify now" → we call
  Resend's verify API → flip `mail_from_verified = 1`
- `cf_tenant_mail_sender()` will prefer the verified tenant domain over
  the platform default.
- **Email deliverability health dashboard** (scoped into Model C): live
  SPF/DKIM/DMARC status from Resend, 30-day delivery/bounce/complaint
  rates pulled from a new Resend webhook handler
  (`api/webhooks/resend.php`, signature-verified) into a
  `mail_delivery_events` table, drill-down on bounces, and a "test send"
  probe with Gmail placement hint.

Everything in this drop is forward-compatible with Model C — no breaking
changes when we ship it.

Full Model C spec lives in `/app/memory/PRD.md` under Backlog (P1).
