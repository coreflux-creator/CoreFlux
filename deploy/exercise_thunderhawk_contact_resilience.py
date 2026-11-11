"""Bounded native-service checks in the existing isolated Thunderhawk pilot."""
import base64, hashlib, json, subprocess, sys
from pathlib import Path
from datetime import datetime,timezone

ROOT=Path(__file__).resolve().parents[1]
PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030'
CORE_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html'
CONFIG=ROOT/'output/contact-resilience-config.json'
def ssh(core,command,body=b'',timeout=55):
    key='coreflux_github_actions_rsa' if core else 'purepay_quanta_migration_20261028'
    host='master_fpepuvndbb@138.197.121.111' if core else 'root@142.93.20.44'
    r=subprocess.run(['ssh','-T','-i','C:/Users/kunal/.ssh/'+key,'-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes',host,command],input=body,capture_output=True,timeout=timeout)
    if r.returncode:raise RuntimeError(r.stderr.decode()[-1500:])
    return json.loads(r.stdout)
def core(payload):
    cfg=json.loads(CONFIG.read_text())
    return ssh(True,f"cd {CORE_ROOT} && COREFLUX_ENV=staging /usr/bin/php8.2 {cfg['harness']}",json.dumps(payload).encode())
def reviewer(mode):
    cfg=json.loads(CONFIG.read_text())
    return ssh(True,f"cd {CORE_ROOT} && COREFLUX_ENV=staging /usr/bin/php8.2 {cfg['reviewer']} --{mode} resilience-20261111")

QUANTA_INNER=r'''import asyncio,hashlib,json,os,socket,sys,urllib.request,urllib.error
from pymongo import MongoClient
from app.security import create_access_token
from app.db import get_token_generation
from app.coreflux_contract import digest
assert socket.gethostname()=='quanta-core-pilot-20261029'
assert os.environ['DB_NAME']=='corepilot_quanta_20261029' and os.environ['MONGO_URL']=='mongodb://quanta-pilot-mongo:27017'
assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
x=json.load(sys.stdin);d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
uid='cfw_9fe254742aedc66ec5ab66c9baba07e2acb5c1581de10d30e7ecf931133895fa';tenant='4f93d4e7-af74-4765-b812-3464bb6d974b'
u=d.users.find_one({'id':uid,'tenant_id':tenant,'roles':['worker']});assert u
i=d.identities.find_one({'id':u['identity_id'],'disabled_at':None});assert i
assert d.coreflux_person_links.count_documents({'tenant_id':tenant,'worker_id':uid,'person.number':'P-358'})==1
session=x.get('session')
if x['mode']=='propose':
 assert u['status']=='active'
 token,expiry=create_access_token(user_id=uid,tenant_id=tenant,roles=['worker'],mfa=False,identity_id=i['id'],token_generation=asyncio.run(get_token_generation()))
 session={'access_token':token,'expires_at':expiry.isoformat()}
assert session
def request(path,body=None):
 req=urllib.request.Request('http://127.0.0.1:8000'+path,data=None if body is None else json.dumps(body).encode(),headers={'Authorization':'Bearer '+session['access_token'],'Content-Type':'application/json'})
 try:
  with urllib.request.urlopen(req,timeout=15) as r:return r.status,json.load(r)
 except urllib.error.HTTPError as e:return e.code,json.load(e)
status,listing=request('/api/core/profile-baselines')
out={'mode':x['mode'],'worker_status':u['status'],'own_baseline_http_status':status,'phone_sha256':hashlib.sha256((u.get('phone') or '').encode()).hexdigest()}
if x['mode']=='propose':
 assert status==200 and len(listing['items'])==1
 baseline=listing['items'][0];assert baseline['worker_id']==uid and baseline['person']['number']=='P-358'
 phones={'original':x['original'],'missing':'+1 202 555 0190','conflict':'+1 202 555 0188','offboarding':'+1 202 555 0187'}
 body={'request_id':'resilience-20261111-'+x['label'],'expected_baseline_version':baseline['version'],'expected_baseline_hash':digest(baseline),
  'proposed':baseline['profile']|{'phone_primary':phones[x['phone']]}}
 code,result=request('/api/core/profile-baselines/'+baseline['id']+'/requests',body)
 assert code in (200,201),(code,result)
 out.update(change_id=result['proposal']['id'],http_status=code,changed_fields=result['proposal']['changed_fields'],_session=session)
else:
 assert x['mode']=='check'
print(json.dumps(out))
'''
def quanta(payload):
    outer=r'''import json,os,subprocess
from pathlib import Path
private=Path('/srv/core-integration-pilot/20261029/private');assert private.resolve()==private and private.stat().st_mode&0o077==0
before=json.loads((private/'contact-worker-before-20261111.json').read_text())
x=PAYLOAD;x['original']=before['worker_before']['phone']
session=private/'contact-resilience-session-20261111.private.json';assert not session.is_symlink()
if session.exists():x['session']=json.loads(session.read_text())
r=subprocess.run(['docker','exec','-i','-w','/app/backend','core-provider-pilot-20261029-quanta-pilot-web-1','python','-c',INNER],input=json.dumps(x),capture_output=True,text=True,timeout=25)
if r.returncode:raise RuntimeError(r.stderr[-1200:])
out=json.loads(r.stdout)
if '_session' in out:
 data=out.pop('_session')
 fd=os.open(session,os.O_CREAT|os.O_WRONLY|os.O_TRUNC,0o600)
 with os.fdopen(fd,'w') as f:json.dump(data,f)
print(json.dumps(out))
'''.replace('PAYLOAD',repr(payload)).replace('INNER',repr(QUANTA_INNER))
    return ssh(False,'python3 -',outer.encode())

