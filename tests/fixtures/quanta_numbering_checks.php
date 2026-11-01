<?php
/** Uses the disposable metadata fixture's shared and isolated directories. */
require_once __DIR__.'/../../core/quanta/sync.php';
$pdo->exec('ALTER TABLE placements ADD COLUMN end_client_name VARCHAR(100)');
$pdo->exec('CREATE TABLE quanta_time_routes (id BIGINT,tenant_id BIGINT,worker_id VARCHAR(128),worksite_id VARCHAR(128),placement_id BIGINT,effective_from DATE)');
$pdo->exec("INSERT INTO quanta_time_routes VALUES (1,11,'worker-1','site-1',201,'2026-10-01'),(2,11,'wrong-worker','site-1',202,'2026-10-01')");
$pdo->exec("INSERT INTO placements(id,tenant_id,person_id,title,status) VALUES (202,12,102,'Other directory','active')");
$pdo->exec("INSERT INTO core_record_numbers VALUES (202,12,'placement','PL-9002')");
$pdo->exec("INSERT INTO quanta_worker_links(id,tenant_id,worker_id,person_id) VALUES (2,11,'wrong-worker',102)");
$links=array_column(quantaWorkerLinks(11),null,'worker_id');
$routes=array_column(quantaRoutes(11),null,'worker_id');
$check($links['worker-1']['person_number']==='P-9001','worker link uses stored person number, not database ID 101');
$check($links['wrong-worker']['person_number']===null,'worker link cannot borrow a number from another directory');
$check($routes['worker-1']['placement_number']==='PL-9002' && $routes['worker-1']['person_number']==='P-9001','route carries stored placement and person references from authorized directories');
$check($routes['wrong-worker']['placement_number']===null && $routes['wrong-worker']['person_number']===null,'foreign placement route exposes no business references');
$pdo->exec('UPDATE people SET deleted_at=NOW() WHERE id=101 AND tenant_id=10');
$check(quantaWorkerLinks(11)[0]['person_number']===null,'unavailable linked person has no invented or stale displayed number');
