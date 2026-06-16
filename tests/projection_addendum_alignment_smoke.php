<?php
/**
 * Projection addendum alignment smoke.
 *
 * Static contract check that architecture docs and core projection surfaces
 * reflect the projection addendum principles:
 * - business events remain canonical
 * - projection engines are deterministic + replayable
 * - artifacts preserve interpretations
 * - AI is supervisory/orchestrative but cannot alter truth or bypass controls
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    {$name}\n"; }
    else     { $fail++; echo "  FAIL  {$name}\n"; }
};
$c = static fn(string $hay, string $needle): bool => str_contains($hay, $needle);

$alignment = (string) file_get_contents($ROOT . '/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md');
$liquidity = (string) file_get_contents($ROOT . '/core/treasury/liquidity_projection.php');
$artifacts = (string) file_get_contents($ROOT . '/core/ai/artifacts.php');
$cashForecast = (string) file_get_contents($ROOT . '/core/ai/cash_forecast.php');
$treasuryManifest = (string) file_get_contents($ROOT . '/modules/treasury/manifest.php');
$forecastApi = (string) file_get_contents($ROOT . '/api/liquidity_forecast.php');
$forecastUi = (string) file_get_contents($ROOT . '/dashboard/src/pages/LiquidityForecast.jsx');
$scenarioApi = (string) file_get_contents($ROOT . '/api/treasury_scenario.php');
$compareApi = (string) file_get_contents($ROOT . '/api/treasury_scenario_compare.php');
$shareApi = (string) file_get_contents($ROOT . '/api/treasury_scenario_share.php');
$impactApi = (string) file_get_contents($ROOT . '/api/ap_bill_liquidity_impact.php');
$replayApi = (string) file_get_contents($ROOT . '/api/posting_rules_replay.php');
$apReplay  = (string) file_get_contents($ROOT . '/api/ap_bill_replay.php');
$biReplay  = (string) file_get_contents($ROOT . '/api/billing_invoice_replay.php');

echo "Projection addendum alignment\n";
$a('architecture doc has projection section',
    $c($alignment, '### Projection Architecture And Economics'));
$a('doc preserves canonical business-event principle',
    $c($alignment, 'Business Events (canonical; immutable)')
    && $c($alignment, 'Do not introduce a second "economic event" source of truth.'));
$a('doc defines deterministic projection flow',
    $c($alignment, 'Projection Engines (deterministic transforms)')
    && $c($alignment, 'Projection Artifacts (snapshots, rollforwards, projected journals/cash/tax)'));
$a('doc locks replayability + versioning',
    $c($alignment, 'Projection rules are versioned; Business Events stay immutable.')
    && $c($alignment, 'Projection outputs must be replayable from graph snapshots + event population.'));
$a('doc locks AI restrictions for projection operations',
    $c($alignment, 'AI may not invent events')
    && $c($alignment, 'bypass approvals')
    && $c($alignment, 'material changes without governed authorization.'));

echo "\nCore surfaces\n";
$a('shared liquidity projection walker exists',
    $c($liquidity, 'function liquidityWalkProjection('));
$a('liquidity projection rule version is explicit',
    $c($liquidity, 'LIQUIDITY_PROJECTION_RULE_VERSION')
    && $c($liquidity, 'function liquidityProjectionEvidence(')
    && $c($liquidity, "'replay_key'"));
$a('liquidity APIs surface projection evidence',
    $c($forecastApi, "'projection'")
    && $c($scenarioApi, 'liquidityProjectionEvidence(')
    && $c($compareApi, 'liquidityProjectionEvidence(')
    && $c($shareApi, 'liquidityProjectionEvidence(')
    && $c($impactApi, 'liquidityProjectionEvidence('));
$a('liquidity forecast exposes source drilldown and timing classes',
    $c($liquidity, 'function liquidityProjectionSourceDetail(')
    && $c($liquidity, "'source_record_type'")
    && $c($liquidity, "'scheduled' => ['inflows' => 0.0, 'outflows' => 0.0]")
    && $c($liquidity, "\$scheduled ? 'scheduled' : 'expected'")
    && $c($liquidity, "'classification' => 'expected'")
    && $c($liquidity, "'classification' => 'forecasted'")
    && $c($forecastApi, "'source_detail'")
    && $c($forecastApi, 'liquidityAttachDailySourceDetail('));
$a('treasury scenario APIs preserve source drilldown through overlays',
    $c($scenarioApi, '$baselineSourceDetail = liquidityProjectionSourceDetail($datasets);')
    && $c($scenarioApi, '$simulatedSourceDetail = liquidityProjectionSourceDetail($datasets, [')
    && $c($compareApi, '$sourceDetailA = liquidityProjectionSourceDetail($datasets, [')
    && $c($compareApi, '$sourceDetailB = liquidityProjectionSourceDetail($datasets, [')
    && $c($scenarioApi, "'source_detail'        => \$simulatedSourceDetail")
    && $c($compareApi, "'source_detail'        => \$sourceDetailA"));
$a('AP bill liquidity impact preserves source drilldown',
    $c($impactApi, '$baselineSourceDetail = liquidityProjectionSourceDetail($datasets);')
    && $c($impactApi, '$simulatedSourceDetail = liquidityProjectionSourceDetail($datasets, [')
    && $c($impactApi, "'source_detail'        => \$simulatedSourceDetail"));
$a('liquidity forecast UI renders source drilldown',
    $c($forecastUi, 'data-testid="liquidity-source-detail"')
    && $c($forecastUi, 'data-testid="liquidity-classification-totals"')
    && $c($forecastUi, 'liquidity-source-daily-list')
    && $c($forecastUi, 'SourceMovement'));
$a('artifact lifecycle helpers exist',
    $c($artifacts, 'function artifactCreate(')
    && $c($artifacts, 'function artifactTransition(')
    && $c($artifacts, 'function artifactLineage('));
$a('cash forecast runs create projection artifacts',
    $c($cashForecast, "artifactCreate(\$tenantId, 'cash_forecast'")
    && $c($cashForecast, "source_record_type' => 'cash_forecast_runs'")
    && $c($cashForecast, "'artifact_id'             => \$artifactId"));
$a('cash forecast runs emit shared audit evidence',
    $c($cashForecast, "'treasury.forecast.run'")
    && $c($cashForecast, 'platformAuditLogWrite(')
    && $c($treasuryManifest, "'treasury.forecast.run'"));
$a('posting replay API exists and is parseable reference',
    $c($replayApi, 'idempotent_replay') || $c($replayApi, 'replayed'));
$a('AP and Billing replay APIs emit replay markers',
    $c($apReplay, "'replay'") && $c($biReplay, "'replay'"));

echo "\nSyntax checks\n";
foreach ([
    '/core/treasury/liquidity_projection.php',
    '/core/ai/artifacts.php',
    '/core/ai/cash_forecast.php',
    '/modules/treasury/manifest.php',
    '/api/liquidity_forecast.php',
    '/api/treasury_scenario.php',
    '/api/treasury_scenario_compare.php',
    '/api/treasury_scenario_share.php',
    '/api/ap_bill_liquidity_impact.php',
    '/api/posting_rules_replay.php',
    '/api/ap_bill_replay.php',
    '/api/billing_invoice_replay.php',
    '/tests/projection_addendum_alignment_smoke.php',
] as $path) {
    $out = []; $rc = 0;
    @exec('php -l ' . escapeshellarg($ROOT . $path) . ' 2>&1', $out, $rc);
    $a("php -l {$path}", $rc === 0);
}

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
