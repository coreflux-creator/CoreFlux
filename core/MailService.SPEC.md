# Core MailService — Specification

**Status**: DRAFT — pending user sign-off
**Stack**: PHP 8 (per HARD_RULES decisions log)
**Layer**: Core platform primitive — NOT a module. Lives in `/core/MailService.php` + connector classes under `/core/mail/`.
**Consumers**: every module that needs to read inbound mail or send outbound mail (Time, future Accounts Receivable / Invoicing, future Accounts Payable, future Payroll notifications, future Customer Communications).

This SPEC defines the platform-wide email primitive. Modules MUST NOT call SMTP, IMAP, Microsoft Graph, Gmail API, or Resend directly — they go through `Core\MailService`. This isolates provider choices behind one abstraction so they can change later by editing one file.

---

## 1. Why this is a core service

Multiple modules need email:
- **Time** reads tenant `timesheets` folder; sends tokenized client approvals.
- **Accounting** (future) will read tenant `invoices` folder for inbound vendor invoices; send outbound customer invoices.
- **Payroll** (future) will send pay stubs / direct-deposit notifications.
- Cross-cutting transactional emails (password reset, MFA, audit alerts).

Without a core service, every module re-implements OAuth / IMAP / SMTP, three times. A core service:
- Single OAuth flow per tenant per provider.
- One credential store, one audit pathway, one retry/throttle layer.
- Provider switch (e.g. add Yahoo, swap Resend for Postmark) is one file.

---

## 2. Two halves: Inbound connector + Outbound sender

### 2.1 Inbound connector (read-only)
**Locked decisions** (per Time SPEC sign-off):
- Tenant retains email in their own mail system. CoreFlux does **not** host tenant inboxes.
- Providers at MVP: **Microsoft 365 (Graph API)** and **Google Workspace (Gmail API)**.
- Folder-scoped: tenant creates a dedicated folder per consuming module (e.g. `timesheets`, later `invoices`); connector reads only that folder.
- **Non-destructive**: connector does NOT mark-as-read, move, label, delete, or modify the source email. Processing status is tracked exclusively inside CoreFlux on the consuming module's intake table.
- **Polling architecture**: per-tenant cron poll on a configurable interval (default 5 min). No webhooks at MVP.
- IMAP fallback (Phase B) for tenants on Yahoo / Fastmail / custom mail servers.

### 2.2 Outbound sender
**Locked decisions** (per Time SPEC sign-off):
- "From" address is **the tenant's own domain** (e.g. `no-reply@tenantcompany.com`, `timesheets@tenantcompany.com`, `invoices@tenantcompany.com`).
- Two transport options per tenant, configured in MailService settings:
  - **Option A — OAuth send via the tenant's own mail account** (Microsoft Graph `Mail.Send` scope or Gmail `gmail.send` scope). No DNS work. Outbound shows up in the tenant's own Sent folder. Reuses the same OAuth as inbound.
  - **Option B — Shared Resend account with the tenant's domain verified** in Resend. Tenant adds DKIM/SPF DNS records pointing to Resend. Better deliverability at scale; outbound does NOT appear in the tenant's Sent folder (separate provider).
- Tenant picks one of A/B per tenant (not per email type) at MVP.

---

## 3. Data model

### 3.1 `tenant_mail_connections` (one row per provider connection per tenant)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `provider` | ENUM('m365','google','imap','resend') | |
| `purpose` | ENUM('inbound','outbound','both') | |
| `display_name` | VARCHAR(200) | tenant-chosen label, e.g. "Acme Workspace" |
| `account_address` | VARCHAR(255) | the mailbox / sender address |
| `oauth_access_token_ct` | VARBINARY(2048) NULL | application-level encrypted (KMS) |
| `oauth_refresh_token_ct` | VARBINARY(2048) NULL | encrypted; refresh tokens are highly sensitive |
| `oauth_expires_at` | DATETIME NULL | |
| `oauth_scope` | VARCHAR(500) NULL | granted scopes |
| `imap_host` / `imap_port` / `imap_username` / `imap_password_ct` | VARCHAR / INT / VARCHAR / VARBINARY | IMAP fallback only |
| `resend_api_key_id` | VARCHAR(120) NULL | Resend key id (if outbound via Resend) |
| `resend_verified_domain` | VARCHAR(200) NULL | the domain Resend has verified for this tenant |
| `kms_key_version` | VARCHAR(64) | for rewrap on rotation |
| `status` | ENUM('active','reauth_required','revoked','error') | |
| `last_polled_at` | DATETIME NULL | inbound only |
| `last_sent_at` | DATETIME NULL | outbound only |
| `last_error` | TEXT NULL | |
| `created_by_user_id` | BIGINT FK | |
| `created_at` / `updated_at` | DATETIME | |

