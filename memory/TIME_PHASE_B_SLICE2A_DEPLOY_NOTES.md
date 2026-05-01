# Time Phase B Slice 2a — M365 Mailbox Connection (no AI yet)

First half of the AI inbox parsing story. Lands the OAuth plumbing,
driver, and folder-watch UI so a tenant admin can connect their Microsoft
365 mailbox, pick a folder, and manually trigger a "Fetch now" poll that
returns message metadata. **No AI parsing, no draft entry creation, no
cron yet** — those arrive in Slice 2b / 2c / 2d.

## What's in this drop

- **`Core\Mail\M365GraphDriver`** — PHP cURL driver (no SDK) implementing
  the existing `MailDriver` interface. Delegated multi-tenant OAuth
  (PKCE), delta-query polling on `/me/mailFolders/{id}/messages/delta`,
  lazy token refresh with 5-minute buffer, `$deltatoken` extraction for
  incremental polling. Outbound `send()` deliberately returns
  `failed` — Resend handles outbound platform-wide.
- **`/oauth/callback/microsoft365.php`** — state-validated callback
  page; exchanges auth code → tokens → looks up the signed-in mailbox
  → inserts/upgrades a `tenant_mail_connections` row → encrypts tokens
  via existing `Core\encryption` AES-256-GCM → audits
  `mail.connection.connected` → redirects back to `/settings/mail`
  with a success or error flash.
- **`/api/mail_connections.php`** — platform-level tenant-scoped API:
  - `GET` → list connections + watched folders
  - `POST ?action=oauth_start&provider=m365` → returns Microsoft
    authorize URL (stores PKCE + state in `$_SESSION`, 10-min window)
  - `POST ?action=list_folders&connection_id=N` → live Graph call to
    fetch the user's mail folders for the picker
  - `POST ?action=watch_folder` → upsert `tenant_mail_folders` row
  - `POST ?action=poll_now&folder_id=N` → synchronous delta poll,
    returns a sample of messages (no writes beyond the cursor)
  - `DELETE ?id=N` → mark connection `revoked` (soft)
- **`MailConnectionsCard.jsx`** — new card on the existing Mail Settings
  page. "Connect Microsoft 365" button → OAuth redirect. Connected
  mailboxes list with folder picker modal and "Fetch now" button that
  shows a preview of 5 sample subjects/senders from the latest poll.
- **Smoke tests** — `tests/m365_graph_smoke.php` (46 ✓): PKCE S256
  challenge, delta-token extraction, token exchange happy + error
  path (injected transport), `/me` fetch, all 4 API actions,
  callback state validation, UI wiring.
- **Bundle** — rebuilt; 1715 modules, 359kB JS.

## Required Azure setup (you said it's done — this is the checklist
for reference + for the second redirect URI)

1. Azure Portal → **App registrations** → your app
   (`d5d81312-faf4-47ba-a001-d9a090415baa`).
2. **Authentication** → **Platform configurations** → **Web** →
   Redirect URIs. **Add both** if not already present:
   - `https://www.corefluxapp.com/oauth/callback/microsoft365.php`
   - `https://<your-preview-domain>/oauth/callback/microsoft365.php`
3. **Implicit grant and hybrid flows**: leave both unchecked
   (we use auth-code + PKCE).
4. **Advanced settings**:
   - **Allow public client flows**: No
   - **Supported account types**: verify this is **"Accounts in any
     organizational directory (Any Azure AD directory - Multitenant)"**
     — this is what lets each CoreFlux tenant's M365 users consent.
5. **API permissions** → **Microsoft Graph** (Delegated):
   - `Mail.Read`
   - `offline_access`
   - Click **Grant admin consent for <your tenant>** if your own M365
     tenant requires it (tenant-specific). Other tenants will consent
     individually when they click "Connect Microsoft 365" in CoreFlux.
6. **Certificates & secrets** → **New client secret** → copy the
   **Value** (not Secret ID). Keep it — you'll paste it in step 3 below.

## Cloudways env vars

Add under **Application Settings → Environment Variables**:

```
MICROSOFT_CLIENT_ID=ap-phase-a0
MICROSOFT_CLIENT_SECRET=<paste the Value from Azure step 6>
MICROSOFT_REDIRECT_URI=https://www.corefluxapp.com/oauth/callback/microsoft365.php
```

