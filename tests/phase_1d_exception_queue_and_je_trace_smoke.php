<?php
/**
 * Phase 1d + JE Trace smoke (Live Books Rails, 2026-02-14).
 *
 * Pins:
 *   • Migration 039 — exception_queue table + v_unified_exception_queue view.
 *   • core/exception_queue.php — public helper surface.
 *   • Posting engine surfaces `event.error` exceptions on posting failure.
 *   • API endpoints exceptions + je_trace exist with correct shape.
 *   • JeTracePane.jsx renders the source + lineage + interpretations.
 *   • JournalEntryDetail.jsx imports + mounts the trace pane.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Migration 039\n";
$mig = $read(__DIR__ . '/../core/migrations/039_unified_exception_queue.sql');
$a('creates exception_queue',                  str_contains($mig, 'CREATE TABLE IF NOT EXISTS exception_queue'));
$a('5-state status enum',                      str_contains($mig, "ENUM('open','snoozed','resolved','dismissed')"));
$a('4-level severity enum',                    str_contains($mig, "ENUM('info','warn','high','critical')"));
$a('polymorphic subject pointer',              str_contains($mig, 'subject_type VARCHAR(60)') && str_contains($mig, 'subject_id   BIGINT'));
$a('payload JSON for module context',          str_contains($mig, 'payload     JSON'));
$a('assignment + snooze + resolution columns', str_contains($mig, 'assigned_user_id') && str_contains($mig, 'snoozed_until') && str_contains($mig, 'resolved_at'));
$a('view drops + recreates (CREATE OR REPLACE pattern)',
    str_contains($mig, 'DROP VIEW IF EXISTS v_unified_exception_queue') && str_contains($mig, 'CREATE VIEW v_unified_exception_queue'));
$a('view fans in queue + ai + event_error',    str_contains($mig, "'queue'") && str_contains($mig, "'ai_interpretation'") && str_contains($mig, "'event_error'"));
$a('view severity from confidence (high <0.50, warn <0.75, else info)',
    str_contains($mig, "WHEN aai.confidence < 0.50 THEN 'high'") &&
    str_contains($mig, "WHEN aai.confidence < 0.75 THEN 'warn'"));
$a("event_error feed reads status='failed'",   str_contains($mig, "WHERE ae.status = 'failed'"));

echo "\nHelper library\n";
$lib = $read(__DIR__ . '/../core/exception_queue.php');
foreach ([
    'exceptionOpen','exceptionList','exceptionResolve','exceptionSnooze',
    'exceptionDismiss','exceptionAssign','exceptionSummary',
] as $fn) {
    $a("library defines {$fn}",                str_contains($lib, "function {$fn}("));
}
$a('table-missing graceful return',            str_contains($lib, '_exceptionQueueTableExists'));
$a('severity sanitized to allowed list',       str_contains($lib, "['info','warn','high','critical']"));
$a('list orders by severity then surfaced_at', str_contains($lib, "FIELD(severity, 'critical','high','warn','info')") && str_contains($lib, 'surfaced_at DESC'));
$a('list filter whitelist',                    str_contains($lib, "['source','severity','subject_type','feed']"));

echo "\nPosting engine wire-in\n";
$proc = $read(__DIR__ . '/../core/posting_engine/process.php');
$a('engine requires exception_queue.php',      str_contains($proc, "require_once __DIR__ . '/../exception_queue.php'"));
$a('engine opens event.error on post failure', str_contains($proc, "exceptionOpen(\$tenantId, 'event.error'"));
$a('engine severity=high on post failure',     str_contains($proc, "'severity'         => 'high'"));
$a('engine wraps exception write best-effort', str_contains($proc, "} catch (\\Throwable \$_) { /* best-effort */ }"));

echo "\nExceptions API\n";
$api = $read(__DIR__ . '/../api/accounting/exceptions.php');
$a('GET ?summary=1 returns severity x feed',   str_contains($api, "'summary' => exceptionSummary"));
$a('GET filter by feed',                       str_contains($api, "'feed'"));
$a('POST without action = open',               str_contains($api, "POST && \$action === ''") || str_contains($api, "'POST' && \$action === ''"));
$a('POST ?action=resolve',                     str_contains($api, "\$action === 'resolve'") && str_contains($api, 'exceptionResolve'));
$a('POST ?action=snooze (requires until_iso)', str_contains($api, 'until_iso required'));
$a('POST ?action=dismiss',                     str_contains($api, "\$action === 'dismiss'"));
$a('POST ?action=assign (requires user_id)',   str_contains($api, 'user_id required'));

echo "\nJE Trace API\n";
$jt = $read(__DIR__ . '/../api/accounting/je_trace.php');
$a('je_trace requires lineage + interp libs',  str_contains($jt, "event_lineage.php") && str_contains($jt, "ai_interpretation.php"));
$a('walks via accounting_subledger_links',     str_contains($jt, 'accounting_subledger_links sl'));
$a('returns ancestors + descendants + interps', str_contains($jt, "'ancestors'") && str_contains($jt, "'descendants'") && str_contains($jt, "'interpretations'"));
$a('joins ai_interpretations on event_id IN(...)', str_contains($jt, 'event_id IN ({$placeholders})') || str_contains($jt, 'event_id IN ('));

echo "\nJE Trace UI\n";
$ui = $read(__DIR__ . '/../modules/accounting/ui/JeTracePane.jsx');
$a('lazy-loads on toggle open',                str_contains($ui, 'open ? `/api/accounting/je_trace.php?je_id=${jeId}` : null'));
$a('renders source/ancestor/descendant chain', str_contains($ui, 'kind="ancestor"') && str_contains($ui, 'kind="source"') && str_contains($ui, 'kind="descendant"'));
$a('shows AI vs rule vs human icon',           str_contains($ui, "row.proposed_by?.startsWith('ai:')") && str_contains($ui, "row.proposed_by?.startsWith('posting_rule:')"));
$a('renders proposed JE lines (Dr/Cr table)',  str_contains($ui, 'row.proposed_je?.lines?.length > 0'));
$a('surfaces registry hint per interpretation',str_contains($ui, 'row.typical_accounting_hint'));
$a('renders status color per state',           str_contains($ui, 'accepted:') && str_contains($ui, 'proposed:') && str_contains($ui, 'overridden:') && str_contains($ui, 'rejected:'));
$a('section testid',                           str_contains($ui, 'data-testid="je-trace-pane"'));
$a('toggle testid',                            str_contains($ui, 'data-testid="je-trace-toggle"'));

$jdetail = $read(__DIR__ . '/../modules/accounting/ui/JournalEntryDetail.jsx');
$a('JournalEntryDetail imports JeTracePane',   str_contains($jdetail, "import JeTracePane from './JeTracePane'"));
$a('JournalEntryDetail mounts <JeTracePane>',  str_contains($jdetail, '<JeTracePane jeId={entry.id}'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