Indexes: `(tenant_id, provider, purpose)`, `(status, last_polled_at)`.

### 3.2 `tenant_mail_folders` (per-tenant folder mapping for inbound connectors)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `connection_id` | BIGINT FK | |
| `module` | VARCHAR(40) | which CoreFlux module consumes this folder, e.g. `time`, `accounting` |
| `folder_path` | VARCHAR(500) | provider-native path: `Inbox/Timesheets`, label `coreflux/timesheets`, etc. |
| `folder_id_at_provider` | VARCHAR(255) | resolved native id (Graph mailFolder id, Gmail labelId) |
| `polling_enabled` | BOOLEAN | |
| `polling_interval_seconds` | INT | default 300 |
| `last_polled_at` | DATETIME NULL | |
| `last_message_cursor` | VARCHAR(255) NULL | provider-native delta cursor / historyId / UID |
| `dedupe_message_ids_window` | INT | how many recent message-ids to remember to avoid re-processing on cursor reset; default 5000 |

### 3.3 `mail_messages_seen` (dedupe ledger — prevents re-ingest if cursor resets)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `folder_id` | BIGINT FK | |
| `provider_message_id` | VARCHAR(255) | RFC822 Message-ID |
| `seen_at` | DATETIME | |
| `intake_event_ref` | VARCHAR(120) NULL | e.g. `time:1234` — module + intake row id |

Indexes: `(folder_id, provider_message_id)` UNIQUE.

### 3.4 `mail_outbox` (every outbound email logged)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `tenant_id` | BIGINT FK | |
| `module` | VARCHAR(40) | calling module |
| `purpose` | VARCHAR(80) | e.g. `time.token_approval`, `accounting.invoice_send` |
| `connection_id` | BIGINT FK | which transport was used |
| `to_addresses_json` | TEXT | array of recipients |
| `from_address` | VARCHAR(255) | tenant's domain address |
| `reply_to` | VARCHAR(255) NULL | |
| `subject` | VARCHAR(500) | |
| `body_text_ref` | VARCHAR(40) NULL | storage_object_id if body is large; else inline `body_text` |
| `body_text` | TEXT NULL | inline if small |
| `body_html` | TEXT NULL | |
| `attachments_json` | TEXT NULL | array of storage_object_ids |
| `status` | ENUM('queued','sent','failed','bounced','complaint') | |
| `provider_message_id` | VARCHAR(255) NULL | id returned by provider |
| `sent_at` | DATETIME NULL | |
| `error` | TEXT NULL | |
| `created_by_user_id` | BIGINT NULL FK | |
| `created_at` | DATETIME | |

---

## 4. PHP API surface

### 4.1 `Core\MailService`

