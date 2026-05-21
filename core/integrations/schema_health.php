<?php
/**
 * Integration schema health — column-width safety auditor.
 *
 * Tracks every encrypted-credential column across every integration
 * and reports columns that are smaller than their recommended minimum.
 *
 * Why this exists: AES-256-GCM ciphertext is `plaintext + 28 bytes`
 * (12-byte nonce + 16-byte tag). When a provider's token format grows
 * — e.g. JobDiva's V2 JWT going from "we never got one" to "1.5 KB"
 * — undersized columns truncate silently or, in MySQL strict mode,
 * surface as the cryptic `SQLSTATE[22001] 1406 Data too long for
 * column …`. This helper lets the operator catch that drift at a
 * glance instead of debugging it after a connection failure.
 *
 * The registry mirrors `core/migrations/*` and is the single source of
 * truth for "what we expect to see in production". Adding a new
 * integration: append a row to `cf_schema_health_registry()` + cover
 * with smoke.
 *
 * Public surface:
 *   cf_schema_health_registry(): array          // registry rows
 *   cf_schema_health_check(): array             // live report
 *   cf_schema_health_status(): string           // overall green|amber|red
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

/**
 * Registry of all encrypted credential columns CoreFlux relies on.
 * `min_bytes` is the per-column minimum width in bytes that should keep
 * the column above the worst-case (token plaintext + 28-byte AES wrap).
 *
 * Sizes are conservative: when in doubt, prefer 4096 over 2048 since
 * the marginal cost is zero on InnoDB row format.
 */
if (!function_exists('cf_schema_health_registry')) {
    function cf_schema_health_registry(): array {
        return [
            // ---- Accounting ----
            ['integration' => 'qbo',         'integration_label' => 'QuickBooks Online', 'table' => 'qbo_connections',         'column' => 'access_token_ct',        'min_bytes' => 4096, 'stores' => 'Intuit OAuth access token (JWT, ~1–2 KB)'],
            ['integration' => 'qbo',         'integration_label' => 'QuickBooks Online', 'table' => 'qbo_connections',         'column' => 'refresh_token_ct',       'min_bytes' => 1024, 'stores' => 'Intuit OAuth refresh token'],
            ['integration' => 'zoho_books',  'integration_label' => 'Zoho Books',        'table' => 'zoho_books_connections',  'column' => 'access_token_ct',        'min_bytes' => 2048, 'stores' => 'Zoho OAuth access token'],
            ['integration' => 'zoho_books',  'integration_label' => 'Zoho Books',        'table' => 'zoho_books_connections',  'column' => 'refresh_token_ct',       'min_bytes' => 2048, 'stores' => 'Zoho OAuth refresh (long-lived)'],

            // ---- Payment rails ----
            ['integration' => 'plaid',       'integration_label' => 'Plaid',             'table' => 'plaid_items',             'column' => 'access_token_ct',        'min_bytes' => 512,  'stores' => 'Plaid access token (~70 chars)'],
            ['integration' => 'mercury',     'integration_label' => 'Mercury',           'table' => 'mercury_connections',     'column' => 'api_token_ct',           'min_bytes' => 512,  'stores' => 'Mercury API token (~80 chars)'],

            // ---- Staffing ----
            ['integration' => 'jobdiva',     'integration_label' => 'JobDiva',           'table' => 'jobdiva_connections',     'column' => 'password_enc',           'min_bytes' => 1024, 'stores' => 'JobDiva API user password'],
            ['integration' => 'jobdiva',     'integration_label' => 'JobDiva',           'table' => 'jobdiva_connections',     'column' => 'session_token_enc',      'min_bytes' => 4096, 'stores' => 'JobDiva V2 JWT (~1.5 KB)'],
            ['integration' => 'jobdiva',     'integration_label' => 'JobDiva',           'table' => 'jobdiva_connections',     'column' => 'webhook_secret_enc',     'min_bytes' => 1024, 'stores' => 'JobDiva webhook HMAC secret'],

            // ---- Ops / CRM ----
            ['integration' => 'airtable',    'integration_label' => 'Airtable',          'table' => 'airtable_connections',    'column' => 'pat_ct',                 'min_bytes' => 2048, 'stores' => 'Airtable Personal Access Token'],

            // ---- Mail / SSO (platform-level but still on the integration risk map) ----
            ['integration' => 'mail',        'integration_label' => 'Mail (IMAP/OAuth)', 'table' => 'mail_oauth',              'column' => 'oauth_access_token_ct',  'min_bytes' => 4096, 'stores' => 'Gmail/Microsoft Graph OAuth access token (~1–2 KB)'],
            ['integration' => 'mail',        'integration_label' => 'Mail (IMAP/OAuth)', 'table' => 'mail_oauth',              'column' => 'oauth_refresh_token_ct', 'min_bytes' => 1024, 'stores' => 'OAuth refresh token'],
            ['integration' => 'mail',        'integration_label' => 'Mail (IMAP/OAuth)', 'table' => 'mail_imap',               'column' => 'imap_password_ct',       'min_bytes' => 512,  'stores' => 'IMAP password'],
            ['integration' => 'sso',         'integration_label' => 'Tenant SSO',        'table' => 'tenant_sso_domains',      'column' => 'client_secret_enc',      'min_bytes' => 1024, 'stores' => 'OIDC/OAuth client secret'],
        ];
    }
}

