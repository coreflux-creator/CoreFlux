"""Exercise the exact native draft request again; preserve every business record."""
import json,hashlib,subprocess,urllib.request,urllib.error,os
from pathlib import Path
from datetime import datetime,timezone
P=Path('/srv/core-integration-pilot/20261029/private');WEB='core-provider-pilot-20261029-pure-pilot-web-1'
ORG='org_0369a63ff2cb';INPUT='cpi_06d372dcd75e8d388c33479be0933bc9ff35489f7d0d07b763d2f45d7b7bde55'
AUTHORITY='cpa_4a0bfee5f022202d92f0b09a87d9423e859c337d65c2e514cc9a3fcf09fc27dc'
RUN='run_core_54cb6e8156e6bfd5386a133ed5ea4d41c015aab8f45d25aeb0813cb090d35938'
STATE=P/'operational-payroll-replay-20261112.json'
def snapshot():
 code=r'''import os,json,hashlib
from pymongo import MongoClient
from bson import json_util
assert os.environ['DB_NAME']=='corepilot_pure_20261029' and os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']];out={}
for n in sorted(d.list_collection_names()):
 if n in ['sessions','login_events']:continue
 rows=[]
 for r in d[n].find({}):
  if n=='users' and r.get('id')=='usr_2d19df3dfa23':r.pop('last_login',None)
  rows.append(hashlib.sha256(json_util.dumps(r,sort_keys=True).encode()).hexdigest())
 out[n]={'rows':sorted(rows),'indexes':json_util.dumps(d[n].index_information(),sort_keys=True)}
r=d.pay_runs.find_one({'organization_id':'org_0369a63ff2cb','id':'RUN'});assert r['status']=='draft' and r['core_execution_hold'] and r['core_allocation_ready'] and r['totals']['gross']==100
assert d.pay_runs.count_documents({'organization_id':'org_0369a63ff2cb','core_allocation.authority_id':'AUTHORITY'})==1
print(json.dumps(out))'''.replace('RUN',RUN).replace('AUTHORITY',AUTHORITY)
 r=subprocess.run(['docker','exec','-i',WEB,'python','-'],input=code,text=True,capture_output=True,timeout=25)
 assert r.returncode==0,r.stderr[:500]
 return json.loads(r.stdout)
def request(path,token=None,body=None,key=None):
 assert path.startswith('/payroll/core-inputs/') or path in ['/auth/login','/security/overview','/security/sessions/revoke']
 h={'Content-Type':'application/json'}
 if token:h['Authorization']='Bearer '+token
 if key:h['Idempotency-Key']=key
 q=urllib.request.Request('http://127.0.0.1:19180/api'+path,None if body is None else json.dumps(body).encode(),h)
 try:
  with urllib.request.urlopen(q,timeout=15) as r:return r.status,json.load(r)
 except urllib.error.HTTPError as e:return e.code,json.load(e)
def save(v):
 with os.fdopen(os.open(STATE,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w') as f:json.dump(v,f,indent=2);f.flush();os.fsync(f.fileno())
def main():
 assert os.geteuid()==0 and not STATE.exists()
 before=snapshot();report={'checked_at':datetime.now(timezone.utc).isoformat(),'phase':'started','passed':False,'checks':[]};save(report)
 token=sid=None
 try:
  plan=json.loads((P/'thunderhawk-bootstrap-plan.private.json').read_text())
  status,v=request('/auth/login',body={'email':'thunderhawk-core-pilot@example.invalid','password':plan['pure_password']});del plan
  assert status==200 and v['organization']['id']==ORG and v['user']['id']=='usr_2d19df3dfa23'
  token=v['token'];del v
  status,v=request('/security/overview',token);assert status==200;sid=v['current_session_id']
  base='/payroll/core-inputs/'+INPUT
  status,v=request(base+'/draft-status',token);assert status==200 and v['execution_enabled'] is False
  items=[x for x in v['items'] if x['authority_id']==AUTHORITY];assert len(items)==1
  item=items[0];assert item['ready'] and item['run_id']==RUN and item['request'] and item['request_key']
  body=item['request'];key=item['request_key'];report['checks'].append('reload_recovers_original_request_and_run')
  status,v=request(base+'/draft',token,body,key);assert status==200 and v['run_id']==RUN and v['ready'] and v['execution_enabled'] is False
  report['checks'].append('exact_request_replays_same_held_draft')
  changed={**body,'pay_date':'2026-11-21'}
  status,v=request(base+'/draft',token,changed,key);assert status==409;report['checks'].append('same_key_changed_request_refused')
  new_key=hashlib.sha256(json.dumps(changed,sort_keys=True,separators=(',',':')).encode()).hexdigest()
  status,v=request(base+'/draft',token,changed,new_key);assert status==409;report['checks'].append('new_key_cannot_replace_existing_allocation')
  status,v=request(base+'/draft-review',token,{k:v for k,v in body.items() if k!='review_hash'});assert status==409;report['checks'].append('allocated_source_cannot_prepare_another_draft')
  status,v=request('/payroll/core-inputs/cpi_'+'0'*64+'/draft-status',token);assert status==404;report['checks'].append('unowned_input_not_exposed')
  report['phase']='verified'
 finally:
  if token and sid:
   status,v=request('/security/sessions/revoke',token,{'session_id':sid});assert status==200 and v['status']=='revoked';report['native_verification_session_retired']=True
  token=None
  after=snapshot();assert after==before
  report.update(business_rows_unchanged=sum(len(v['rows']) for v in before.values()),collections=len(before),run_id=RUN,gross='100.00',execution_hold=True,
   passed=report['phase']=='verified',finished_at=datetime.now(timezone.utc).isoformat());save(report)
 print(json.dumps(report))
if __name__=='__main__':main()
