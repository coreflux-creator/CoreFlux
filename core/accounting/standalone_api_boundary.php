<?php
declare(strict_types=1);

require_once __DIR__ . '/standalone_boundary.php';

/** Classify the server-resolved PHP entry script, never the client URL. */
function coreAccountingAllowsPublicApiScript(?string $scriptFilename, string $root, string $environment): bool
{
    if ($environment !== 'coreaccounting') return true;
    $script = $scriptFilename !== null ? realpath($scriptFilename) : false;
    $base = realpath($root);
    if ($script === false || $base === false) return false;
    $script = str_replace('\\', '/', $script);
    $base = rtrim(str_replace('\\', '/', $base), '/');
    if (!str_starts_with($script, $base . '/')) return false;
    $relative = substr($script, strlen($base) + 1);

    if (preg_match('~^modules/([a-z][a-z0-9_-]*)/api/[^/]+\.php$~', $relative, $match)) {
        return coreAccountingAllowsModule($match[1], $environment);
    }
    if (str_starts_with($relative, 'core/api/')) {
        return in_array($relative, [
            'core/api/ai_suggestions.php',
            'core/api/payment_rails.php',
        ], true);
    }
    if (preg_match('~^[^/]+\.php$~', $relative)) {
        return in_array($relative, [
            'auditor.php', 'dashboard.php', 'forgot_password.php', 'login.php',
            'logout.php', 'qbo-connect.php', 'qbo-oauth-callback.php',
            'reset_password.php', 'session.php', 'signup.php', 'spa.php',
            'switch_tenant.php',
        ], true);
    }
    if (!str_starts_with($relative, 'api/')) return false;
    if ($relative === 'api/index.php') return true;
    if (preg_match('~^api/coreone/v1/(bills|invoices|journals|reports)\.php$~', $relative)) {
        return true;
    }

    if (preg_match('~^api/(accounting|ap|auth|billing|qbo|sso|treasury|zoho_books)/[a-z][a-z0-9_]*\.php$~', $relative)) {
        return true;
    }
    if (str_starts_with($relative, 'api/admin/')) {
        return in_array(substr($relative, strlen('api/')), [
            'admin/accounting/outbox.php',
            'admin/accounting_coa_coverage.php',
            'admin/accounting_sync_dashboard.php',
            'admin/accounting_sync_reconcile.php',
            'admin/auditor_tokens.php',
            'admin/csv_import_history.php',
            'admin/csv_mapping_presets.php',
            'admin/fsc_health.php',
            'admin/mail_health.php',
            'admin/mail_outbox_show.php',
            'admin/mail_senders.php',
            'admin/mail_suppressions.php',
            'admin/mail_test_send.php',
            'admin/manageable_tenants.php',
            'admin/membership_access.php',
            'admin/membership_audit.php',
            'admin/membership_drift.php',
            'admin/memberships.php',
            'admin/permission_profiles.php',
            'admin/rbac_bridge_health.php',
            'admin/reports/log_drilldown.php',
            'admin/reports/save_snapshot.php',
            'admin/roles_reference.php',
            'admin/run_accounting_outbox_now.php',
            'admin/schema_health.php',
            'admin/user_effective_permissions.php',
        ], true);
    }

    return in_array(substr($relative, strlen('api/')), [
        'accounting.php', 'accounting_events.php', 'active_entity.php', 'active_persona.php',
        'ai_categorization_rules.php', 'ap_bill_liquidity_impact.php', 'ap_bill_replay.php',
        'audit_anomaly.php', 'audit_log.php', 'bank_transaction_dedupe.php',
        'billing_invoice_replay.php', 'books_health.php', 'coreone_credentials.php',
        'custom_field_definitions.php', 'custom_field_layouts.php', 'dimensional_pnl.php',
        'evidence_upload_url.php', 'export_templates.php', 'financial_state.php',
        'gl_detail.php', 'je_auto_reverse.php', 'kpi_notes.php', 'line_ai_suggest.php',
        'liquidity_forecast.php', 'mail_connections.php', 'mail_settings.php',
        'mercury_accounts.php', 'mercury_connection.php', 'mercury_reconciliation.php',
        'mercury_transactions.php', 'missing_dimensions.php', 'plaid_auth_pull.php',
        'plaid_bank_link.php', 'plaid_dedupe.php', 'plaid_diagnostics.php',
        'plaid_exchange.php', 'plaid_items.php', 'plaid_link_token.php',
        'plaid_sync_transactions.php', 'posting_rules_replay.php', 'posting_rules_seed.php',
        'qbo.php', 'report_builder.php', 'reports_ai_explain.php', 'reports_finance.php',
        'review_flags.php', 'sso_config.php', 'sub_tenant_consolidated_reports.php',
        'tax_form_export.php', 'tax_mapping_ai_suggest.php', 'tax_mappings.php',
        'tenant_mail_branding.php', 'tenant_modules.php', 'tenants.php',
        'transactions_to_review.php', 'treasury_cash_position.php',
        'treasury_recommendations.php', 'treasury_scenario.php',
        'treasury_scenario_compare.php', 'treasury_scenario_presets.php',
        'treasury_scenario_share.php', 'users.php', 'workflow.php', 'zoho_books.php',
    ], true);
}

/** Deny unsupported direct PHP entrypoints before their own early exits. */
function coreAccountingEnforcePublicApiScript(?string $scriptFilename, string $root): void
{
    if (coreAccountingAllowsPublicApiScript($scriptFilename, $root, (string) getenv('COREFLUX_ENV'))) {
        return;
    }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo '{"error":"Not found","status":404}';
    exit;
}
