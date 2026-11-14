<?php
/** Private native dump/restore for the isolated pilot or its disposable CI rehearsal. */
declare(strict_types=1);

function thunderhawkPilotDatabaseScope(PDO $pdo): string
{
    if(PHP_SAPI!=='cli'||PHP_OS_FAMILY!=='Linux')throw new RuntimeException('Native pilot database CLI required');
    $database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $pilot=$database==='muzqvdvqbx'&&getenv('COREFLUX_ENV')==='staging'
        &&getcwd()==='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html'
        &&posix_geteuid()===1003&&defined('DB_NAME')&&DB_NAME==='muzqvdvqbx';
    $ci=in_array($database,['coreaccounting_time_payroll_upgrade','coreaccounting_time_payroll_restore'],true)
        &&getenv('COREFLUX_ENV')==='coreaccounting'&&getenv('COREFLUX_STANDALONE_DATABASE')==='coreaccounting_ci'
        &&defined('DB_NAME')&&DB_NAME==='coreaccounting_ci';
    if(!$pilot&&!$ci)throw new RuntimeException('Database is outside the isolated upgrade scope');
    if(!in_array(DB_HOST,['127.0.0.1','localhost'],true))throw new RuntimeException('Native local database connection required');
    return $database;
}

function thunderhawkPilotPrivateStream(string $path): mixed
{
    $parent=dirname($path);
    if(realpath($parent)!==$parent||is_link($parent)||fileowner($parent)!==posix_geteuid()
        ||(fileperms($parent)&0777)!==0700||file_exists($path)||is_link($path))throw new RuntimeException('New private backup path required');
    $mask=umask(0077);try{$stream=fopen($path,'x+b');}finally{umask($mask);}
    if(!$stream||!chmod($path,0600))throw new RuntimeException('Cannot create private backup file');
    return $stream;
}

/** The caller retains its verified ingress/scheduler holds and database writer guard. */
function thunderhawkPilotDatabaseCapture(PDO $pdo,string $path): array
{
    $database=thunderhawkPilotDatabaseScope($pdo);
    $stream=thunderhawkPilotPrivateStream($path);$errorPath=$path.'.stderr';
    $errors=thunderhawkPilotPrivateStream($errorPath);
    try{
        $command=['/usr/bin/mariadb-dump','--no-defaults','--host='.DB_HOST,'--user='.DB_USER,
            '--default-character-set=utf8mb4','--single-transaction','--quick','--skip-lock-tables',
            '--routines','--events','--triggers','--skip-add-locks','--skip-disable-keys',
            '--hex-blob','--skip-comments','--max-allowed-packet=256M','--max-statement-time=180',$database];
        $env=getenv();$env['MYSQL_PWD']=DB_PASS;$env['LC_ALL']='C';
        $process=proc_open($command,[0=>['file','/dev/null','r'],1=>$stream,2=>$errors],$pipes,null,$env);
        unset($env);
        if(!is_resource($process))throw new RuntimeException('Cannot launch native pilot backup');
        $exit=proc_close($process);
        if(!fflush($stream)||!fsync($stream)||!fflush($errors)||!fsync($errors)||$exit!==0)throw new RuntimeException('Native pilot backup failed; inspect private diagnostic');
        $size=fstat($stream)['size'];
        if($size<100)throw new RuntimeException('Native pilot backup is empty');
    }finally{fclose($stream);fclose($errors);}
    return ['database'=>$database,'path'=>$path,'sha256'=>hash_file('sha256',$path),'bytes'=>$size,'mode'=>'0600'];
}

/** Explicit recovery only. The caller must first prove holds and unchanged original business values. */
function thunderhawkPilotDatabaseRestore(PDO $pdo,array $backup): array
{
    $database=thunderhawkPilotDatabaseScope($pdo);$path=$backup['path']??'';
    $source=$backup['database']??'';
    if(($database==='muzqvdvqbx'&&$source!==$database)
        ||($database!=='muzqvdvqbx'&&!in_array($source,['coreaccounting_time_payroll_upgrade','coreaccounting_time_payroll_restore'],true)))throw new RuntimeException('Backup database identity differs');
    clearstatcache(true,$path);$stat=@lstat($path);
    if(!$stat||is_link($path)||realpath($path)!==$path||!is_file($path)||$stat['uid']!==posix_geteuid()
        ||($stat['mode']&0777)!==0600||$stat['nlink']!==1||$stat['size']!==($backup['bytes']??null)
        ||hash_file('sha256',$path)!==($backup['sha256']??null))throw new RuntimeException('Private backup fingerprint differs');
    $input=fopen($path,'rb');$errorPath=$path.'.restore-'.bin2hex(random_bytes(8)).'.stderr';
    $errors=thunderhawkPilotPrivateStream($errorPath);
    try{
        $command=['/usr/bin/mariadb','--no-defaults','--host='.DB_HOST,'--user='.DB_USER,
            '--default-character-set=utf8mb4','--binary-mode','--skip-reconnect','--local-infile=0',
            '--max-allowed-packet=256M',$database];
        $env=getenv();$env['MYSQL_PWD']=DB_PASS;$env['LC_ALL']='C';
        $process=proc_open($command,[0=>$input,1=>['file','/dev/null','w'],2=>$errors],$pipes,null,$env);unset($env);
        if(!is_resource($process))throw new RuntimeException('Cannot launch native pilot restore');
        $exit=proc_close($process);
        if(!fflush($errors)||!fsync($errors)||$exit!==0)throw new RuntimeException('Native pilot restore failed; retain maintenance and inspect private diagnostic');
    }finally{fclose($input);fclose($errors);}
    return ['database'=>$database,'backup_sha256'=>$backup['sha256'],'native_restore_completed'=>true];
}