```php
namespace Core;

class MailService {
    // ─── Inbound ───
    public function poll_folder(int $folderId): PollResult;
    public function fetch_message(int $folderId, string $providerMessageId): MailMessage;
    public function list_recent_unprocessed(int $folderId, int $limit = 100): MailMessageList;

    // ─── Outbound ───
    public function send(
        int    $tenantId,
        string $module,
        string $purpose,
        array  $to,
        string $subject,
        string $bodyText,
        ?string $bodyHtml = null,
        array  $attachments = [],   // array of storage_object_ids
        array  $opts = []           // ['reply_to' => ..., 'from' => ..., 'connection_id' => ...]
    ): MailSendResult;

    // ─── Connection management ───
    public function start_oauth_flow(int $tenantId, string $provider, string $purpose): string; // returns redirect URL
    public function complete_oauth_callback(int $tenantId, string $provider, array $callbackParams): MailConnection;
    public function refresh_token_if_needed(int $connectionId): void;
    public function revoke_connection(int $connectionId): void;
    public function test_connection(int $connectionId): TestResult;
}

interface MailDriver {
    public function poll(int $folderId, ?string $cursor): PollResult;
    public function send(MailEnvelope $env): MailSendResult;
    public function refresh_oauth(int $connectionId): void;
    public function revoke(int $connectionId): void;
}

class M365GraphDriver implements MailDriver { ... }
class GmailApiDriver implements MailDriver { ... }
class ImapDriver implements MailDriver { ... }   // Phase B
class ResendDriver implements MailDriver { ... }  // outbound only
```

### 4.2 Module integration pattern

Time module's poll cron calls:
```php
$folder = MailService::resolve_folder($tenantId, 'time');   // looks up tenant_mail_folders
$result = MailService::poll_folder($folder->id);
foreach ($result->messages as $msg) {
    if (!MailService::is_new_message($folder->id, $msg->provider_message_id)) continue;
    $rawObjId = StorageService::put('time', $tenantId, 'raw', /*entity_id*/ $msg->provider_message_id, 'message.eml', $msg->raw_eml);
    $intakeId = TimeIntake::create_from_mail($tenantId, $msg, $rawObjId);
    MailService::mark_seen($folder->id, $msg->provider_message_id, "time:$intakeId");
}
```

Time module's tokenized approval send:
```php
MailService::send(
    $tenantId,
    'time',
    'token_approval',
    [$clientApproverEmail],
    "Approve timesheet for {$person->name} — week of {$period->label}",
    $textBody,
    $htmlBody,
    [],
    ['reply_to' => $tenantConfig->reply_to]
);
```

---

## 5. OAuth flows

### 5.1 Microsoft 365 (Graph API)
- App registration in Azure AD: `Mail.Read` (inbound), `Mail.Send` (outbound), `MailboxSettings.Read`, `offline_access`.
- Tenant admin consents on behalf of the mailbox owner (or per-user, depending on tenant policy).
- Refresh tokens last ~90 days; `refresh_token_if_needed()` called on every poll.

### 5.2 Google Workspace (Gmail API)
- Google Cloud project: scopes `gmail.readonly` (inbound), `gmail.send` (outbound), `gmail.labels` (resolve folder by label).
- Domain-wide delegation NOT used at MVP (avoids needing Workspace super-admin); per-user consent instead.
- Refresh tokens long-lived; rotate on `invalid_grant`.

### 5.3 Resend (outbound only)
- Tenant adds DKIM/SPF/DMARC DNS records in their domain pointing to Resend.
- Tenant supplies their Resend API key in MailService config.
- Verified domain status checked daily; alerts on degradation.

### 5.4 Token storage
- Access + refresh tokens encrypted at application level (PHP openssl + KMS). Same pattern as banking/PII fields — DB dump alone is useless.
- Tokens never logged.
- Tokens auto-rewrap on KMS key rotation.

---

## 6. Polling strategy

A platform cron (e.g. every minute) selects connections due for poll based on `last_polled_at + polling_interval_seconds`, batches them, and dispatches to per-driver workers.

- **M365 Graph**: uses `delta` queries on the folder for incremental fetch.
- **Gmail**: uses `historyId` since-last-poll.
- **IMAP** (Phase B): UIDNEXT / UIDVALIDITY tracking.

Rate-limits respected per provider; backoff on 429 responses; surface to `tenant_mail_connections.status='error'` after consecutive failures.

---

## 7. Security

