<?php
/** The shared executable fixture covers the exporter used by provider acceptance. */
require __DIR__.'/purepay_assignment_commission_fixture.php';
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/purepay_assignment_commission_fixture.php'),$status);
if($status!==0)throw new RuntimeException('Commission exchange projection failed');
