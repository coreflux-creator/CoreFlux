"""Verify the deployed native payment reads without executing provider synchronization."""
import json,os,subprocess,urllib.request,urllib.error
from pathlib import Path
from datetime import datetime,timezone
PRIVATE=Path('/srv/core-integration-pilot/20261029/private')
STATE=PRIVATE/'payment-display-native-reads-20261112.json'
WEB='core-provider-pilot-20261029-pure-pilot-web-1'
IDS=['pmt_87cf14ddb4','pmt_274cf7d401'];ORG='org_0369a63ff2cb'
PAYROLL_STATUS='/payroll/core-inputs/cpi_06d372dcd75e8d388c33479be0933bc9ff35489f7d0d07b763d2f45d7b7bde55/draft-status'

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
payments=[]
for pid in ['pmt_87cf14ddb4','pmt_274cf7d401']:
 p=d.payments.find_one({'id':pid,'organization_id':'org_0369a63ff2cb'})
 assert p and p['status'] in {'settled','failed','returned','canceled'},'Nonterminal payment requires separate synchronization review'
 payments.append({k:p.get(k) for k in ['id','organization_id','amount','status','invoice_number']})
print(json.dumps({'tables':out,'payments':payments}))'''
 r=subprocess.run(['docker','exec','-i',WEB,'python','-'],input=code,text=True,capture_output=True,timeout=25)
 assert r.returncode==0,r.stderr[:500]
 return json.loads(r.stdout)

def request(path,token=None,body=None):
 assert path in ['/auth/login','/security/overview','/security/sessions/revoke',PAYROLL_STATUS]+['/payments/'+v for v in IDS]
 headers={'Content-Type':'application/json'}
 if token:headers['Authorization']='Bearer '+token
 req=urllib.request.Request('http://127.0.0.1:19180/api'+path,None if body is None else json.dumps(body).encode(),headers)
 try:
  with urllib.request.urlopen(req,timeout=15) as r:return r.status,json.load(r)
 except urllib.error.HTTPError as e:return e.code,json.load(e)

def save(v):
 with os.fdopen(os.open(STATE,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w') as f:json.dump(v,f,indent=2);f.flush();os.fsync(f.fileno())

def main():
 assert os.geteuid()==0 and PRIVATE.resolve()==PRIVATE and (PRIVATE.stat().st_mode&0o777)==0o700 and not STATE.exists()
 install=json.loads((PRIVATE/'payment-display-release-v3-20261112.json').read_text());assert install['passed'] and install['phase']=='complete'
 before=snapshot();report={'started_at':datetime.now(timezone.utc).isoformat(),'phase':'started','passed':False,'checks':[]};save(report)
 token=sid=None
 try:
  plan=json.loads((PRIVATE/'thunderhawk-bootstrap-plan.private.json').read_text())
  status,v=request('/auth/login',body={'email':'thunderhawk-core-pilot@example.invalid','password':plan['pure_password']});del plan
  assert status==200 and v['organization']['id']==ORG and v['user']['id']=='usr_2d19df3dfa23'
  token=v['token'];del v
  status,v=request('/security/overview',token);assert status==200;sid=v['current_session_id']
  for attempt in range(2):
   for original in before['payments']:
    status,v=request('/payments/'+original['id'],token)
    assert status==200
    row=v['payment'];assert all(row.get(k)==v for k,v in original.items())
    assert row['invoice_number']=='Sep_26'
  report['checks']=['both_terminal_payments_load_through_native_detail_route','repeated_reads_retain_labels_and_original_financial_fields','terminal_status_guard_excludes_provider_synchronization']
  status,v=request(PAYROLL_STATUS,token)
  assert status==200 and v['execution_enabled'] is False
  items=[r for r in v['items'] if r['authority_id']=='cpa_4a0bfee5f022202d92f0b09a87d9423e859c337d65c2e514cc9a3fcf09fc27dc']
  assert len(items)==1 and items[0]['ready'] and items[0]['run_id']=='run_core_54cb6e8156e6bfd5386a133ed5ea4d41c015aab8f45d25aeb0813cb090d35938'
  report['checks'].append('existing_held_payroll_draft_remains_recoverable')
  report['phase']='verified'
 finally:
  if token and sid:
   status,v=request('/security/sessions/revoke',token,{'session_id':sid});assert status==200 and v['status']=='revoked';report['verification_session_retired']=True
  token=None
  after=snapshot();assert after==before,'Native payment reads changed saved business records'
  report.update(business_rows_unchanged=sum(len(v['rows']) for v in before['tables'].values()),collections=len(before['tables']),
   passed=report['phase']=='verified',finished_at=datetime.now(timezone.utc).isoformat(),real_financial_execution=False,existing_payment_records_written=False)
  save(report)
 print(json.dumps(report))

if __name__=='__main__':main()
