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
