<?php
/**
 * P1 follow-on smoke — Linked External Systems mini-panel.
 *
 * Asserts:
 *   - Reusable component LinkedExternalSystemsPanel.jsx renders mappings
 *     with status palette + direction labels + last-synced + empty state.
 *   - Wired into PersonDetail as a new "connections" tab (route + nav entry).
 *   - Wired into Company DirectoryDetail as an inline panel below contacts.
 *   - Component reads /api/integrations/mappings.php?action=list_for_internal
 *     (no new backend needed — endpoint already shipped in Slice A2).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');

echo "Component — LinkedExternalSystemsPanel.jsx\n";
$path = "{$ROOT}/dashboard/src/components/LinkedExternalSystemsPanel.jsx";
$src = (string) file_get_contents($path);
$assert('component file exists',                   strlen($src) > 0);
$assert('default export',
    strpos($src, 'export default function LinkedExternalSystemsPanel') !== false);
$assert('reads list_for_internal endpoint (no new backend)',
    strpos($src, '/api/integrations/mappings.php?action=list_for_internal') !== false);
$assert('encodes entityType + internalId',
    strpos($src, 'encodeURIComponent(entityType)') !== false
    && strpos($src, 'encodeURIComponent(internalId)') !== false);
$assert('panel container testid template',
    strpos($src, 'data-testid={`linked-systems-panel-${entityType}-${internalId}`}') !== false);
$assert('per-source row testid template',
    strpos($src, 'data-testid={`linked-systems-row-${m.source_system}`}') !== false);
$assert('per-source status testid template',
    strpos($src, 'data-testid={`linked-systems-status-${m.source_system}`}') !== false);
$assert('renders empty state when no mappings',
    strpos($src, 'data-testid="linked-systems-empty"') !== false
    && strpos($src, 'Not currently linked to any external system') !== false);
$assert('palette covers all 4 sync_status values',
    strpos($src, 'ok:') !== false
    && strpos($src, 'stale:') !== false
    && strpos($src, 'error:') !== false
    && strpos($src, 'deleted_in_source:') !== false);
$assert('direction label map covers pull/push/two_way/off',
    strpos($src, "pull:    'Pull only'") !== false
    && strpos($src, "push:    'Push only'") !== false
    && strpos($src, "two_way: 'Two-way'") !== false
    && strpos($src, "off:     'Disabled'") !== false);
$assert('JobDiva label map entry',                  strpos($src, "jobdiva: 'JobDiva'") !== false);
$assert('renders external_id in monospace',
    strpos($src, "fontFamily: 'ui-monospace, monospace', fontSize: 12 }}>\n                    {m.external_id}") !== false);
$assert('table testid',                             strpos($src, 'data-testid="linked-systems-table"') !== false);
$assert('handles loading + error states',
    strpos($src, 'data-testid="linked-systems-loading"') !== false
    && strpos($src, 'data-testid="linked-systems-error"') !== false);

echo "\nWiring — PersonDetail (Connections tab)\n";
$pd = (string) file_get_contents("{$ROOT}/modules/people/ui/PersonDetail.jsx");
$assert('imports LinkedExternalSystemsPanel',
    strpos($pd, "import LinkedExternalSystemsPanel from '../../../dashboard/src/components/LinkedExternalSystemsPanel'") !== false);
$assert('Connections tab in TABS array',
    strpos($pd, "{ slug: 'connections',label: 'Connections' }") !== false);
$assert('mounts /connections route with entityType=person',
    strpos($pd, '<Route path="connections" element={<LinkedExternalSystemsPanel entityType="person" internalId={person.id} />} />') !== false);

echo "\nWiring — DirectoryDetail (Company)\n";
$dd = (string) file_get_contents("{$ROOT}/modules/people/ui/DirectoryModule.jsx");
$assert('imports LinkedExternalSystemsPanel',
    strpos($dd, "import LinkedExternalSystemsPanel from '../../../dashboard/src/components/LinkedExternalSystemsPanel'") !== false);
$assert('renders panel with entityType=company below contacts',
    strpos($dd, '<LinkedExternalSystemsPanel entityType="company" internalId={c.id} />') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
