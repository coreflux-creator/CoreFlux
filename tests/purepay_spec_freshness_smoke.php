<?php
/** Pure//Pay published-contract freshness smoke. */
declare(strict_types=1);
$root=dirname(__DIR__);$pass=0;$fail=[];
$check=function(string $l,bool $ok)use(&$pass,&$fail){echo($ok?'  ✓ ':'  ✗ ').$l.PHP_EOL;if($ok)$pass++;else$fail[]=$l;};
$path=$root.'/spec/purepay_schema.json';$tool=$root.'/tools/refresh_purepay_spec.sh';
$spec=json_decode((string)file_get_contents($path),true);
$check('schema parses',is_array($spec));
$check('published base URL recorded',($spec['baseUrl']??'')==='https://purepay.online/api/v1');
foreach(['/vendors','/bills','/bills/{id}/pay','/payments','/wallet'] as $p)$check("{$p} recorded",isset($spec['paths'][$p]));
$events=$spec['definitions']['Webhook']['events']??[];
$check('settled + failed events recorded',in_array('payment.settled',$events,true)&&in_array('payment.failed',$events,true));
$check('HMAC contract recorded',str_contains((string)($spec['definitions']['Webhook']['signature']??''),'timestamp'));
$check('refresh tool exists',is_file($tool));
$toolSrc=(string)file_get_contents($tool);
$check('refresh tool fetches official site',str_contains($toolSrc,'https://purepay.online/developers'));
$check('refresh tool checks webhook markers',str_contains($toolSrc,'X-Webhook-Signature'));
$check('snapshot marker exists',is_file($root.'/spec/purepay_docs/.fetched_at'));
echo "Pure//Pay freshness: {$pass} passed, ".count($fail)." failed\n";exit($fail?1:0);

