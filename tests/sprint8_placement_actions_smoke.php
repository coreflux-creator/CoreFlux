<?php
/**
 * Sprint 8 — Actionable placement margin: review flags + AI insight.
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
function _a(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $label\n"; }
    else       { $fail++; echo "  FAIL  $label\n"; }
}

echo "Sprint 8 — actions on placement margin rows\n";

$mig = (string) file_get_contents(__DIR__ . '/../core/migrations/015_review_flags.sql');
$flg = (string) file_get_contents(__DIR__ . '/../api/review_flags.php');
$ai  = (string) file_get_contents(__DIR__ . '/../api/reports_ai_explain.php');
$ui  = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/StaffingReports.jsx');

echo "\nMigration 015 — review_flags\n";
_a('CREATE TABLE review_flags',                  str_contains($mig, 'CREATE TABLE IF NOT EXISTS review_flags'));
_a('polymorphic entity_type',                    str_contains($mig, "entity_type     ENUM('placement','invoice','bill','person'"));
_a('reason_code + severity + status enums',      str_contains($mig, 'severity') && str_contains($mig, 'status') && str_contains($mig, "'open','resolved','dismissed'"));
_a('AI fields (summary / confidence / source)',  str_contains($mig, 'ai_summary') && str_contains($mig, 'ai_confidence') && str_contains($mig, 'ai_source'));
_a('indexed by entity + status',                 str_contains($mig, 'idx_rf_tenant_entity') && str_contains($mig, 'idx_rf_tenant_status'));

echo "\n/api/review_flags.php\n";
_a('manager+ gate',                              str_contains($flg, "['master_admin', 'tenant_admin', 'admin', 'manager']"));
_a('GET filters by entity_type + status',        str_contains($flg, 'entity_type = :et') && str_contains($flg, 'rf.status = :s'));
_a('POST idempotent on (entity, reason)',        str_contains($flg, "AND status = 'open' LIMIT 1") && str_contains($flg, 'updated'));
_a('PATCH resolves / dismisses',                 str_contains($flg, "status must be resolved or dismissed"));
_a('DELETE soft-dismisses',                      str_contains($flg, "SET status = 'dismissed'"));
_a('joins users for actor names',                str_contains($flg, 'flagged_by_name') && str_contains($flg, 'resolved_by_name'));
_a('reason_code / severity validated',           str_contains($flg, '_RF_SEVERITIES') && str_contains($flg, '_RF_ENTITY_TYPES'));

echo "\n/api/reports_ai_explain.php\n";
_a('manager+ gate',                              str_contains($ai, "['master_admin','tenant_admin','admin','manager']"));
_a('placement context loaded',                   str_contains($ai, 'FROM placements p') && str_contains($ai, 'pe.preferred_name'));
_a('LLM call via aiAsk()',                       str_contains($ai, 'aiAsk([') && str_contains($ai, "'feature_class'     => 'narrative'"));
_a('CFO-style staffing system prompt',           str_contains($ai, 'CFO co-pilot'));
_a('heuristic fallback when AI disabled',        str_contains($ai, 'heuristic') && str_contains($ai, "'source'           => 'heuristic'"));
_a('flag recommendation includes reason+severity', str_contains($ai, "'reason_code' => \$recommendedFlag[0]") && str_contains($ai, "'severity'    => \$marginPerHr < 0"));
_a('signals: low_margin, stale, missing data',   str_contains($ai, "'low_margin'") && str_contains($ai, "'stale_unsigned_timesheet'") && str_contains($ai, "'missing_data'"));

echo "\nUI: PlacementMarginTable upgrades\n";
_a('imports api (for POST/PATCH)',               str_contains($ui, "import { api, useApi }"));
_a('fetches open flags scoped to placement',     str_contains($ui, "/api/review_flags.php?entity_type=placement&status=open"));
_a('flagged rows highlighted',                   str_contains($ui, "flagged ? '#fef9c3' : undefined"));
_a('flag badge per row',                         str_contains($ui, 'placement-flag-badge-'));
_a('per-row AI button',                          str_contains($ui, 'placement-ai-btn-'));
_a('per-row Flag button',                        str_contains($ui, 'placement-flag-btn-'));
_a('Actions column header rendered',             str_contains($ui, '>Actions<'));

echo "\nUI: PlacementAiPanel modal\n";
_a('panel exists',                               str_contains($ui, 'function PlacementAiPanel'));
_a('hits /api/reports_ai_explain.php',           str_contains($ui, "api.post('/api/reports_ai_explain.php'"));
_a('renders AI answer',                          str_contains($ui, 'data-testid="placement-ai-answer"'));
_a('shows source + confidence',                  str_contains($ui, 'source: <strong>') && str_contains($ui, 'confidence:'));
_a('recommended flag yellow card',               str_contains($ui, 'data-testid="placement-ai-recommended-flag"'));
_a('"Apply this flag" one-click button',         str_contains($ui, 'data-testid="placement-ai-accept-flag"'));
_a('Custom-flag and Open-placement actions',     str_contains($ui, 'placement-ai-custom-flag') && str_contains($ui, 'placement-ai-open-placement'));

echo "\nUI: PlacementFlagModal\n";
_a('flag modal exists',                          str_contains($ui, 'function PlacementFlagModal'));
_a('renders existing flags list',                str_contains($ui, 'placement-flag-existing-'));
_a('per-flag Resolve button',                    str_contains($ui, 'placement-flag-resolve-'));
_a('reason-code dropdown',                       str_contains($ui, 'data-testid="placement-flag-reason"'));
_a('severity dropdown',                          str_contains($ui, 'data-testid="placement-flag-severity"'));
_a('notes textarea',                             str_contains($ui, 'data-testid="placement-flag-notes"'));
_a('submit button POSTs to review_flags',        str_contains($ui, "api.post('/api/review_flags.php'"));

echo "\n--- $pass assertions, $fail failed ---\n";
exit($fail === 0 ? 0 : 1);