For the preview/staging environment, set `MICROSOFT_REDIRECT_URI` to
that preview domain instead — but Azure accepts the full list, so
matching whatever `$_SERVER['HTTP_HOST']` is on the live box works.

Restart PHP-FPM after saving.

## No schema migration needed

`tenant_mail_connections` and `tenant_mail_folders` already exist from
`core/migrations/003_mail_service.sql` (Skinny 3b). If that migration
hasn't been run yet on your Cloudways DB, run it now:
```bash
mysql -u <user> -p <db> < /path/to/coreflux/core/migrations/003_mail_service.sql
```
Idempotent.

## End-to-end smoke test (after deploy)

1. Log in to https://www.corefluxapp.com as a tenant admin.
2. **Settings → Email delivery** → scroll to the **Inbound mailboxes**
   card at the bottom → **Connect Microsoft 365**.
3. Microsoft login screen appears → pick the account that owns the
   timesheet inbox → consent. Browser redirects back to
   `/oauth/callback/microsoft365.php` → you see
   "Connected: you@yourdomain.com" for 1 second → auto-redirects to
   Settings → Email delivery with a green banner.
4. Under the connection, click **Pick folder…** → the Graph API is
   queried live and the top-level folders render with item counts →
   click the folder that receives timesheets (e.g. "Timesheets" or a
   subfolder of Inbox).
5. A new row appears under the connection: folder path + `time` badge
   + "— never polled —".
6. Click **Fetch now**. Within ~5 seconds a panel appears showing
   "N message(s) seen" and the top 5 subjects + senders. The row's
   "Last polled" timestamp updates and "Cursor" flips to ✓.
7. Re-click **Fetch now**. Second call uses the stored delta cursor,
   so it should return `0 messages` unless new mail arrived between
   calls. This proves the incremental sync works.
8. (Optional) Revoke connection via the trash-can button → row flips
   to `revoked` → polling is gated off.

## Security + operational notes

- **Token encryption** — `oauth_access_token_ct` + `oauth_refresh_token_ct`
  are AES-256-GCM ciphertext (via `Core\encryption`), never exposed in
  API responses.
- **State validation** — callback uses `hash_equals()` against the
  state stored in `$_SESSION['m365_oauth']` (10-min window); replay or
  CSRF attempts are rejected with a friendly error page.
- **Multi-tenant OAuth** — the app is registered multi-tenant, so every
  CoreFlux tenant's M365 admin can consent for their own Azure AD
  directory; we never need a shared service principal.
- **Delta tokens** — stored in `tenant_mail_folders.last_message_cursor`;
  first poll fetches all messages, subsequent polls are incremental.
  If you ever need a full resync, clear that column (`UPDATE
  tenant_mail_folders SET last_message_cursor = NULL WHERE id = N`).
- **Rate limits** — Graph allows 2000 req/60s per user; `poll_now` is
  user-triggered, so throttling is not a concern until we add cron in
  Slice 2d. 429 responses gracefully stop the poll loop.
- **Refresh tokens** — Microsoft doesn't rotate refresh tokens on each
  exchange, but they expire after 90 days of inactivity. If polling
  breaks after a long idle period, the tenant admin re-clicks
  **Connect Microsoft 365** to re-authorize — the existing connection
  row is reused, not duplicated.

## Rollback

- Unset `MICROSOFT_CLIENT_SECRET` in Cloudways env → the oauth_start
  endpoint returns `503 MICROSOFT_CLIENT_SECRET not configured. …`
  and the "Connect Microsoft 365" button shows an error toast. Existing
  connections keep their rows but can't refresh → eventually fall to
  `reauth_required`.
- Schema rollback: Skinny 3b tables were additive; no data loss risk
  from dropping the feature entirely.

## What's deferred to Slice 2b / 2c / 2d

- **Slice 2b** — `time_intake_events` table, OpenAI prompt that extracts
  `{placement, work_date, hours, category}` proposals from email body +
  attachments (PDF text extract), intake convert/dismiss/flag endpoints.
- **Slice 2c** — the "Inbox (AI)" sidebar view showing
  email ⇄ AI proposal, one-click convert-to-pending-review.
- **Slice 2d** — cron entrypoint `/app/cron/time_inbox_poll.php` + the
  Cloudways cron job config (`* * * * * /usr/bin/php /home/master/applications/<app>/public_html/cron/time_inbox_poll.php`).
