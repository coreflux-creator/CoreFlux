<?php
/**
 * core/mercury/error_playbook.php
 *
 * Mercury error-code remediation playbook — parallel to
 * `core/qbo/error_playbook.php`. Maps the `code` field on
 * MercuryApiException::$errorCode (Mercury's response body field
 * `code`, e.g. `invalid_recipient`, `insufficient_funds`, `rate_limit`)
 * onto a structured remediation hint.
 *
 * Why a Mercury playbook (banking is "the wire moved or didn't"):
 *   For QBO, primitive #6 surfaces validation errors so a human can
 *   tweak the source data and requeue. Banking errors are fewer but
 *   higher-stakes — the operator needs to know whether to (a) safely
 *   retry the same payment, (b) cancel-and-redo with edits, or
 *   (c) escalate to compliance.  The severity field encodes that
 *   distinction so the UI can colour the row appropriately.
 *
 * Return shape (identical to QBO playbook for UI re-use):
 *   ['code', 'category', 'severity', 'summary', 'suggested_fix', 'docs_link']
 */
declare(strict_types=1);

function mercuryErrorPlaybookTable(): array
{
    static $table = null;
    if ($table !== null) return $table;

    $docs = 'https://docs.mercury.com/reference/errors';

    $table = [
        // ─── Recipient validation ───
        'invalid_recipient' => [
            'category' => 'validation', 'severity' => 'fix_data',
            'summary'  => 'Recipient does not exist or is missing required fields.',
            'suggested_fix' => 'Open Mercury Recipients → confirm the recipient is present and has valid routing + account numbers. If the recipient was deleted upstream, recreate it before requeuing.',
        ],
        'invalid_routing_number' => [
            'category' => 'validation', 'severity' => 'fix_data',
            'summary'  => 'Routing number rejected by Mercury (failed ABA check or unknown FI).',
            'suggested_fix' => 'Re-verify the routing number with the recipient. Many fail because the recipient gave their wire routing number for an ACH payment (or vice versa).',
        ],
        'invalid_account_number' => [
            'category' => 'validation', 'severity' => 'fix_data',
            'summary'  => 'Account number rejected (length, format, or character set).',
            'suggested_fix' => 'Confirm the account number with the recipient. Mercury rejects spaces, dashes, and non-numeric characters.',
        ],
        'invalid_amount' => [
            'category' => 'validation', 'severity' => 'fix_data',
            'summary'  => 'Amount is below Mercury\'s minimum or above the rail-specific maximum.',
            'suggested_fix' => 'ACH minimum is $0.01 / maximum is $1M per transaction. Domestic wire maximum is $25M. International wires have country-specific caps.',
        ],

        // ─── Funds & limits ───
        'insufficient_funds' => [
            'category' => 'validation', 'severity' => 'fix_data',
            'summary'  => 'Source Mercury account does not have enough available balance.',
            'suggested_fix' => 'Inspect /api/admin/treasury/mercury_balance for the source account. Either move funds in via internal transfer (auto-sweep), wait for pending deposits to clear, or reduce the payment amount and requeue.',
        ],
        'daily_limit_exceeded' => [
            'category' => 'rate_limit', 'severity' => 'requeue_safe',
            'summary'  => 'Tenant\'s daily Mercury send limit hit.',
            'suggested_fix' => 'Mercury enforces a daily $/payment cap per business account. The payment will succeed on the next business day automatically — leave in Failed state and requeue after midnight UTC.',
        ],

        // ─── Auth ───
        'invalid_api_key' => [
            'category' => 'auth', 'severity' => 'fix_oauth',
            'summary'  => 'API token rejected by Mercury (revoked, rotated, or wrong tenant).',
            'suggested_fix' => 'Tenant admin must reconnect from Settings → Integrations → Mercury. The API token was either rotated in Mercury\'s dashboard or pasted into the wrong CoreFlux tenant.',
        ],
        'unauthorized' => [
            'category' => 'auth', 'severity' => 'fix_oauth',
            'summary'  => 'API token lacks permission for this operation.',
            'suggested_fix' => 'Mercury API tokens are scoped per-account. Confirm the token has "Send Payments" permission in Mercury → Settings → API.',
        ],

        // ─── Rate limiting ───
        'rate_limit_exceeded' => [
            'category' => 'rate_limit', 'severity' => 'requeue_safe',
            'summary'  => 'Mercury API rate limit hit (typically 100 req/min).',
            'suggested_fix' => 'No action needed — the next mpAdvance cron pass (every 5 min) will pick this up. If a single tenant is generating sustained throttle errors, check for a runaway batch.',
        ],

        // ─── Compliance / hold ───
        'compliance_hold' => [
            'category' => 'permission', 'severity' => 'fix_config',
            'summary'  => 'Payment held by Mercury\'s compliance team for review.',
            'suggested_fix' => 'Do NOT requeue. Mercury\'s compliance team must clear the hold (usually within 1 business day). Contact Mercury support if it persists.',
        ],
        'sanctions_screen_failed' => [
            'category' => 'permission', 'severity' => 'fix_config',
            'summary'  => 'Recipient flagged by OFAC / sanctions screening.',
            'suggested_fix' => 'CRITICAL: Do not retry. Escalate to compliance. The recipient may need additional KYC documentation or the payment may be prohibited entirely.',
        ],

        // ─── Returns / reversals ───
        'r01' => [
            'category' => 'validation', 'severity' => 'fix_data',
            'summary'  => 'ACH return — Insufficient funds at the recipient bank.',
            'suggested_fix' => 'The recipient\'s account couldn\'t cover any pre-existing debit. Confirm with recipient and requeue — usually safe within 24h.',
        ],
        'r02' => [
            'category' => 'validation', 'severity' => 'fix_data',
            'summary'  => 'ACH return — Recipient account closed.',
            'suggested_fix' => 'Recipient account is closed at the receiving bank. Contact the recipient for updated banking details before any retry.',
        ],
        'r03' => [
            'category' => 'validation', 'severity' => 'fix_data',
            'summary'  => 'ACH return — No account / unable to locate.',
            'suggested_fix' => 'The account number is wrong or doesn\'t exist at the routing bank. Verify with the recipient — usually a typo on the account number.',
        ],
        'r10' => [
            'category' => 'permission', 'severity' => 'fix_config',
            'summary'  => 'ACH return — Customer advises unauthorized (dispute).',
            'suggested_fix' => 'CRITICAL: The recipient disputes authorization. Do not retry without written approval from compliance.',
        ],
    ];
    foreach ($table as $code => $entry) {
        $table[$code]['code'] = (string) $code;
        $table[$code]['docs_link'] = $docs;
    }
    return $table;
}

function mercuryErrorPlaybookLookup(?string $code): array
{
    $code = trim((string) ($code ?? ''));
    // Mercury error codes are case-sensitive lowercase snake_case.
    $codeLc = strtolower($code);
    $table = mercuryErrorPlaybookTable();
    if ($codeLc !== '' && isset($table[$codeLc])) {
        return $table[$codeLc];
    }
    return [
        'code'          => $code,
        'category'      => 'unknown',
        'severity'      => 'fix_data',
        'summary'       => $code === ''
            ? 'No Mercury error code was reported by the upstream API.'
            : "Mercury error code '{$code}' is not in the local playbook.",
        'suggested_fix' => 'Inspect vendor_raw (mp_event.detail.vendor_raw) for Mercury\'s response body. The `message` field usually explains the rejection in plain English.',
        'docs_link'     => 'https://docs.mercury.com/reference/errors',
    ];
}
