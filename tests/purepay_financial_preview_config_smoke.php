<?php
/** Real private-config transformation and atomic replacement, using disposable files. */
declare(strict_types=1);
require_once __DIR__.'/../deploy/configure_purepay_financial_preview.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void {
    if (!$ok) throw new RuntimeException($message); $checks++; echo "PASS $message\n";
};
$rejects=static function(callable $operation):bool {try {$operation(); return false;} catch (RuntimeException $e) {return true;}};
$before="<?php\n// Existing private settings remain byte-identical.\ndefine('DB_NAME', 'fixture');\n";
$after=financialPilotConfig($before);
$check(str_starts_with($after,$before),'existing configuration bytes preserved');
$check(substr_count($after,'COREFLUX_PUREPAY_FINANCIAL_PREVIEW_API_BASE')===1,'one reviewed setting added');
$check(str_contains($after,FINANCIAL_PILOT_BASE),'reviewed preview host retained');
foreach ([$after,"<?php echo 1; ?>\n","not PHP"] as $invalid) {
    $check($rejects(static fn()=>financialPilotConfig($invalid)),'ambiguous configuration rejected');
}
$folder=sys_get_temp_dir().DIRECTORY_SEPARATOR.'coreflux-financial-config-'.bin2hex(random_bytes(6));
if (!mkdir($folder,0700)) throw new RuntimeException('Cannot create fixture directory');
$target=$folder.'/db.php'; $prepared=$folder.'/next.php';
try {
    file_put_contents($target,$before); file_put_contents($prepared,$after);
    $oldHash=hash('sha256',$before); $newHash=hash('sha256',$after);
    $check($rejects(static fn()=>financialPilotReplace($target,$prepared,str_repeat('0',64),$newHash)),'stale target hash rejected');
    $check(file_get_contents($target)===$before && file_get_contents($prepared)===$after,'stale target leaves both files intact');
    $check($rejects(static fn()=>financialPilotReplace($target,$prepared,$oldHash,str_repeat('0',64))),'unreviewed replacement hash rejected');
    $check(file_get_contents($target)===$before,'unreviewed replacement leaves original intact');
    financialPilotReplace($target,$prepared,$oldHash,$newHash);
    $check(file_get_contents($target)===$after && !file_exists($prepared),'atomic replacement installs exact reviewed bytes');
    $check($rejects(static fn()=>financialPilotReplace($target,$prepared,$oldHash,$newHash)),'consumed or interrupted preparation cannot replay blindly');
} finally {
    foreach ([$target,$prepared] as $path) if (is_file($path)) unlink($path);
    rmdir($folder);
}
echo "PASS $checks financial preview configuration checks\n";
