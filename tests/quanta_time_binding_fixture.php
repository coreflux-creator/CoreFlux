<?php
/** Actual Quanta native-wire fixture on stdin; no network, database or provider. */
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/fixtures/quanta_time_binding_checks.php';
$packet=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
echo json_encode(quantaTimeBindingChecks($packet),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
