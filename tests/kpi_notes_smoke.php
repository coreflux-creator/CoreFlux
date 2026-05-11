<?php
/**
 * Smoke: Tenant KPI annotations on the Cash Cycle Health tile.
 *
 * Static contract checks (no DB):
 *   - migration 029_tenant_kpi_notes.sql defines table with right shape
 *   - /api/kpi_notes.php has GET / POST / POST?action=delete + RBAC + 280-char cap
 *   - React KpiNote component handles read-only / editable / add-note states
 *   - CashCycleHealthTile wires a KpiNote under each of the 4 stats
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

echo "Migration: core/migrations/029_tenant_kpi_notes.sql\n";
$migPath = __DIR__ . '/../core/migrations/029_tenant_kpi_notes.sql';
$mig = (string) file_get_contents($migPath);
$a('migration file exists',                            is_file($migPath));
$a('creates tenant_kpi_notes table',                   str_contains($mig, 'CREATE TABLE IF NOT EXISTS tenant_kpi_notes'));
$a('note_key VARCHAR(64)',                             str_contains($mig, 'note_key  VARCHAR(64)  NOT NULL'));
$a('note_text capped at 280 chars',                    str_contains($mig, 'note_text VARCHAR(280) NOT NULL'));
$a('unique (tenant_id, note_key)',                     str_contains($mig, 'UNIQUE KEY uq_tkn_tenant_key (tenant_id, note_key)'));
$a('tracks updated_by_user_id',                        str_contains($mig, 'updated_by_user_id'));
$a('idempotent (IF NOT EXISTS)',                       str_contains($mig, 'CREATE TABLE IF NOT EXISTS'));

echo "\nAPI: /api/kpi_notes.php\n";
$apiPath = __DIR__ . '/../api/kpi_notes.php';
$apiSrc  = (string) file_get_contents($apiPath);
$a('api file exists + parses',                         is_file($apiPath) && (int) shell_exec('php -l ' . escapeshellarg($apiPath) . ' >/dev/null 2>&1; echo $?') === 0);
$a('read permission billing.view',                     str_contains($apiSrc, "RBAC::requirePermission(\$user, 'billing.view')"));
$a('canWrite() requires manager/admin role',           str_contains($apiSrc, "in_array(\$role, ['admin', 'manager']"));
$a("GET returns notes + can_write",                    str_contains($apiSrc, "'notes' => \$notes, 'can_write' => \$canWrite(\$user)"));
$a('POST upsert via ON DUPLICATE KEY UPDATE',          str_contains($apiSrc, 'ON DUPLICATE KEY UPDATE note_text = VALUES(note_text)'));
$a('POST clamps text length to 280',                   str_contains($apiSrc, 'strlen($text) > 280'));
$a('POST sanitizes key to [a-z0-9_]',                  str_contains($apiSrc, "preg_replace('/[^a-z0-9_]/', '', strtolower"));
$a('empty text triggers delete',                       str_contains($apiSrc, "// Empty text = delete the note"));
$a("POST ?action=delete supported",                    str_contains($apiSrc, "\$method === 'POST' && \$action === 'delete'"));
$a('write rejects non-managers with 403',              str_contains($apiSrc, "api_error('Manager role required to write KPI notes', 403)"));
$a('GET tolerates missing table (try/catch)',          str_contains($apiSrc, '/* table not migrated yet — empty notes */'));

echo "\nReact: KpiNote.jsx component\n";
$kpPath = __DIR__ . '/../dashboard/src/components/KpiNote.jsx';
$kp = (string) file_get_contents($kpPath);
$a('component file exists',                            is_file($kpPath));
$a('handles read-only "view" state',                   str_contains($kp, 'data-testid={`kpi-note-${noteKey}`}'));
$a('add-note button for managers when empty',          str_contains($kp, 'kpi-note-add-${noteKey}') && str_contains($kp, "if (!canWrite) return null"));
$a('renders nothing for line staff w/ no note',        str_contains($kp, "if (!canWrite) return null"));
$a('edit mode shows input + save + cancel',            str_contains($kp, 'kpi-note-save-${noteKey}') && str_contains($kp, 'kpi-note-cancel-${noteKey}'));
$a('keyboard shortcuts Enter/Escape',                  str_contains($kp, "if (e.key === 'Enter')") && str_contains($kp, "if (e.key === 'Escape')"));
$a('clamps input to 280 chars',                        str_contains($kp, 'maxLength={280}'));
$a('POSTs to /api/kpi_notes.php',                      str_contains($kp, "api.post('/api/kpi_notes.php'"));

echo "\nCashCycleHealthTile wires notes into all 4 stats\n";
$tileSrc = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/CashCycleHealthTile.jsx');
$a('imports KpiNote',                                  str_contains($tileSrc, "import KpiNote from '../components/KpiNote'"));
$a('fetches /api/kpi_notes.php',                       str_contains($tileSrc, "useApi('/api/kpi_notes.php')"));
$a('hydrates notes + can_write into state',            str_contains($tileSrc, 'setNotes(notesData.notes || {})') && str_contains($tileSrc, 'setCanWriteNotes(Boolean(notesData.can_write))'));
$a('handleNoteSaved updates local cache',              str_contains($tileSrc, 'const handleNoteSaved = useCallback'));
foreach (['cash_cycle_dso', 'cash_cycle_ar', 'cash_cycle_pwp_awaiting', 'cash_cycle_pwp_released'] as $k) {
    $a("Stat passes noteKey={$k}",                     str_contains($tileSrc, "noteKey=\"{$k}\""));
}
$a('Stat renders <KpiNote/> when noteKey present',     str_contains($tileSrc, '{noteKey && <KpiNote'));

echo "\n.deploy-version sentinel exists for kpi_notes\n";
$dv = (string) file_get_contents(__DIR__ . '/../.deploy-version');
$a('mentions tenant_kpi_notes migration OR kpi_notes',
   str_contains($dv, '029_tenant_kpi_notes.sql') || str_contains($dv, 'kpi_notes')
);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