- Per-tenant OAuth tokens encrypted at application layer.
- All inbound message bodies + attachments stored in S3 (via Core StorageService) under `mail/{tenant_id}/raw/{message_id}/...`. NEVER stored in DB long-term.
- Outbound `mail_outbox` body retained 90 days then truncated (keep metadata, drop body) — adjustable per tenant.
- All outbound emails carry `List-Unsubscribe` header (CAN-SPAM compliance) for marketing-style emails. Transactional (token approval, password reset) flagged as transactional.
- DKIM signing required for outbound (Resend handles automatically; OAuth-via-tenant-mail uses tenant's own DKIM).
- Bounce / complaint handling: provider webhooks parsed, `mail_outbox.status` updated; high-bounce addresses auto-suppressed per tenant.

---

## 8. Audit events

- `mail.connection.created` / `.reauthorized` / `.revoked` / `.errored`
- `mail.folder.added` / `.removed` / `.polling_enabled` / `.polling_disabled`
- `mail.poll.completed` (with messages_fetched count, duration)
- `mail.message.seen` (every dedupe insertion)
- `mail.outbound.queued` / `.sent` / `.failed` / `.bounced` / `.complained`
- `mail.token.refreshed` / `.refresh_failed`

All events tenant-scoped; tokens never appear in audit logs.

---

## 9. RBAC

| Slug | Description |
|---|---|
| `mail.connections.view` | View configured mail connections for the tenant |
| `mail.connections.manage` | Add / remove / re-authorize connections |
| `mail.folders.manage` | Map folders to consuming modules |
| `mail.outbox.view` | View sent email log for the tenant |
| `mail.test.send` | Trigger a test email |

---

## 10. Configuration / secrets

`.env`:

```
MAIL_M365_CLIENT_ID=
MAIL_M365_CLIENT_SECRET=
MAIL_M365_REDIRECT_URI=

MAIL_GOOGLE_CLIENT_ID=
MAIL_GOOGLE_CLIENT_SECRET=
MAIL_GOOGLE_REDIRECT_URI=

MAIL_RESEND_API_BASE=https://api.resend.com
# Per-tenant Resend keys are stored in tenant_mail_connections, not env

MAIL_KMS_KEY_ID=alias/coreflux-mail
```

---

## 11. MVP cut list

**Phase A (ship first):**
- `Core\MailService` skeleton + `MailDriver` interface
- M365 Graph driver (inbound + outbound) — most common in staffing
- `tenant_mail_connections`, `tenant_mail_folders`, `mail_messages_seen`, `mail_outbox` tables
- OAuth start/callback endpoints in core (admin UI in tenant settings)
- Polling cron
- Time module integration: poll `timesheets` folder, send tokenized approvals
- Audit + RBAC

**Phase B:**
- Gmail driver (inbound + outbound)
- Resend driver (outbound) — tenants who prefer Resend over OAuth-send
- IMAP fallback driver

**Phase C:**
- Webhook-based push for Graph (subscribe to mailbox) — replaces polling for low-latency tenants
- Bounce / complaint webhook handlers
- Outbound rate-limiting + DKIM key rotation tooling

---

## 12. Open questions

1. **Per-tenant Resend account** vs **CoreFlux-shared Resend account with tenant domain added**? You said "I have Resend" — confirming you want the shared-account model where you control the API key and add tenant domains under your Resend org. Cleaner for billing; deliverability scales together.
2. **Outbound on OAuth-via-tenant-mail**: Microsoft caps `Mail.Send` at ~10k messages/day per mailbox; Gmail caps at 2k/day for free, 10k/day for Workspace. Hard limits. Tenants with high outbound volume MUST use Resend. Surface this in the UI? Recommend: yes — show daily volume estimate and warn when approaching.
3. **Tenant-folder name convention**: do we mandate the folder name (`timesheets`, `invoices`) or let the tenant pick and map? Recommend: tenant picks any name, maps to module in MailService settings.
4. **Polling interval**: default 5 min OK? Some tenants may want 1 min for time-sensitive workflows. Recommend tenant-configurable, 1–60 min range.
5. **Email body retention** in `mail_outbox` — 90 days OK? Audit needs vary by jurisdiction (some require 7 years).

---

*This SPEC is binding once signed off. Update before code changes.*
