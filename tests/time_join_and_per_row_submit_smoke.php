<?php
/**
 * Smoke — time-entries cross-tenant JOIN fix + per-row Submit in MyTime.
 *
 * Operator complaints addressed:
 *   "time not linked to placement or person?" — the time entries list
 *      JOINed people + placements on the timesheet's tenant_id, but
 *      both modules are `'shared'` by default → sub-tenants would
 *      silently miss the JOIN and surface "—" for name + placement.
 *   "can't submit a single timesheet, only all at once" — MyTime had
 *      no per-entry Submit button; everything funneled through the
 *      staffing Submit-Week path. Per-entry submit endpoint already
 *      exists (entries.php?action=submit&id=N) — we just never
 *      surfaced it.
 *
 * Fixes locked in:
 *   - modules/time/lib/time.php loads core/sub_tenants.php, resolves
 *     `effectiveTenantIdForModule('people' | 'placements')`, binds
 *     them to the JOIN tenant (timeEntriesList + period bundle build).
 *   - modules/time/ui/MyTime.jsx surfaces a drafts panel with per-row
 *     Submit + "Submit all N drafts" CTA.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$ROOT = realpath(__DIR__ . '/..');
$timeLib = (string) file_get_contents("{$ROOT}/modules/time/lib/time.php");
$myTime  = (string) file_get_contents("{$ROOT}/modules/time/ui/MyTime.jsx");

echo "\n1. timeEntriesList JOIN uses effective module tenant\n";
$a('lib loads core/sub_tenants.php',
   str_contains($timeLib, "require_once __DIR__ . '/../../../core/sub_tenants.php';"));
$a('binds people_tid + placements_tid into params before scopedQuery',
   str_contains($timeLib, "\$params['people_tid']     = effectiveTenantIdForModule('people')     ?? currentTenantId();")
   && str_contains($timeLib, "\$params['placements_tid'] = effectiveTenantIdForModule('placements') ?? currentTenantId();"));
$a('people JOIN binds :people_tid (not te.tenant_id)',
   str_contains($timeLib, 'LEFT JOIN people pe     ON pe.id = te.person_id    AND pe.tenant_id = :people_tid'));
$a('placement JOIN binds :placements_tid (not te.tenant_id)',
   str_contains($timeLib, 'LEFT JOIN placements pl ON pl.id = te.placement_id AND pl.tenant_id = :placements_tid'));
$a('rationale comment present in code',
   str_contains($timeLib, 'silently misses every row'));

echo "\n2. period bundle-build JOIN also fixed\n";
$a('period bundle SELECT binds placements_tid via param',
   str_contains($timeLib, "'pid' => \$periodId, 'placements_tid' => effectiveTenantIdForModule('placements') ?? currentTenantId()"));
$a('period bundle JOIN no longer references te.tenant_id for placements',
   substr_count($timeLib, 'pl.tenant_id = te.tenant_id') === 0);

echo "\n3. MyTime per-row Submit UI\n";
$a('derives drafts from entries.filter(status === draft)',
   str_contains($myTime, "const drafts = entries.filter(e => e.status === 'draft');"));
$a('submitBusy state guards both single and bulk paths',
   str_contains($myTime, 'const [submitBusy, setSubmitBusy] = useState(null);'));
$a('submitOne POSTs to per-entry submit action',
   str_contains($myTime, 'api.post(`/modules/time/api/entries.php?action=submit&id=${entryId}`, {})'));
$a('submitAll loops via Promise.allSettled (partial-failure tolerant)',
   str_contains($myTime, 'Promise.allSettled(')
   && str_contains($myTime, 'drafts.map(e => api.post(`/modules/time/api/entries.php?action=submit&id=${e.id}`'));
$a('submitAll requires confirmation',
   str_contains($myTime, 'if (!confirm(`Submit all ${drafts.length} draft entr'));
$a('drafts panel only renders when drafts > 0',
   str_contains($myTime, '{drafts.length > 0 && ('));
$a('drafts panel has testid + count testid',
   str_contains($myTime, 'data-testid="time-drafts-panel"')
   && str_contains($myTime, 'data-testid="time-drafts-count"'));
$a('per-row Submit button testid uses entry id',
   str_contains($myTime, 'data-testid={`time-draft-submit-${e.id}`}'));
$a('Submit-all CTA testid present',
   str_contains($myTime, 'data-testid="time-drafts-submit-all"'));
$a('drafts row falls back to "Person #N" when name JOIN misses (defensive)',
   str_contains($myTime, 'e.person_id ? `Person #${e.person_id}`'));
$a('drafts row falls back to "Placement #N" when placement title is null',
   str_contains($myTime, 'e.placement_title || `Placement #${e.placement_id}`'));

echo "\n4. PHP syntax\n";
$out = []; $rc = 0;
exec('php -l ' . escapeshellarg("{$ROOT}/modules/time/lib/time.php") . ' 2>&1', $out, $rc);
$a("php -l modules/time/lib/time.php", $rc === 0, implode("\n", $out));

echo "\n=========================================\n";
echo "Time JOIN + per-row submit smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
