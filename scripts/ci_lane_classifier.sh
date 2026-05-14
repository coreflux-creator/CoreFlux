#!/usr/bin/env bash
#
# CI lane classifier — single source of truth for the 4-way parallel
# smoke test sharding. Sourced by ci_smoke_all.sh (and the lane smoke
# test) so the classification logic lives in exactly ONE place.
#
# Lanes:
#   harness  — simulation harness + module emission discipline
#   ui       — dashboard / SPA / CSV / digest / scenarios / sprint UIs
#   modules  — AP / AR / billing / time / staffing / placement / payroll
#               / treasury / payment / company / mail / plaid / gusto
#   core     — everything else (accounting engine, posting rules, replay,
#               migrations, RBAC, event registry, etc.)
#
# Usage (from a parent script):
#     . scripts/ci_lane_classifier.sh
#     lane=$(ci_classify_lane "sprint6b_dashboard_uis_smoke.php")
#     # -> "ui"
#
# Determinism: first-match-wins, top-down. Adding a new test? Pick the
# narrowest pattern that matches; don't broaden an existing pattern.

ci_classify_lane() {
    local name="$1"
    case "$name" in
        # ── harness (~25 tests) — sim harness + the entire event-driven
        #    posting + replay surface (phase_1*, sprint7b/c/e event-layer,
        #    posting rules sandbox, formula engine). These all stand or
        #    fall together with the harness invariants.
        sim_harness_*|module_emission_discipline_smoke.php|phase_2a_event_discipline_smoke.php|\
        phase_1b_*|phase_1c_*|phase_1d_*|phase_1e_*|\
        event_registry_contract_*|accounting_bank_rule_learning_*|\
        sprint7b_event_layer_*|sprint7b_formula_engine_*|sprint7b_rule_sandbox_*|\
        sprint7c1_default_rules_seed_*|sprint7c2_7d_replay_and_aliases_*|\
        sprint7e_ap_event_layer_*|sprint7e_subledger_replay_*)
            echo "harness"; return 0 ;;

        # ── ui (~45 tests) — dashboards, SPA pages, CSV, scenarios, digests ──
        csv_*|cfo_dashboard*|ci_status_*|error_boundary*|inbox_progress_badge*|kpi_notes*|\
        saved_scenarios*|scenario_compare*|scenario_presets*|scenario_share*|\
        magic_link_auth*|digest_*|export_templates*|admin_healthcheck*|\
        sprint1_login_*|sprint4_executive_*|sprint5_mobile_*|sprint5_saved_views*|\
        sprint6_mobile_*|sprint6b_*|sprint6d_*|sprint6e_*|sprint6f_*|sprint6g_*|\
        sprint6h_*|sprint6i_*|sprint6j_*|sprint6k_*|sprint7_reports_drill_*|\
        sprint7e1_*|sprint7e2_*|sprint7e3_*|sprint7g_*|sprint_distribution_polish*|\
        ai_confidence_moat_*|ai_extract_*|p1_linked_external_systems_*|\
        p2_admin_surfaces_*|p3_treasury_scenario_*)
            echo "ui"; return 0 ;;

        # ── modules (~60 tests) — business logic ──
        ap_*|ar_*|billing_*|time_*|staffing_*|placement*|placements_*|people_*|\
        recurring_contracts_*|payroll_*|treasury_*|pay_when_paid_*|companies_*|\
        dunning_*|master_tenants_*|sub_tenant_*|subtenant_*|sso_*|\
        cash_cycle_health_*|invoice_pdf_*|gusto_*|plaid_*|payment_rails*|\
        tenant_mail_*|mailer_*|mail_service_*|bugfix_*|storage_service_*|\
        m365_graph_*|sprint3_staffing_loop_*|sprint6c_*|sprint7c_treasury_*|\
        sprint8*|sprint9_*|p0_ap_bill_liquidity_*|p1_a4_time_direction_*|\
        p2_liquidity_and_auto_reverse_*|approval_reminders_daily_*)
            echo "modules"; return 0 ;;

        # ── core (~50 tests) — everything else (default lane) ──
        *)
            echo "core"; return 0 ;;
    esac
}
