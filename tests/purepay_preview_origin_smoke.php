<?php
declare(strict_types=1);
require_once __DIR__.'/../deploy/migrate_purepay_preview_origin.php';
$root=sys_get_temp_dir().'/core-preview-origin-'.bin2hex(random_bytes(8));
if (!mkdir($root,0700)) throw new RuntimeException('Cannot create isolated file fixture');
$target=$root.'/current.php'; $prepared=$root.'/prepared.php';
$old='<?php /* original synthetic config */'; $new='<?php /* replacement synthetic config */';
$fail=static function(callable $f): bool {try {$f();return false;} catch (Throwable $e) {return true;}};
try {
    file_put_contents($target,$old); file_put_contents($prepared,$new);
    if (!$fail(fn()=>purepayPreviewOriginReplace($target,$prepared,str_repeat('a',64),hash('sha256',$new)))) throw new RuntimeException('Changed target accepted');
    if (file_get_contents($target)!==$old || file_get_contents($prepared)!==$new) throw new RuntimeException('Failure changed recovery files');
    if (!$fail(fn()=>purepayPreviewOriginReplace($target,$prepared,hash('sha256',$old),str_repeat('b',64)))) throw new RuntimeException('Changed candidate accepted');
    purepayPreviewOriginReplace($target,$prepared,hash('sha256',$old),hash('sha256',$new));
    if (file_get_contents($target)!==$new || file_exists($prepared)) throw new RuntimeException('Atomic replacement did not verify');
    echo "PASS preview configuration replacement and failure preservation\n";
} finally {
    foreach ([$target,$prepared] as $file) if (is_file($file)) unlink($file);
    rmdir($root);
}
