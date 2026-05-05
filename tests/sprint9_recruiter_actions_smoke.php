<?php
/**
 * Sprint 9 — Recruiter leaderboard actions + Swirl branding.
 */

declare(strict_types=1);

$pass = 0; $fail = 0;
function _a(string $label, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $label\n"; }
    else       { $fail++; echo "  FAIL  $label\n"; }
}

echo "Sprint 9 — recruiter actions + Swirl\n";

$mig15 = (string) file_get_contents(__DIR__ . '/../core/migrations/015_review_flags.sql');
$mig16 = (string) file_get_contents(__DIR__ . '/../core/migrations/016_review_flags_recruiter.sql');
$flg   = (string) file_get_contents(__DIR__ . '/../api/review_flags.php');
$ai    = (string) file_get_contents(__DIR__ . '/../api/reports_ai_explain.php');
$swirl = (string) file_get_contents(__DIR__ . '/../dashboard/src/components/Swirl.jsx');
$ui    = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/StaffingReports.jsx');

echo "\nSwirl component (CoreFlux brand)\n";
_a('Swirl.jsx exports default function',  str_contains($swirl, 'export default function Swirl'));
_a('renders an inline SVG',               str_contains($swirl, '<svg'));
_a('drops a brand testid',                str_contains($swirl, 'data-testid="cf-swirl-icon"'));
_a('mimics lucide API (size/color)',      str_contains($swirl, 'size = 16') && str_contains($swirl, "color = 'currentColor'"));

echo "\nMigrations (entity_type='recruiter')\n";
_a('015 enum includes recruiter (fresh installs)', str_contains($mig15, "ENUM('placement','invoice','bill','person','recruiter')"));
_a('016 ALTERs the enum on existing tenants',      str_contains($mig16, 'ALTER TABLE review_flags') && str_contains($mig16, "'recruiter'"));

echo "\n/api/review_flags.php — recruiter type\n";
_a('entity_type whitelist includes recruiter', str_contains($flg, "['placement','invoice','bill','person','recruiter']"));

echo "\n/api/reports_ai_explain.php — recruiter branch\n";
_a('accepts entity_type=recruiter',                  str_contains($ai, "\$entityType !== 'placement' && \$entityType !== 'recruiter'"));
_a('aggregates recruiter book (placement_count)',    str_contains($ai, 'placement_count'));
_a('reads 90d hours + margin contribution',          str_contains($ai, "modify('-90 days')"));
_a('computes team median margin/hr',                 str_contains($ai, 'team_median_margin_per_hour_90d') && str_contains($ai, 'sort($perHr)'));
_a('builds CFO-style recruiter prompt',              str_contains($ai, "feature_key'       => 'reports.recruiter_explain'"));
_a('heuristic fallback: low book / low margin',      str_contains($ai, 'meaningfully below team median'));
_a('recommended_flag carried out of recruiter branch',str_contains($ai, "'reason_code' => \$recommendedFlag[0]"));

echo "\nUI — RecruiterBoard upgrades\n";
_a('imports Swirl',                          str_contains($ui, "import Swirl from '../components/Swirl'"));
_a('removes lucide Sparkles import',         !str_contains($ui, 'Sparkles,'));
_a('placement AI button uses Swirl',         str_contains($ui, 'placement-ai-btn-') && substr_count($ui, '<Swirl') >= 2);
_a('fetches open recruiter flags',           str_contains($ui, "/api/review_flags.php?entity_type=recruiter&status=open"));
_a('flagged recruiters highlighted yellow',  str_contains($ui, "flagsByRecruiter") && str_contains($ui, "flagged ? '#fef9c3' : undefined"));
_a('recruiter flag badge per row',           str_contains($ui, 'recruiter-flag-badge-'));
_a('recruiter Swirl AI button',              str_contains($ui, 'recruiter-ai-btn-'));
_a('recruiter Flag button',                  str_contains($ui, 'recruiter-flag-btn-'));
_a('Actions column on recruiter board',      str_contains($ui, 'data-testid="recruiter-board"') && substr_count($ui, '>Actions<') >= 2);

echo "\nUI — RecruiterAiPanel + RecruiterFlagModal\n";
_a('RecruiterAiPanel exists',                str_contains($ui, 'function RecruiterAiPanel'));
_a('AI panel hits the same explain endpoint',str_contains($ui, "entity_type: 'recruiter', entity_id: recruiter.recruiter_id"));
_a('AI answer rendered',                     str_contains($ui, 'data-testid="recruiter-ai-answer"'));
_a('panel shows team-median context',        str_contains($ui, 'team_median_margin_per_hour_90d'));
_a('Apply this flag wiring',                 str_contains($ui, 'data-testid="recruiter-ai-accept-flag"'));
_a('Custom flag launcher',                   str_contains($ui, 'data-testid="recruiter-ai-custom-flag"'));
_a('RecruiterFlagModal exists',              str_contains($ui, 'function RecruiterFlagModal'));
_a('flag form POSTs entity_type=recruiter',  str_contains($ui, "entity_type: 'recruiter', entity_id: recruiter.recruiter_id,"));
_a('per-flag Resolve button',                str_contains($ui, 'recruiter-flag-resolve-'));

echo "\n--- $pass assertions, $fail failed ---\n";
exit($fail === 0 ? 0 : 1);
