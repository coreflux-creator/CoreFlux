<?php
/** One-time permanent identity for the authorized isolated staging app. No target override. */
declare(strict_types=1);
$root='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
if (PHP_SAPI!=='cli' || getenv('COREFLUX_ENV')!=='staging' || ($argv[1]??'')!=='--initialize'
    || realpath(dirname(__DIR__))!==$root) throw new RuntimeException('Exact isolated staging identity setup only');
require_once $root.'/core/db.php';
require_once $root.'/core/integrations/identity.php';
$pdo=getDB();
if (!$pdo || $pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
    || (int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=999')->fetchColumn()!==1
    || !defined('COREFLUX_STAGING') || !COREFLUX_STAGING) throw new RuntimeException('Staging identity mismatch');
$directory=dirname($root).'/private_html';
$path=$directory.'/coreflux.integration.php';
$allowWebRead=static function()use($root,$path):void {
    // Use the same web-service group as the working private database config.
    // The identity stays outside the webroot, with no group write or public access.
    $group=filegroup($root.'/core/db.local.php');
    if ($group===false || is_link($path) || realpath($path)!==$path
        || fileowner($path)!==posix_geteuid() || !chgrp($path,$group) || !chmod($path,0640)) {
        throw new RuntimeException('Cannot grant the web process private identity read access');
    }
};
if (integrationInstanceId()!==null) {
    $allowWebRead();
    echo "Permanent Core integration identity verified; retained unchanged with private web read access.\n";
    exit(0);
}
if ((int)$pdo->query('SELECT COUNT(*) FROM quanta_workspace_bindings')->fetchColumn()>0
    || (int)$pdo->query('SELECT COUNT(*) FROM integration_deliveries WHERE attempted_at IS NOT NULL')->fetchColumn()>0) {
    throw new RuntimeException('Prior integration state exists. Restore its permanent identity before continuing.');
}
if (!is_dir($directory)) {
    if (!mkdir($directory,0750) || !chgrp($directory,filegroup($root.'/core/db.local.php')) || !chmod($directory,0750)) {
        throw new RuntimeException('Cannot create private configuration directory');
    }
}
if (is_link($directory) || realpath($directory)!==$directory) throw new RuntimeException('Invalid private configuration directory');
$bytes=random_bytes(16);
$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);
$hex=bin2hex($bytes);
$uuid=substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
$content="<?php\n// Permanent identity for this isolated installation. Preserve across releases; never clone.\ndefine('COREFLUX_INTEGRATION_INSTANCE_ID', '".$uuid."');\n";
$oldMask=umask(0077);
try {
    $file=fopen($path,'x');
    if (!$file) throw new RuntimeException('Private identity already exists or is not writable; it was not replaced');
    try { if (fwrite($file,$content)!==strlen($content) || !fflush($file)) throw new RuntimeException('Private identity could not be saved completely'); }
    finally { fclose($file); }
} finally { umask($oldMask); }
$allowWebRead();
if (integrationInstanceId()!==$uuid) throw new RuntimeException('Saved permanent identity did not verify');
echo "Permanent Core integration identity initialized for isolated staging. Provider delivery remains disabled.\n";
