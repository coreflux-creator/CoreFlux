<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');require_once __DIR__.'/../core/quanta/routes.php';
$count=0;$check=static function(bool $ok,string $label)use(&$count){if(!$ok)throw new RuntimeException($label);$count++;echo "PASS $label\n";};
$fails=static function(callable $f){try{$f();return false;}catch(Throwable $e){return true;}};
$base=['worker_id'=>'worker-1','worksite_id'=>'site-1','placement_id'=>42,'effective_from'=>'2026-10-01','effective_to'=>null,'dimension_values'=>[]];
$legacy=quantaRouteInput($base);$bound=quantaRouteInput($base+['quanta_assignment_id'=>'cfa_'.str_repeat('a',64)]);
$check($legacy['quanta_assignment_id']===''&&$bound['quanta_assignment_id']!=='' ,'explicit assignment selector is separate from legacy route');
$check(quantaRouteOverlapConflict($legacy,array_replace($legacy,['placement_id'=>43])),'overlapping legacy dates still conflict');
$check(quantaRouteOverlapConflict($legacy,$bound),'same placement cannot receive duplicate legacy and captured routes');
$check(!quantaRouteOverlapConflict($legacy,array_replace($bound,['placement_id'=>43])),'a distinct captured placement does not make legacy routing ambiguous');
$check(!quantaRouteOverlapConflict($bound,array_replace($bound,['placement_id'=>43,'quanta_assignment_id'=>'cfa_'.str_repeat('b',64)])),'distinct captured assignments may overlap');
$check(quantaRouteOverlapConflict($bound,array_replace($bound,['placement_id'=>43])),'the same captured assignment cannot target two placements');
foreach(['quanta_assignment_id'=>null,'placement_id'=>'42','worksite_id'=>'../foreign','effective_from'=>'2026-02-30','effective_to'=>'2026-09-30'] as $field=>$value)$check($fails(fn()=>quantaRouteInput(array_replace($base,[$field=>$value]))),'invalid '.$field.' fails');
$check($fails(fn()=>quantaRouteInput(array_replace($bound,['worksite_id'=>'']))),'captured routes require a worksite');
echo "$count assignment route checks passed\n";
