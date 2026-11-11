"""Restore only the known prior isolated pilot code after native contract rejection; never restore data."""
import fcntl,hashlib,json,os,subprocess,sys,time,urllib.request,uuid
from datetime import datetime,timezone
from pathlib import Path
ROOT=Path('/srv/core-integration-pilot/20261029');PRIVATE=ROOT/'private';WEB='core-provider-pilot-20261029-pure-pilot-web-1';SITE='core-provider-pilot-20261029-pure-pilot-site-1'
STATE=PRIVATE/'pure-lifecycle-compatibility-restoration-20261111.json'
sha=lambda b:hashlib.sha256(b).hexdigest()

def run(*args,input=None,timeout=60):
    p=subprocess.run(args,input=input,capture_output=True,text=True,timeout=timeout)
    if p.returncode:raise RuntimeError(p.stderr[-400:])
    return p.stdout

def snapshot():
    code="""import hashlib,json,os
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_pure_20261029' and os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
print(json.dumps({n:{'rows':sorted(hashlib.sha256(json_util.dumps(r,sort_keys=True).encode()).hexdigest() for r in d[n].find({})),'indexes':json_util.dumps(d[n].index_information(),sort_keys=True)} for n in sorted(d.list_collection_names())}))"""
    return json.loads(run('docker','exec','-i',WEB,'python','-',input=code))

def save(report):
    with STATE.open('w') as f:json.dump(report,f,indent=2)

def main():
    assert os.geteuid()==0 and sys.argv[1:]==['--restore-prior-isolated-contracts'] and not STATE.exists()
    lock=(PRIVATE/'pure-lifecycle-release.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    install=json.loads((PRIVATE/'pure-lifecycle-current-source-install-resume-20261111.json').read_text())
    attempt=json.loads((PRIVATE/'synthetic-lifecycle-pure-activation-20261111.json').read_text())
    assert install['passed'] and attempt['passed'] is False and attempt['phase']=='login' and attempt['native_session_retired']
    live=json.loads(run('docker','inspect',WEB,SITE));assert live[0]['Id']==install['container_id'] and live[1]['Id']==install['site_id']
    assert json.loads((ROOT/'pure/backend/release_handover.json').read_text())==install['stamp']
    backup=Path(install['backup']);assert backup.parent==PRIVATE and backup.resolve()==backup
    original=json.loads((PRIVATE/'pure-lifecycle-release-inspection-20261111.json').read_text())
    for name,digest in original['runtime'].items():assert sha((backup/'backend'/name).read_bytes().replace(b'\r\n',b'\n'))==digest
    before=snapshot()
    ids=run('docker','ps','-aq').split()
    unrelated={r['Name']:[r['Id'],r['State']['Status'],r['State']['StartedAt']] for r in json.loads(run('docker','inspect',*ids)) if r['Name'] not in ['/'+WEB,'/'+SITE]}
    rejected=PRIVATE/('pure-lifecycle-incompatible-'+uuid.uuid4().hex);rejected.mkdir(mode=0o700)
    (rejected/'database-fingerprints.json').write_text(json.dumps(before))
    config=PRIVATE/'pure.env';(rejected/'pure.env').write_bytes(config.read_bytes());os.chmod(rejected/'pure.env',0o600)
    with os.fdopen(os.open(STATE,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'w') as f:f.write('{}')
    report={'checked_at':datetime.now(timezone.utc).isoformat(),'passed':False,'phase':'restoring','rejected_candidate':str(rejected),'database_restored':False,'production_changed':False,'real_payroll_payments_postings':False}
    save(report)
    run('docker','compose','-f',str(ROOT/'compose.yaml'),'stop','pure-pilot-site','pure-pilot-web')
    for part in ['backend','frontend']:
        os.rename(ROOT/'pure'/part,rejected/part);os.rename(backup/part,ROOT/'pure'/part)
    temp=config.with_suffix('.restore-next')
    with os.fdopen(os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'wb') as f:f.write((backup/'pure.env').read_bytes());f.flush();os.fsync(f.fileno())
    os.replace(temp,config)
    run('docker','compose','-f',str(ROOT/'compose.yaml'),'up','-d','--no-deps','--force-recreate','pure-pilot-web','pure-pilot-site')
    for attempt in range(30):
        try:
            with urllib.request.urlopen('http://127.0.0.1:19180/api/release-status',timeout=5) as r:health=json.load(r)
            assert health['state']=='active' and health['startup_mode']=='isolated_pilot' and health['provider_egress_enabled'] is False
            break
        except (OSError,ValueError,AssertionError):
            if attempt==29:raise
            time.sleep(1)
    assert snapshot()==before
    for name,digest in original['runtime'].items():assert sha((ROOT/'pure/backend'/name).read_bytes().replace(b'\r\n',b'\n'))==digest
    ids=run('docker','ps','-aq').split()
    assert {r['Name']:[r['Id'],r['State']['Status'],r['State']['StartedAt']] for r in json.loads(run('docker','inspect',*ids)) if r['Name'] not in ['/'+WEB,'/'+SITE]}==unrelated
    report.update(passed=True,phase='complete',health=health,original_runtime_files=len(original['runtime']),
        documents_preserved=sum(len(v['rows']) for v in before.values()),collections_and_indexes_preserved=len(before),other_services_unchanged=True,
        container_id=json.loads(run('docker','inspect',WEB))[0]['Id'],site_id=json.loads(run('docker','inspect',SITE))[0]['Id'])
    save(report);print(json.dumps(report))

if __name__=='__main__':main()
