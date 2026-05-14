<?php
/**
 * tests/timesheet_csv_attachments_smoke.php
 *
 * Validates the 2026-02 release that ties together:
 *   - TimesheetWeek CSV-Import discoverability (link to shared importer)
 *   - Polymorphic Evidence Attachments (generic upload-url + UI component)
 *   - Wiring of EvidenceAttachments onto TimesheetWeek, InvoiceDetail, BillDetail
 *   - FSC Health endpoint + CFO Dashboard collapsible "Cache Health" section
 *
 * Lane: ui (CSV + dashboard chrome). Static contract assertions only.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "  ok    {$name}\n"; }
    else     { $fail++; echo "  FAIL  {$name}\n"; }
};

$ROOT = realpath(__DIR__ . '/..');

// ── TimesheetWeek CSV import discoverability ────────────────────────
echo "TimesheetWeek CSV import + history links\n";
$tsw = (string) file_get_contents("{$ROOT}/modules/staffing/ui/TimesheetWeek.jsx");
$a('TimesheetWeek imports react-router Link',        str_contains($tsw, "import { Link } from 'react-router-dom'"));
$a('TimesheetWeek imports Upload + History icons',   str_contains($tsw, "import { Upload, History } from 'lucide-react'"));
$a('CSV Import link points to /modules/time/bulk',   str_contains($tsw, 'to="/modules/time/bulk"'));
$a('CSV Import link has data-testid',                str_contains($tsw, 'data-testid="ts-csv-import-link"'));
$a('CSV History link points to /data/import-history',str_contains($tsw, 'to="/data/import-history"'));
$a('CSV History link has data-testid',               str_contains($tsw, 'data-testid="ts-csv-history-link"'));
$a('CSV button has descriptive tooltip',             str_contains($tsw, 'Bulk import historical or ongoing timesheets'));

echo "\nTime CSV importer (existing — verify multi-period support)\n";
$tci = (string) file_get_contents("{$ROOT}/modules/time/api/csv_import.php");
$a('csv_import.php exists',                          is_file("{$ROOT}/modules/time/api/csv_import.php"));
$a('resolves placement by external_id',              str_contains($tci, 'time_placements') && str_contains($tci, 'external_id'));
$a('auto-resolves period from work_date',
    str_contains($tci, 'time_periods') &&
    (str_contains($tci, 'work_date') && (str_contains($tci, 'period_id') || str_contains($tci, "'period'"))));
$a('supports dry_run action',                        str_contains($tci, 'dry_run'));
$a('supports commit action',                         str_contains($tci, 'commit'));
$a('uses shared CsvImportService',                   str_contains($tci, 'CsvImportService'));

// ── Evidence Attachments backend ────────────────────────────────────
echo "\napi/evidence_upload_url.php presigned-upload helper\n";
$uup = "{$ROOT}/api/evidence_upload_url.php";
$src = (string) file_get_contents($uup);
$a('evidence_upload_url.php exists',                 is_file($uup));
$a('evidence_upload_url.php parses',
   (int) shell_exec('php -l ' . escapeshellarg($uup) . ' >/dev/null 2>&1; echo $?') === 0);
$a('requires StorageService',                        str_contains($src, "require_once __DIR__ . '/../core/StorageService.php'"));
$a('require api_require_auth',                       str_contains($src, 'api_require_auth()'));
$a('POST-only (rejects non-POST)',                   str_contains($src, "api_method() !== 'POST'"));
$a('subject_type whitelist contains time_entry',     str_contains($src, "'time_entry'"));
$a('subject_type whitelist contains time_bundle',    str_contains($src, "'time_bundle'"));
$a('subject_type whitelist contains billing_invoice',str_contains($src, "'billing_invoice'"));
$a('subject_type whitelist contains ap_bill',        str_contains($src, "'ap_bill'"));
$a('rejects unknown subject_type → 422',             str_contains($src, "subject_type not in allowlist"));
$a('module map routes time→time bucket',             str_contains($src, "'time_entry'              => 'time'"));
$a('module map routes billing→billing bucket',       str_contains($src, "'billing_invoice'         => 'billing'"));
$a('returns storage_key + upload + signed_url',
    str_contains($src, "'storage_key'") && str_contains($src, "'upload'") && str_contains($src, "'signed_url'"));
$a('uses StorageService::getInstance',               str_contains($src, 'StorageService::getInstance()'));
$a('uses get_presigned_post',                        str_contains($src, '->get_presigned_post('));

echo "\napi/accounting/evidence.php signed_url action\n";
$ev = (string) file_get_contents("{$ROOT}/api/accounting/evidence.php");
$a('GET signed_url action defined',                  str_contains($ev, "action === 'signed_url'"));
$a('signed_url checks deleted_at IS NULL',           str_contains($ev, 'deleted_at IS NULL'));
$a('signed_url calls StorageService',                str_contains($ev, '\\Core\\StorageService::getInstance()->get_signed_url'));
$a('signed_url returns 404 on missing',              str_contains($ev, "'not found or no file', 404"));

// ── React component ─────────────────────────────────────────────────
echo "\n<EvidenceAttachments /> component\n";
$cmp = (string) file_get_contents("{$ROOT}/dashboard/src/components/EvidenceAttachments.jsx");
$a('component file exists',                          is_file("{$ROOT}/dashboard/src/components/EvidenceAttachments.jsx"));
$a('default-exports EvidenceAttachments',            str_contains($cmp, 'export default function EvidenceAttachments'));
$a('accepts subjectType prop',                       str_contains($cmp, 'subjectType'));
$a('accepts subjectId prop',                         str_contains($cmp, 'subjectId'));
$a('accepts compact + readOnly + label props',
    str_contains($cmp, 'compact = false') && str_contains($cmp, 'readOnly = false') && str_contains($cmp, "label = 'Attachments'"));
$a('POSTs evidence_upload_url.php',                  str_contains($cmp, "'/api/evidence_upload_url.php'"));
$a('does multipart POST to presigned URL',           str_contains($cmp, "fetch(presign.upload.url"));
$a('handles LocalDriver (no upload.url)',            str_contains($cmp, 'if (presign.upload?.url)'));
$a('registers metadata via /api/accounting/evidence.php',
    str_contains($cmp, "'/api/accounting/evidence.php'"));
$a('fetches fresh signed URL per download click',
    str_contains($cmp, "action=signed_url&id="));
$a('emits data-testid={prefix}-panel',               str_contains($cmp, '`${testidPrefix}-panel`'));
$a('emits data-testid={prefix}-upload-btn',          str_contains($cmp, '`${testidPrefix}-upload-btn`'));
$a('emits data-testid={prefix}-list',                str_contains($cmp, '`${testidPrefix}-list`'));
$a('emits data-testid={prefix}-row-{id}',            str_contains($cmp, '`${testidPrefix}-row-${row.id}`'));
$a('emits data-testid={prefix}-download-{id}',       str_contains($cmp, '`${testidPrefix}-download-${row.id}`'));
$a('emits data-testid={prefix}-delete-{id}',         str_contains($cmp, '`${testidPrefix}-delete-${row.id}`'));
$a('renders Loader2 with cf-spin during upload',     str_contains($cmp, '<Loader2 size={12} className="cf-spin"/>'));
$a('default doc-type map covers time_entry',         str_contains($cmp, "case 'time_entry':") && str_contains($cmp, "'signed_timesheet'"));
$a('default doc-type map covers ap_bill',            str_contains($cmp, "case 'ap_bill':") && str_contains($cmp, "'vendor_invoice'"));

// ── Mount points ────────────────────────────────────────────────────
echo "\nEvidenceAttachments mount points\n";
$a('TimesheetWeek imports EvidenceAttachments',      str_contains($tsw, "import EvidenceAttachments from '../../../dashboard/src/components/EvidenceAttachments'"));
$a('TimesheetWeek mounts <EvidenceAttachments> for time_bundle',
    preg_match('/<EvidenceAttachments[^>]+subjectType="time_bundle"[^>]+subjectId=\{header\.id\}/s', $tsw) === 1);
$a('TimesheetWeek attachment testidPrefix ts-evidence',
    str_contains($tsw, 'testidPrefix="ts-evidence"'));

$inv = (string) file_get_contents("{$ROOT}/modules/billing/ui/InvoiceDetail.jsx");
$a('InvoiceDetail imports EvidenceAttachments',      str_contains($inv, "import EvidenceAttachments from '../../../dashboard/src/components/EvidenceAttachments'"));
$a('InvoiceDetail mounts billing_invoice attachments',
    preg_match('/<EvidenceAttachments[^>]+subjectType="billing_invoice"[^>]+subjectId=\{inv\.id\}/s', $inv) === 1);
$a('InvoiceDetail attachment testidPrefix',          str_contains($inv, 'testidPrefix="billing-invoice-evidence"'));

$bd = (string) file_get_contents("{$ROOT}/modules/ap/ui/BillDetail.jsx");
$a('BillDetail imports EvidenceAttachments',         str_contains($bd, "import EvidenceAttachments from '../../../dashboard/src/components/EvidenceAttachments'"));
$a('BillDetail mounts ap_bill attachments',
    preg_match('/<EvidenceAttachments[^>]+subjectType="ap_bill"[^>]+subjectId=\{bill\.id\}/s', $bd) === 1);
$a('BillDetail attachment testidPrefix',             str_contains($bd, 'testidPrefix="ap-bill-evidence"'));

// ── FSC Health endpoint + CFO Dashboard panel ───────────────────────
echo "\nFSC Health endpoint + dashboard panel\n";
$hp = "{$ROOT}/api/admin/fsc_health.php";
$hpSrc = (string) file_get_contents($hp);
$a('fsc_health.php exists',                          is_file($hp));
$a('fsc_health.php parses',
   (int) shell_exec('php -l ' . escapeshellarg($hp) . ' >/dev/null 2>&1; echo $?') === 0);
$a('fsc_health requires auth',                       str_contains($hpSrc, 'api_require_auth()'));
$a('fsc_health is GET-only',                         str_contains($hpSrc, "api_method() !== 'GET'"));
$a('fsc_health graceful when migration 045 missing',
    str_contains($hpSrc, "'configured' => false") && str_contains($hpSrc, "Migration 045_financial_state_cache.sql"));
$a('fsc_health returns rows_cached',                 str_contains($hpSrc, "'rows_cached'"));
$a('fsc_health returns scopes_cached',               str_contains($hpSrc, "'scopes_cached'"));
$a('fsc_health returns dirty_count',                 str_contains($hpSrc, "'dirty_count'"));
$a('fsc_health returns per_scope p50/p95-ish',       str_contains($hpSrc, "'per_scope'") && str_contains($hpSrc, 'avg_ms'));
$a('fsc_health returns top_dirty_reasons',           str_contains($hpSrc, "'top_dirty_reasons'"));
$a('fsc_health scoped by tenant_id',                 substr_count($hpSrc, 'tenant_id = :t') >= 5);

$fhp = (string) file_get_contents("{$ROOT}/dashboard/src/components/FscHealthPanel.jsx");
$a('FscHealthPanel.jsx exists',                      is_file("{$ROOT}/dashboard/src/components/FscHealthPanel.jsx"));
$a('FscHealthPanel default-exports component',       str_contains($fhp, 'export default function FscHealthPanel'));
$a('FscHealthPanel is collapsed by default',         str_contains($fhp, 'useState(false)'));
$a('FscHealthPanel lazy-loads data on open',         str_contains($fhp, "open ? '/api/admin/fsc_health.php' : null"));
$a('FscHealthPanel renders Cache Health label',      str_contains($fhp, 'Cache Health'));
$a('FscHealthPanel data-testid root',                str_contains($fhp, 'data-testid="fsc-health-panel"'));
$a('FscHealthPanel toggle has testid',               str_contains($fhp, 'data-testid="fsc-health-toggle"'));
$a('FscHealthPanel renders pending-dirty tile',      str_contains($fhp, 'testid="fsc-dirty"'));
$a('FscHealthPanel renders per-scope table',         str_contains($fhp, 'data-testid="fsc-per-scope"'));
$a('FscHealthPanel renders dirty-reason chips',      str_contains($fhp, 'data-testid="fsc-dirty-reasons"'));

$cfo = (string) file_get_contents("{$ROOT}/dashboard/src/pages/CFODashboard.jsx");
$a('CFODashboard imports FscHealthPanel',            str_contains($cfo, "import FscHealthPanel from '../components/FscHealthPanel'"));
$a('CFODashboard mounts <FscHealthPanel />',         str_contains($cfo, '<FscHealthPanel />'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
