<?php
/** Schema-only pilot migration and guarded recovery; callers own maintenance and backups. */
declare(strict_types=1);

const QUANTA_PILOT_ROUTE_SQL_SHA256='99be19a4ee7426b18fe310a6c646c80388cfe755b1bb9060af5540cd658fe091';
const QUANTA_PILOT_ROUTE_COLUMNS=['tenant_id','worker_id','worksite_id','dimension_key','effective_from'];

function quantaPilotRouteState(PDO $pdo):array
{
    $columns=$pdo->query("SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quanta_time_routes' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
    if(!$columns)throw new RuntimeException('Existing Quanta routes table is required');
    $indexes=[];
    foreach($pdo->query('SHOW INDEX FROM quanta_time_routes')->fetchAll(PDO::FETCH_ASSOC) as $row){
        $indexes[$row['Key_name']][(int)$row['Seq_in_index']]=['column'=>$row['Column_name'],'unique'=>(int)$row['Non_unique']===0,
            'prefix'=>$row['Sub_part']===null?null:(int)$row['Sub_part'],'type'=>$row['Index_type']];
    }
    ksort($indexes);foreach($indexes as &$rows)ksort($rows);unset($rows);
    return ['columns'=>$columns,'indexes'=>$indexes,'rows'=>$pdo->query('SELECT * FROM quanta_time_routes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)];
}

function quantaPilotRouteIndex(array $columns):array
{
    $result=[];foreach($columns as $i=>$name)$result[$i+1]=['column'=>$name,'unique'=>true,'prefix'=>null,'type'=>'BTREE'];return $result;
}

function quantaPilotRouteBaseline(array $before):void
{
    if(in_array('quanta_assignment_id',array_column($before['columns'],'COLUMN_NAME'),true)
        ||isset($before['indexes']['uq_quanta_route_assignment_start'])
        ||($before['indexes']['uq_quanta_route_start']??null)!==quantaPilotRouteIndex(QUANTA_PILOT_ROUTE_COLUMNS)){
        throw new RuntimeException('Route migration requires the reviewed legacy schema');
    }
}

function quantaPilotRoutePreserved(array $before,array $current):void
{
    quantaPilotRouteBaseline($before);
    $selector=array_values(array_filter($current['columns'],static fn($row)=>$row['COLUMN_NAME']==='quanta_assignment_id'));
    if($selector&&(count($selector)!==1||$selector[0]['COLUMN_TYPE']!=='varchar(68)'||$selector[0]['IS_NULLABLE']!=='NO'
        ||!in_array($selector[0]['COLUMN_DEFAULT'],['',"''"],true)||$selector[0]['CHARACTER_SET_NAME']!=='ascii'
        ||$selector[0]['COLLATION_NAME']!=='ascii_bin'||$selector[0]['EXTRA']!==''))throw new RuntimeException('Assignment selector column differs from reviewed schema');
    if(isset($current['indexes']['uq_quanta_route_start'])&&$current['indexes']['uq_quanta_route_start']!==$before['indexes']['uq_quanta_route_start'])throw new RuntimeException('Original route index was altered');
    if(isset($current['indexes']['uq_quanta_route_assignment_start'])&&$current['indexes']['uq_quanta_route_assignment_start']!==quantaPilotRouteIndex([...QUANTA_PILOT_ROUTE_COLUMNS,'quanta_assignment_id']))throw new RuntimeException('Replacement route index was altered');
    $rows=$current['rows'];
    foreach($rows as &$row){
        if(array_key_exists('quanta_assignment_id',$row)&&$row['quanta_assignment_id']!=='')throw new RuntimeException('Captured route use prevents automatic schema recovery');
        unset($row['quanta_assignment_id']);
    }unset($row);
    if($rows!==$before['rows'])throw new RuntimeException('Existing route data changed; preserve it for review');
    $columns=array_values(array_filter($current['columns'],static fn($row)=>$row['COLUMN_NAME']!=='quanta_assignment_id'));
    if($columns!==$before['columns'])throw new RuntimeException('Unrelated route column changed');
    $indexes=$current['indexes'];unset($indexes['uq_quanta_route_start'],$indexes['uq_quanta_route_assignment_start']);
    $old=$before['indexes'];unset($old['uq_quanta_route_start']);
    if($indexes!==$old)throw new RuntimeException('Unrelated route index changed');
}

function quantaPilotRouteVerify(PDO $pdo,array $before):array
{
    $current=quantaPilotRouteState($pdo);quantaPilotRoutePreserved($before,$current);
    $column=array_values(array_filter($current['columns'],static fn($row)=>$row['COLUMN_NAME']==='quanta_assignment_id'));
    if(count($column)!==1||$column[0]['COLUMN_TYPE']!=='varchar(68)'||$column[0]['IS_NULLABLE']!=='NO'
        ||!in_array($column[0]['COLUMN_DEFAULT'],['',"''"],true)||$column[0]['CHARACTER_SET_NAME']!=='ascii'||$column[0]['COLLATION_NAME']!=='ascii_bin'
        ||isset($current['indexes']['uq_quanta_route_start'])
        ||($current['indexes']['uq_quanta_route_assignment_start']??null)!==quantaPilotRouteIndex([...QUANTA_PILOT_ROUTE_COLUMNS,'quanta_assignment_id'])){
        throw new RuntimeException('Assignment route schema does not match the reviewed migration');
    }
    return $current;
}

function quantaPilotRouteApply(PDO $pdo,string $sql,array $before):array
{
    quantaPilotRouteBaseline($before);
    if(!hash_equals(QUANTA_PILOT_ROUTE_SQL_SHA256,hash('sha256',str_replace("\r\n","\n",$sql))))throw new RuntimeException('Unreviewed route migration');
    if(quantaPilotRouteState($pdo)!==$before)throw new RuntimeException('Route schema or data changed after preflight');
    foreach(coreflux_split_sql_statements($sql) as $statement)$pdo->exec($statement);
    return quantaPilotRouteVerify($pdo,$before);
}

function quantaPilotRouteRestore(PDO $pdo,array $before):void
{
    $current=quantaPilotRouteState($pdo);quantaPilotRoutePreserved($before,$current);
    if(!isset($current['indexes']['uq_quanta_route_start'])){
        // Restore the original uniqueness guard before removing the replacement.
        $pdo->exec('ALTER TABLE quanta_time_routes ADD UNIQUE KEY uq_quanta_route_start (tenant_id,worker_id,worksite_id,dimension_key,effective_from)');
    }elseif($current['indexes']['uq_quanta_route_start']!==$before['indexes']['uq_quanta_route_start']){
        throw new RuntimeException('Original route index was altered');
    }
    if(isset($current['indexes']['uq_quanta_route_assignment_start'])){
        if($current['indexes']['uq_quanta_route_assignment_start']!==quantaPilotRouteIndex([...QUANTA_PILOT_ROUTE_COLUMNS,'quanta_assignment_id']))throw new RuntimeException('Replacement route index was altered');
        $pdo->exec('ALTER TABLE quanta_time_routes DROP INDEX uq_quanta_route_assignment_start');
    }
    if(in_array('quanta_assignment_id',array_column($current['columns'],'COLUMN_NAME'),true))$pdo->exec('ALTER TABLE quanta_time_routes DROP COLUMN quanta_assignment_id');
    if(quantaPilotRouteState($pdo)!==$before)throw new RuntimeException('Original route schema was not restored exactly');
}
