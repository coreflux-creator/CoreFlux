<?php
/**
 * StorageService smoke test — exercises LocalDriver end-to-end.
 *
 * Does not require AWS, MySQL, or Composer dependencies.
 * S3Driver is asserted to be present + class-loadable but NOT instantiated
 * (it requires aws-sdk-php which is only installed on the deploy environment).
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/StorageService.php';

use Core\StorageService;
use Core\Storage\LocalDriver;

$pass = 0;
$fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) {
        echo "  ✓ {$name}\n";
        $pass++;
    } else {
        echo "  ✗ {$name}\n";
        $fail++;
    }
};

// Use a throwaway dir for this test
$tmpRoot = sys_get_temp_dir() . '/cf-storage-test-' . bin2hex(random_bytes(4));
@mkdir($tmpRoot, 0775, true);
$driver = new LocalDriver($tmpRoot, 'test-secret', '/api/storage/local/get.php');
$svc = StorageService::reset($driver);

echo "Driver selection\n";
$assert("default driver is 'local' when no env",         $svc->driver_name() === 'local');

echo "\nKey building (path convention)\n";
$key1 = $svc->build_key('people', 42, 'person', 991, 'resume.pdf');
$assert("key starts with 'people/42/person/991/'", str_starts_with($key1, 'people/42/person/991/'));
$assert("key ends with original filename",         str_ends_with($key1, 'resume.pdf'));
$assert("key has uuid prefix on filename",         (bool) preg_match('#/[0-9a-f]{8}-resume\.pdf$#', $key1));

try {
    $svc->build_key('people', 0, 'person', 1, 'x.pdf');
    $assert("rejects tenant_id=0", false);
} catch (\InvalidArgumentException $e) {
    $assert("rejects tenant_id=0", true);
}

try {
    $svc->build_key('', 1, 'person', 1, 'x.pdf');
    $assert("rejects empty module", false);
} catch (\InvalidArgumentException $e) {
    $assert("rejects empty module", true);
}

$keyTraverse = $svc->build_key('people', 42, 'person', 991, '../etc/passwd');
$assert("traversal stripped from filename", !str_contains($keyTraverse, '..'));

echo "\nPut + head + signed URL (LocalDriver)\n";
$put = $svc->put('time', 7, 'timesheet', '2026-W08', 'sheet.pdf', 'PDF-bytes-here');
$assert("put returns key",         !empty($put['key']));
$assert("put returns etag",        !empty($put['etag']));
$assert("put size matches input",  $put['size_bytes'] === strlen('PDF-bytes-here'));
$assert("put signed_url issued",   str_contains($put['signed_url'], 'sig='));

$head = $svc->head($put['key']);
$assert("head returns size",       isset($head['size_bytes']) && $head['size_bytes'] === strlen('PDF-bytes-here'));
$assert("head returns etag",       !empty($head['etag']));

echo "\nSigned URL HMAC verification\n";
parse_str(parse_url($put['signed_url'], PHP_URL_QUERY) ?: '', $qs);
$verify = $driver->verify_signed_token($qs['k'] ?? '', (int) ($qs['e'] ?? 0), $qs['sig'] ?? '');
$assert("HMAC-signed token verifies", $verify === true);
$assert("tampered token rejected", !$driver->verify_signed_token($qs['k'] ?? '', (int) ($qs['e'] ?? 0), 'xxx'));

echo "\nPresigned POST shape\n";
$post = $svc->get_presigned_post($put['key']);
$assert("presigned-post has form_action", !empty($post['form_action']));
$assert("presigned-post has 'sig' field",  isset($post['fields']['sig']));
$assert("presigned-post has 'key' field",  isset($post['fields']['key']));

echo "\nRetention + legal hold (advisory on local driver)\n";
$svc->apply_retention($put['key'], new \DateTimeImmutable('+7 years'));
$svc->apply_legal_hold($put['key'], true);
$abs = $driver->abs($put['key']);
$meta = json_decode((string) @file_get_contents($abs . '.meta.json'), true) ?: [];
$assert("retention persisted in meta",  !empty($meta['lock_until']));
$assert("legal hold persisted in meta", !empty($meta['legal_hold']));

echo "\nSoft delete\n";
$svc->soft_delete($put['key']);
$assert("head returns null after delete", $svc->head($put['key']) === null);

echo "\nS3Driver loadable (without instantiation)\n";
$assert("S3Driver class exists",
    class_exists(\Core\Storage\S3Driver::class));

// Cleanup
@exec('rm -rf ' . escapeshellarg($tmpRoot));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
