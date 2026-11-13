<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
$tenantId = isset($args[0]) && preg_match('/^[1-9][0-9]*$/D', $args[0])
    ? filter_var($args[0], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) : false;
if ($tenantId === false || count($args)>2 || (isset($args[1]) && $args[1]!=='--apply-source-links')) {
    fwrite(STDERR, "Usage: php scripts/repair_staffing_client_catalog.php WORKSPACE_ID [--apply-source-links]\nDefault: read-only review. The optional flag repairs exact JobDiva billing links; client lifecycle and company roles require native review.\n");
    exit(2);
}
require_once __DIR__.'/../core/jobdiva/mapping_alignment.php';
require_once __DIR__.'/../modules/staffing/lib/client_catalog_review.php';
try {
    $apply = ($args[1] ?? '') === '--apply-source-links';
    $before = staffingClientCatalogReview($tenantId);
    $repair = $apply ? jobdivaMappingRepairStaffingClientLinks($tenantId, null, 5000) : null;
    $after = $apply ? staffingClientCatalogReview($tenantId) : $before;
    echo json_encode(['mode'=>$apply?'apply_exact_source_links':'read_only', 'before'=>$before, 'assignment_client_links'=>$repair, 'after'=>$after], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit(($repair['failed'] ?? 0)>0 || $after['review_required'] ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}
