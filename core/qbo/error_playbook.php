<?php
/**
 * core/qbo/error_playbook.php
 *
 * QBO error-code remediation playbook — maps Intuit's machine-readable
 * `Fault.Error[0].code` values onto a structured remediation hint so
 * the DLQ admin UI can show a one-line "Suggested fix" alongside the
 * raw vendor body.
 *
 * Codes sourced from the Intuit error code catalogue and our own
 * observed-failure history. NOT exhaustive — fall through to a
 * generic hint when unknown.
 *
 * Return shape:
 *   [
 *     'code'         => string,          // QBO's numeric code
 *     'category'     => string,          // e.g. 'validation' | 'auth' | 'permission' | 'duplicate' | 'rate_limit' | 'unknown'
 *     'severity'     => string,          // 'requeue_safe' | 'fix_data' | 'fix_config' | 'fix_oauth'
 *     'summary'      => string,          // one-line explanation
 *     'suggested_fix'=> string,          // actionable remediation
 *     'docs_link'    => ?string,         // Intuit docs anchor when available
 *   ]
 */
declare(strict_types=1);

/**
 * The playbook table. Indexed by QBO error code (string-typed so we can
 * support both numeric codes like "6210" and word codes like "stale_object").
 */
function qboErrorPlaybookTable(): array
{
    static $table = null;
    if ($table !== null) return $table;

    $docs = 'https://developer.intuit.com/app/developer/qbo/docs/develop/troubleshooting/error-codes';

    $table = [
        // ───────── Validation ─────────
        '6000' => [
            'category' => 'validation', 'severity' => 'fix_data',
            'summary'  => 'Generic business validation error.',
            'suggested_fix' => 'Inspect vendor_raw → the QBO `Detail` field names the offending field. Fix the source data (e.g. missing customer, bad date) and requeue.',
        ],
        '6190' => [
            'category' => 'validation', 'severity' => 'fix_data',
            'summary'  => 'Business validation error (often a stale-id reference).',
            'suggested_fix' => 'A referenced entity (Customer, Vendor, Account) was deleted or modified after the payload was built. Re-sync the entity from QBO (`/api/admin/qbo/pull_*` endpoints) and requeue.',
        ],
        '6210' => [
            'category' => 'validation', 'severity' => 'fix_data',
            'summary'  => 'Journal lines do not balance (debit total ≠ credit total).',
            'suggested_fix' => 'Open the JE in CoreFlux and re-post it — the line totals likely drifted due to a manual edit. The CoreFlux JE balance check should be the source of truth.',
        ],
        '6240' => [
            'category' => 'validation', 'severity' => 'fix_data',
            'summary'  => 'Required field missing on the payload.',
            'suggested_fix' => 'Check vendor_raw for the missing field name. Likely a vendor/customer mapping is incomplete — update the `accounting_account_mappings` grid for this tenant.',
        ],
        '6470' => [
            'category' => 'validation', 'severity' => 'fix_data',
            'summary'  => 'Account type incompatible with this transaction.',
            'suggested_fix' => 'The mapped QBO account is the wrong type (e.g. mapping an Expense JE line to an Income account). Update the operator mapping for this CoreFlux account.',
        ],
        '6610' => [
            'category' => 'duplicate', 'severity' => 'fix_data',
            'summary'  => 'Duplicate DocNumber / TxnNumber.',
            'suggested_fix' => 'QBO already has an entity with this DocNumber. Either (a) bump CoreFlux\'s next-number sequence past the conflict, or (b) reconcile via `external_entity_mappings` if QBO already received the same payload.',
        ],

        // ───────── Auth ─────────
        '3100' => [
            'category' => 'auth', 'severity' => 'fix_oauth',
            'summary'  => 'Invalid app token / app revoked.',
            'suggested_fix' => 'Tenant must reconnect from Settings → Integrations → QuickBooks. The OAuth grant was revoked at the QBO side.',
        ],
        '3200' => [
            'category' => 'auth', 'severity' => 'fix_oauth',
            'summary'  => 'Access token expired or invalid.',
            'suggested_fix' => 'The proactive refresh cron should self-heal this within 15 min. If it persists, the refresh token itself has expired — tenant must reconnect.',
        ],
        '3201' => [
            'category' => 'auth', 'severity' => 'requeue_safe',
            'summary'  => 'Auth token state transient.',
            'suggested_fix' => 'Usually self-recovers on retry. Confirm `qbo_token_refresh.php` cron is running, then requeue.',
        ],

        // ───────── Permission ─────────
        '5010' => [
            'category' => 'permission', 'severity' => 'fix_config',
            'summary'  => 'User lacks permission for this entity in QBO.',
            'suggested_fix' => 'The QBO admin who connected this tenant doesn\'t have rights to create this entity type. Have a QBO admin with full Accountant access reconnect.',
        ],

        // ───────── Rate limit / throttling ─────────
        '4001' => [
            'category' => 'rate_limit', 'severity' => 'requeue_safe',
            'summary'  => 'Throttle / concurrent request limit hit.',
            'suggested_fix' => 'No action needed — the backoff schedule will retry. If this dead-letters, requeue once; persistent throttling means the cron is over-pushing.',
        ],

        // ───────── Server / transient ─────────
        '0' => [
            'category' => 'unknown', 'severity' => 'requeue_safe',
            'summary'  => 'Code 0 — QBO returned no machine-readable code.',
            'suggested_fix' => 'Inspect vendor_raw verbatim. Often a transient 5xx — requeue once before deeper investigation.',
        ],
    ];
    foreach ($table as $code => $entry) {
        $table[$code]['code'] = (string) $code;
        $table[$code]['docs_link'] = $docs;
    }
    return $table;
}

/**
 * Look up a remediation hint for a given QBO error code. Returns a
 * fallback "unknown code" entry when no specific match exists, so the
 * UI never has to handle a null.
 */
function qboErrorPlaybookLookup(?string $code): array
{
    $code = trim((string) ($code ?? ''));
    $table = qboErrorPlaybookTable();
    if ($code !== '' && isset($table[$code])) {
        return $table[$code];
    }
    return [
        'code'          => $code,
        'category'      => 'unknown',
        'severity'      => 'fix_data',
        'summary'       => $code === ''
            ? 'No QBO error code was reported by the upstream API.'
            : "QBO error code {$code} is not in the local playbook.",
        'suggested_fix' => 'Inspect vendor_raw — Intuit\'s `Fault.Error[0].Detail` typically explains the rejection. Once the underlying cause is fixed, requeue.',
        'docs_link'     => 'https://developer.intuit.com/app/developer/qbo/docs/develop/troubleshooting/error-codes',
    ];
}