args=sys.argv[1:]
if args[0]=='stage':
    entries={}
    for kind,name in [('reviewer','thunderhawk_contact_reviewer.php'),('harness','thunderhawk_contact_resilience.php')]:
        body=(ROOT/'deploy'/name).read_bytes();sha=hashlib.sha256(body).hexdigest()
        entries[kind]={'path':PRIVATE+'/'+kind+'-'+sha[:16]+'.php','sha256':sha,'body':base64.b64encode(body).decode()}
    packed=base64.b64encode(json.dumps(entries).encode()).decode()
    script=r'''<?php
if(PHP_SAPI!=='cli'||posix_geteuid()!==1003)exit(2);
$items=json_decode(base64_decode('PACKED'),true,32,JSON_THROW_ON_ERROR);$out=[];
foreach($items as $kind=>$f){$p=$f['path'];if(realpath(dirname($p))!=='PRIVATE'||is_link($p))exit(2);
 $body=base64_decode($f['body'],true);if(hash('sha256',$body)!==$f['sha256'])exit(2);token_get_all($body,TOKEN_PARSE);
 if(is_file($p)){if(hash_file('sha256',$p)!==$f['sha256'])exit(2);}else{$m=umask(0077);try{$h=fopen($p,'x');}finally{umask($m);}if(!$h)exit(2);fwrite($h,$body);fflush($h);fsync($h);fclose($h);}
 $out[$kind]=$p;}
echo json_encode($out,JSON_THROW_ON_ERROR);
'''.replace('PACKED',packed).replace('PRIVATE',PRIVATE)
    out=ssh(True,'/usr/bin/php8.2',script.encode());CONFIG.write_text(json.dumps(out,indent=2)+'\n')
elif args[0]=='reviewer':out=reviewer(args[1])
elif args[0]=='core':
    payload={'mode':args[1]}
    if args[1] in ('send','resume','inspect','review'):payload['change_id']=args[2]
    if args[1]=='review':payload['phone']=args[3]
    if args[1]=='send' and len(args)>3:payload['fault']=args[3]
    if args[1]=='worker_patch':payload['kind']=args[2]
    out=core(payload)
elif args[0]=='quanta':out=quanta({'mode':args[1],**({'label':args[2],'phone':args[3]} if args[1]=='propose' else {})})
else:raise RuntimeError('Unknown bounded action')
out['checked_at']=datetime.now(timezone.utc).isoformat()
with (ROOT/'output/contact-resilience-events.jsonl').open('a') as f:f.write(json.dumps({'action':args,'result':out})+'\n')
print(json.dumps(out))
