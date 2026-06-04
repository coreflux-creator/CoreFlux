<?php
// CoreFlux local/server-only config overrides.
// Copy to core/config.local.php on each deployment host and fill in the values.
// This file is gitignored — NEVER commit the real one.

// 32 random bytes, base64-encoded. Encrypts SSN, bank account, routing numbers.
// Generate with: php -r 'echo base64_encode(random_bytes(32));'
// DO NOT change once set in production — rotating it will orphan existing ciphertext.
define('COREFLUX_DATA_KEY', 'REPLACE_ME_WITH_BASE64_32_BYTES');

// OpenAI key — used directly by core/ai_service.php (no Python sidecar).
// Get one at https://platform.openai.com/api-keys
define('OPENAI_API_KEY', 'sk-proj-REPLACE_ME');

// Optional: per-feature-class model overrides. Defaults shown — uncomment to override.
// define('AI_MODEL_SUMMARY',        'gpt-5.4-mini');
// define('AI_MODEL_NARRATIVE',      'gpt-5.4');
// define('AI_MODEL_DRAFT',          'gpt-5.4');
// define('AI_MODEL_CLASSIFICATION', 'gpt-5.4-mini');
// define('AI_MODEL_DEEP_REASONING', 'gpt-5.4-thinking');
// define('AI_FALLBACK_MODEL',       'gpt-5.2');

// =========================================================================
// Plaid (Link / Auth / Transactions / Transfer)
// -------------------------------------------------------------------------
// Used by /app/core/plaid_service.php + /app/core/payment_rails/plaid_*
// Get keys at https://dashboard.plaid.com/developers/keys.
// PLAID_ENV controls which secret + host is active ('sandbox' | 'production').
// =========================================================================
// define('PLAID_CLIENT_ID',          'REPLACE_ME');
// define('PLAID_SECRET_SANDBOX',     'REPLACE_ME');
// define('PLAID_SECRET_PRODUCTION',  'REPLACE_ME');
// define('PLAID_ENV',                'sandbox');
// define('PLAID_WEBHOOK_URL',        'https://yourdomain.com/api/core/webhooks/plaid');

// =========================================================================
// Resend (transactional email — outbound only)
// -------------------------------------------------------------------------
// Used by core/mailer.php → mailerSend() and the Core\Mail\ResendDriver
// registered in core/mail_bootstrap.php. When RESEND_API_KEY is set (either
// via env var or via the define() below), every mailerSend() call routes
// through Resend; otherwise sends are captured by the LogDriver locally.
//
// Get a key at https://resend.com/api-keys ("Full access" recommended).
// Verify your sending domain at https://resend.com/domains before going
// to production — unverified domains can only deliver to your account's
// own verified addresses.
// =========================================================================
// define('RESEND_API_KEY',     're_REPLACE_ME');
// define('RESEND_FROM_EMAIL',  'no-reply@yourdomain.com');
// define('RESEND_FROM_NAME',   'CoreFlux Notifications');

// =========================================================================
// QuickBooks Online (QBO) — Intuit AppCenter OAuth 2.0
// -------------------------------------------------------------------------
// Used by /app/core/qbo/client.php and the /app/api/qbo.php router.
// Per-tenant connection model — each tenant connects their own Intuit
// company; CoreFlux never holds a partner-level token.
//
// 1) Create an app at https://developer.intuit.com/app/developer/dashboard
//    → "Just start creating an app" → pick scope "Accounting".
//
// 2) From the app's "Keys & OAuth" section, grab the **Development Keys**
//    (for sandbox testing) or **Production Keys** (after Intuit security
//    review). Each environment has its own client_id + client_secret.
//
// 3) On the same screen, add a Redirect URI **exactly** matching:
//        https://YOUR_HOST/api/qbo.php?action=oauth_callback
//    The trailing `?action=oauth_callback` query string IS part of the URI
//    Intuit will compare against — it must be registered verbatim.
//
// 4) For sandbox testing, every Intuit developer account auto-provisions
//    a free sandbox company at
//        https://developer.intuit.com/app/developer/sandbox
//    Connect to it from CoreFlux → Admin → QuickBooks Online → "Connect
//    to QuickBooks". After OAuth, qboPing() probes /companyinfo and
//    populates the company name on the connection row.
//
// 5) Flip QBO_ENV to 'production' AFTER Intuit approves the app for
//    production keys. Sandbox companies will NOT accept production keys
//    and vice-versa.
// =========================================================================
// define('QBO_CLIENT_ID',     'ABxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
// define('QBO_CLIENT_SECRET', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
// define('QBO_REDIRECT_URI',  'https://yourdomain.com/api/qbo.php?action=oauth_callback');
// define('QBO_ENV',           'sandbox');  // 'sandbox' | 'production'
// define('QBO_SCOPES',        'com.intuit.quickbooks.accounting');
