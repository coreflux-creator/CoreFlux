<?php
/** The native vault supports old and new table layouts without guessing cross-entity links. */
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('CI')!=='true')throw new RuntimeException('Isolated CI only');
require_once __DIR__.'/../core/db.php';
require_once __DIR__.'/../core/migrate.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')throw new RuntimeException('Exact CI database required');
$columns='id,tenant_id,person_id,doc_type,storage_object_id,file_name,signed,signed_at,expires_at,uploaded_by_user_id,created_at,deleted_at';
$pdo->query('SELECT '.$columns.' FROM people_documents WHERE 1=0')->closeCursor();
$pdo->query('SELECT id,tenant_id,module,entity_type,entity_id,s3_key FROM storage_objects WHERE 1=0')->closeCursor();
echo "PASS native document and storage columns exist after clean install\n";
$fixture='coreflux_docs_repair_ci';$pdo->exec('CREATE DATABASE '.$fixture);$pdo->exec('USE '.$fixture);
$check=static function(bool $ok,string $label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";};
$create=static function(string $file)use($pdo){foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../modules/people/migrations/'.$file))as$sql)if(preg_match('/^CREATE TABLE IF NOT EXISTS people_documents\s*\(/i',trim($sql))){$pdo->exec($sql);return;}throw new RuntimeException('Document definition not found');};
$repair=static function()use($pdo){for($i=0;$i<2;$i++)foreach(coreflux_split_sql_statements(file_get_contents(__DIR__.'/../modules/people/migrations/009_document_vault_alignment.sql'))as$sql)$pdo->exec($sql);};
try{
    $storageSql=coreflux_split_sql_statements(file_get_contents(__DIR__.'/../core/migrations/172_storage_registry.sql'));
    foreach($storageSql as$sql)$pdo->exec($sql);
    $pdo->exec("INSERT INTO storage_objects(tenant_id,module,entity_type,entity_id,s3_key,filename,created_at) VALUES(999,'people','document','77','people/999/document/77/synthetic.pdf','synthetic.pdf',NOW())");
    $registry=$pdo->query('SELECT * FROM storage_objects')->fetchAll(PDO::FETCH_ASSOC);
    foreach($storageSql as$sql)$pdo->exec($sql);
    $check($pdo->query('SELECT * FROM storage_objects')->fetchAll(PDO::FETCH_ASSOC)===$registry,'canonical storage migration preserves registered object identities on replay');
    $create('001_init.sql');
    $pdo->exec("INSERT INTO people_documents(tenant_id,employee_id,kind,filename,mime_type,size_bytes,storage_path,uploaded_by) VALUES(999,77,'w4','legacy.pdf','application/pdf',12,'legacy/opaque',90)");
    $original=$pdo->query('SELECT * FROM people_documents WHERE id=1')->fetch(PDO::FETCH_ASSOC);$repair();
    $row=$pdo->query('SELECT * FROM people_documents WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $check(array_intersect_key($row,$original)===$original,'legacy document fields and identities survive migration replay exactly');
    $check($row['person_id']===null&&$row['storage_object_id']===null&&$row['doc_type']===null,'legacy employee and file identifiers are not promoted into trusted person evidence');
    $pdo->exec("INSERT INTO people_documents(tenant_id,person_id,doc_type,storage_object_id,file_name,uploaded_by_user_id) VALUES(999,1,'offer',5,'canonical.pdf',91)");
    $row=$pdo->query('SELECT * FROM people_documents WHERE id=2')->fetch(PDO::FETCH_ASSOC);
    $check((int)$row['person_id']===1&&(int)$row['storage_object_id']===5&&$row['employee_id']===null&&$row['storage_path']===null,'native vault writes require no invented legacy employee or file data');
    $pdo->query('SELECT '.$columns.' FROM people_documents WHERE 1=0')->closeCursor();
    $pdo->exec('DROP TABLE people_documents');$create('003_spec_alignment.sql');
    $pdo->exec("INSERT INTO people_documents(tenant_id,person_id,doc_type,storage_object_id,file_name) VALUES(999,1,'offer',5,'existing-canonical.pdf')");
    $original=$pdo->query('SELECT * FROM people_documents WHERE id=1')->fetch(PDO::FETCH_ASSOC);$repair();
    $check($pdo->query('SELECT * FROM people_documents WHERE id=1')->fetch(PDO::FETCH_ASSOC)===$original,'already canonical vault schema and data remain unchanged');
    echo "PASS document alignment accepts both original schema generations\n";
    require __DIR__.'/fixtures/people_document_vault_checks.php';
}finally{$pdo->exec('USE coreaccounting_ci');$pdo->exec('DROP DATABASE '.$fixture);}
