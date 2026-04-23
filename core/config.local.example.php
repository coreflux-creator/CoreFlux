<?php
// CoreFlux local/server-only config overrides.
// Copy to core/config.local.php on each deployment host and fill in the values.
// This file is gitignored — NEVER commit the real one.

// 32 random bytes, base64-encoded. Encrypts SSN, bank account, routing numbers.
// Generate with: php -r 'echo base64_encode(random_bytes(32));'
// DO NOT change once set in production — rotating it will orphan existing ciphertext.
define('COREFLUX_DATA_KEY', 'REPLACE_ME_WITH_BASE64_32_BYTES');

// AI sidecar reachability (Python FastAPI from /app/backend/).
// On Cloudways, either run the sidecar on the same VM (localhost) or expose it
// over HTTPS through a small subdomain + reverse proxy.
define('AI_SIDECAR_URL',    'http://localhost:8001/api/ai/chat');
define('AI_SIDECAR_SECRET', 'REPLACE_ME_COPY_FROM_SIDECAR_DOT_ENV');
