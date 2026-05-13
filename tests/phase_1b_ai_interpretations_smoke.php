<?php
/**
 * Phase 1b — AI Interpretation Records smoke (Live Books Rails, 2026-02-14).
 *
 * Pins:
 *   • Migration 037 creates the right table shape.
 *   • core/ai_interpretation.php exposes the helper surface and degrades
 *     gracefully when the table is missing.
 *   • core/posting_engine/process.php auto-records an 'accepted'
 *     interpretation row for every rule-derived posting (the
 *     deterministic baseline Phase 2 AI will compete with).
 *   • api/accounting/ai_interpretations.php provides list + review.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Migration 037\n";
$mig = $read(__DIR__ . '/../core/migrations/037_accounting_ai_interpretations.sql');
$a('creates accounting_ai_interpretations',     str_contains($mig, 'CREATE TABLE IF NOT EXISTS accounting_ai_interpretations'));
$a('FK-shaped event_id column',                 str_contains($mig, 'event_id  BIGINT UNSIGNED NOT NULL'));
$a('confidence DECIMAL(4,3) default 1.000',     str_contains($mig, 'confidence       DECIMAL(4,3) NOT NULL DEFAULT 1.000'));
$a('proposed_je_json + evidence_json JSON',     str_contains($mig, 'proposed_je_json JSON NOT NULL') && str_contains($mig, 'evidence_json    JSON'));
$a('status enum with 5 states',                 str_contains($mig, "ENUM('proposed','accepted','overridden','rejected','superseded')"));
$a('reviewer trail columns',                    str_contains($mig, 'reviewer_user_id') && str_contains($mig, 'review_disposition'));
$a('typical_accounting_hint snapshot',          str_contains($mig, 'typical_accounting_hint TEXT'));
$a('index for review queue',                    str_contains($mig, 'idx_aai_review_q    (tenant_id, requires_review, status)'));

echo "\nHelper library\n";
$lib = $read(__DIR__ . '/../core/ai_interpretation.php');
foreach ([
    'aiInterpretationRecord',
    'aiInterpretationLatestForEvent',
    'aiInterpretationAccept',
    'aiInterpretationOverride',
    'aiInterpretationReject',
    'aiInterpretationListPendingReview',
] as $fn) {
    $a("library defines {$fn}",                 str_contains($lib, "function {$fn}("));
}
$a('library skips when table is missing',       str_contains($lib, '_aiInterpretationTableExists'));
$a('confidence clamped to 0..1',                str_contains($lib, '$confidence < 0.0') && str_contains($lib, '$confidence > 1.0'));
$a('requires_review defaults true below 0.75',  str_contains($lib, "(\$confidence < 0.75)"));
$a('accept supersedes prior accepted rows',     str_contains($lib, "status = 'superseded'") && str_contains($lib, "status = 'accepted'"));

echo "\nPosting engine auto-record\n";
$proc = $read(__DIR__ . '/../core/posting_engine/process.php');
$a('engine requires ai_interpretation.php',     str_contains($proc, "require_once __DIR__ . '/../ai_interpretation.php'"));
$a('engine snapshots event_registry hint',      str_contains($proc, 'eventRegistryGet((string) $event[\'event_type\'])'));
$a('engine records posting_rule:<id>',          str_contains($proc, "'posting_rule:' . (int) \$rule['id']"));
$a('engine records confidence 1.0 + accepted',  str_contains($proc, "'confidence'        => 1.000") && str_contains($proc, "'status'            => 'accepted'"));
$a('engine includes JE lines in proposal',      str_contains($proc, "'account_code' => \$l['account_code']"));
$a('engine logs failure best-effort only',      str_contains($proc, '[ai-interpretation] record failed'));

echo "\nAPI\n";
$api = $read(__DIR__ . '/../api/accounting/ai_interpretations.php');
$a('GET by event_id',                           str_contains($api, "event_id = :e"));
$a('GET ?latest=1',                             str_contains($api, "latestOnly"));
$a('GET ?pending_review=1',                     str_contains($api, "aiInterpretationListPendingReview"));
$a('POST ?action=accept',                       str_contains($api, "\$action === 'accept'") && str_contains($api, 'aiInterpretationAccept'));
$a('POST ?action=override (note required)',     str_contains($api, "note required for override"));
$a('POST ?action=reject (reason required)',     str_contains($api, "\$action === 'reject'") && str_contains($api, 'reason required'));
$a('graceful 404 on missing table',             str_contains($api, 'not yet migrated'));

echo "\nCFO hint surfacing\n";
$cfo = $read(__DIR__ . '/../api/cfo_annotate.php');
$a('CFO annotator loads event_registry',        str_contains($cfo, "require_once __DIR__ . '/../core/event_registry.php'"));
$a('Widget→event_types map present',            str_contains($cfo, 'widgetEventMap') && str_contains($cfo, "'finance.dso'"));
$a('registry_hints injected into AI context',   str_contains($cfo, "'registry_hints' => \$registryHints"));
$a('Dr/Cr hint instruction conditional',        str_contains($cfo, 'typical Dr/Cr hints supplied'));
$a('registry_hints surfaced in api_ok',         str_contains($cfo, "'registry_hints'  => \$registryHints"));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