/**
 * Live introspection — joins the registry against
 * information_schema.COLUMNS and returns a row per registered column
 * with its actual width + verdict.
 *
 * Verdict values:
 *   - ok           : column width >= min_bytes
 *   - undersized   : column width < min_bytes (action required)
 *   - missing      : table or column not in the database yet
 *                    (migration pending — informational, not red)
 *   - unknown      : couldn't introspect (DB error etc.)
 */
if (!function_exists('cf_schema_health_check')) {
    function cf_schema_health_check(): array {
        $rows = cf_schema_health_registry();
        $out  = [];
        try {
            $pdo = getDB();
        } catch (\Throwable $e) {
            // Catastrophic: can't even open the DB. Return unknowns.
            foreach ($rows as $r) $out[] = $r + ['actual_bytes' => null, 'data_type' => null, 'verdict' => 'unknown', 'message' => 'DB unavailable'];
            return $out;
        }
        $stmt = $pdo->prepare(
            'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = :tbl
                AND COLUMN_NAME  = :col
              LIMIT 1'
        );
        foreach ($rows as $r) {
            try {
                $stmt->execute(['tbl' => $r['table'], 'col' => $r['column']]);
                $col = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            } catch (\Throwable $e) {
                $out[] = $r + ['actual_bytes' => null, 'data_type' => null, 'verdict' => 'unknown', 'message' => substr($e->getMessage(), 0, 200)];
                continue;
            }
            if (!$col) {
                $out[] = $r + ['actual_bytes' => null, 'data_type' => null, 'verdict' => 'missing', 'message' => 'Column not present — migration pending?'];
                continue;
            }
            $actual = $col['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int) $col['CHARACTER_MAXIMUM_LENGTH'] : null;
            $verdict = $actual !== null && $actual < (int) $r['min_bytes'] ? 'undersized' : 'ok';
            $out[] = $r + [
                'actual_bytes' => $actual,
                'data_type'    => strtolower((string) $col['DATA_TYPE']),
                'verdict'      => $verdict,
                'message'      => $verdict === 'undersized'
                    ? sprintf(
                        'Column is %d bytes but recommended minimum is %d. Run: ALTER TABLE %s MODIFY COLUMN %s VARBINARY(%d);',
                        $actual, (int) $r['min_bytes'], $r['table'], $r['column'], (int) $r['min_bytes']
                    )
                    : null,
            ];
        }
        return $out;
    }
}

/**
 * Overall traffic-light status across all registered columns.
 *   - red   : at least one undersized column
 *   - amber : no undersized but at least one missing/unknown
 *   - green : every registered column is present + wide enough
 */
if (!function_exists('cf_schema_health_status')) {
    function cf_schema_health_status(?array $rows = null): string {
        $rows = $rows ?? cf_schema_health_check();
        $hasUndersized = false; $hasUnknown = false;
        foreach ($rows as $r) {
            if ($r['verdict'] === 'undersized')                  $hasUndersized = true;
            elseif (in_array($r['verdict'], ['missing','unknown'], true)) $hasUnknown    = true;
        }
        if ($hasUndersized) return 'red';
        if ($hasUnknown)    return 'amber';
        return 'green';
    }
}
