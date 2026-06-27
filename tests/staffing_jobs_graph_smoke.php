<?php
/**
 * Staffing jobs graph smoke.
 *
 * Locks the Jobs/Roles consumer graph:
 *   - staffing_jobs schema exists and placements can link to it.
 *   - JobDiva job mirrors bridge into staffing_jobs.
 *   - Staffing UI/API expose the graph as a real page, not a stub.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? "  ok    " : "  FAIL  ") . $label . PHP_EOL;
    if ($ok) $pass++; else $fail++;
};
$read = fn(string $p): string => (string) file_get_contents($root . '/' . $p);

echo "Staffing jobs schema\n";
$mig = $read('modules/staffing/migrations/006_jobs.sql');
$a('creates staffing_jobs table', str_contains($mig, 'CREATE TABLE IF NOT EXISTS staffing_jobs'));
$a('has canonical client/company/source identity columns',
    str_contains($mig, 'client_id BIGINT UNSIGNED NULL')
    && str_contains($mig, 'company_id BIGINT UNSIGNED NULL')
    && str_contains($mig, 'source_system ENUM'));
$a('unique source external id per tenant', str_contains($mig, 'uq_sj_tenant_source_ext (tenant_id, source_system, external_id)'));
$a('adds placements.staffing_job_id link', str_contains($mig, 'ADD COLUMN staffing_job_id BIGINT UNSIGNED NULL'));
$a('backfills placement links by JobDiva job id',
    str_contains($mig, "sj.source_system = 'jobdiva'")
    && str_contains($mig, 'sj.external_id = p.jobdiva_job_id')
    && str_contains($mig, 'SET p.staffing_job_id = sj.id'));

echo "\nStaffing jobs library\n";
$lib = $read('modules/staffing/lib/jobs.php');
$a('exports JobDiva ensure helper', str_contains($lib, 'function staffingJobEnsureFromJobDivaPayload('));
$a('exports source lookup helper', str_contains($lib, 'function staffingJobFindBySource('));
$a('exports placement link helper', str_contains($lib, 'function staffingJobLinkPlacementsByJobDivaId('));
$a('bridges job client through staffingClientEnsureForCompany', str_contains($lib, 'staffingClientEnsureForCompany($tenantId, $companyId, $clientName'));

echo "\nJobDiva sync bridge\n";
$sync = $read('core/jobdiva/sync.php');
$a('sync loads staffing jobs lib', str_contains($sync, "/../../modules/staffing/lib/jobs.php"));
$a('bridge helper declared', str_contains($sync, 'function jobdivaBridgeStaffingJobFromPayload('));
$a('job mirror entity calls bridge',
    str_contains($sync, "if (\$entityType === 'jobdiva_job' && \$upsert !== null)")
    && str_contains($sync, 'jobdivaBridgeStaffingJobFromPayload($tid, $extId, $jd, $userId)'));
$a('mirror-by-placement passes actor to jobs bridge',
    str_contains($sync, "jobdivaMirrorStoreAndIndex(\$tid, 'jobdiva_job', \$jobs")
    && str_contains($sync, "['id', 'jobId', 'job_id', 'jobID', 'JOBID', 'job id'], \$userId"));
$a('placement upsert can write staffing_job_id',
    str_contains($sync, "'staffing_job_id'      => ['sji'")
    && str_contains($sync, 'client_id, staffing_job_id'));

echo "\nPlacement read model\n";
$placementLib = $read('modules/placements/lib/placements.php');
$a('placement safe fields include staffing_job_id', str_contains($placementLib, "'staffing_job_id'"));
$a('placement detail joins staffing_jobs', str_contains($placementLib, 'LEFT JOIN staffing_jobs sj'));
$a('placement list exposes staffing_job_title', str_contains($placementLib, 'staffing_job_title'));

echo "\nStaffing API/UI\n";
$api = $read('modules/staffing/api/jobs.php');
$ui = $read('modules/staffing/ui/Jobs.jsx');
$mod = $read('modules/staffing/ui/StaffingModule.jsx');
$manifest = $read('modules/staffing/manifest.php');
$a('jobs API list/get/create/update/close actions',
    str_contains($api, "action === 'list'")
    && str_contains($api, "action === 'get'")
    && str_contains($api, "action === 'create'")
    && str_contains($api, "action === 'update'")
    && str_contains($api, "action === 'close'"));
$a('jobs API gates manage actions', str_contains($api, "rbac_legacy_require(\$user, 'staffing.jobs.manage')"));
$a('jobs UI renders table + drawer', str_contains($ui, 'data-testid="staffing-jobs-table"') && str_contains($ui, 'data-testid="staffing-job-drawer"'));
$a('StaffingModule routes jobs to Jobs component',
    str_contains($mod, "import Jobs from './Jobs'")
    && str_contains($mod, 'path="jobs"         element={<Jobs />}'));
$a('manifest declares staffing.jobs.manage', str_contains($manifest, "'staffing.jobs.manage'"));

echo "\nSyntax\n";
foreach (['modules/staffing/lib/jobs.php', 'modules/staffing/api/jobs.php', 'core/jobdiva/sync.php'] as $file) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $out, $rc);
    $a('php -l ' . $file, $rc === 0);
}

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
