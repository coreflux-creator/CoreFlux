import json,shlex,subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
php=r'''chdir('/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html');require 'core/db.php';
$p='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
$r=json_decode(file_get_contents($p.'/time-correction-browser-20261112.json'),true,512,JSON_THROW_ON_ERROR);
$pdo=getDB();if(!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!$r['passed']||$r['phase']!=='complete')exit(2);
$pdo->exec('START TRANSACTION READ ONLY');try{
foreach($r['actors'] as$a){
 $s=$pdo->prepare('SELECT COUNT(*) FROM users WHERE id=? AND tenant_id=2002 AND email=? AND is_active=0 AND password IS NULL AND password_hash IS NULL');$s->execute([$a['id'],$a['email']]);if((int)$s->fetchColumn()!==1)throw new RuntimeException('Actor remains active');
 $s=$pdo->prepare('SELECT COUNT(*) FROM membership_module_access WHERE membership_id=?');$s->execute([$a['membership_id']]);if((int)$s->fetchColumn()!==0)throw new RuntimeException('Grant remains');
 $s=$pdo->prepare('SELECT status FROM tenant_memberships WHERE id=? AND user_id=? AND tenant_id=2002');$s->execute([$a['membership_id'],$a['id']]);if($s->fetchColumn()!=='revoked')throw new RuntimeException('Membership remains active');
}
if(file_exists($p.'/time-correction-browser-credential-20261112.private.json'))throw new RuntimeException('One-time credential remains');
$s=$pdo->query('SELECT COUNT(*) AS n,SUM(hours) AS hours FROM time_entries WHERE tenant_id=2002 AND person_id=358 AND placement_id=864 AND work_date BETWEEN "2026-10-05" AND "2026-10-18" AND status="approved"');$h=$s->fetch(PDO::FETCH_ASSOC);
if((int)$h['n']!==10||(float)$h['hours']!==80.0||$pdo->query('SELECT status FROM placements WHERE tenant_id=2002 AND id=874')->fetchColumn()!=='ended')throw new RuntimeException('Protected cohort differs');
echo json_encode(['checked_at'=>gmdate('c'),'passed'=>true,'read_only'=>true,'retired_actors'=>array_column($r['actors'],'id'),'temporary_grants_removed'=>true,'passwords_removed'=>true,'memberships_revoked'=>true,'one_time_credential_removed'=>true,'protected_paid_hours'=>80,'protected_paid_entries'=>10,'closed_PL874_preserved'=>true]);
}finally{$pdo->rollBack();}'''
r=subprocess.run(['ssh','-T','-i','C:/Users/kunal/.ssh/coreflux_github_actions_rsa','-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','master_fpepuvndbb@138.197.121.111','COREFLUX_ENV=staging /usr/bin/php8.2 -r '+shlex.quote(php)],capture_output=True,text=True,timeout=35)
if r.returncode:raise RuntimeError(r.stderr[-1200:])
d=json.loads(r.stdout);(root/'output/time-browser-cleanup-verification.json').write_text(json.dumps(d,indent=2),encoding='utf-8');print(json.dumps(d))
