"""Copy one existing, projected payroll ledger record into the isolated pilot.

Production is queried read-only. Only observation-contract fields are copied;
no employee profiles, bank details, provider credentials or execution calls.
"""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import urllib.request

PRIVATE=Path('/srv/core-integration-pilot/20261029/private')
RUN='run_54dde5d17c05'
ORG='org_0369a63ff2cb'
SOURCE='payroll-web-pure-web-1'
TARGET='core-provider-pilot-20261029-pure-pilot-web-1'


def execute(container,code,payload=None):
    p=subprocess.run(['docker','exec','-i','-w','/app/backend',container,'python','-c',code],
        input=json.dumps(payload or {}),text=True,capture_output=True,timeout=30)
    if p.returncode:
        save('payroll-actual-copy-error.private.json',{'container':container,'stderr':p.stderr})
        lines=p.stderr.strip().splitlines()
        kind=lines[-1].split(':',1)[0] if lines else 'unknown'
        if not kind.isidentifier():kind='unknown'
        raise RuntimeError('Guarded payroll observation failed ('+kind+'); diagnostic saved privately')
    return json.loads(p.stdout)


def save(name,value):
    tmp=PRIVATE/(name+'.tmp')
    with os.fdopen(os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w',encoding='utf-8') as out:
        json.dump(value,out,sort_keys=True,indent=2)
    os.replace(tmp,PRIVATE/name)


SOURCE_CODE=r'''
import hashlib,json,os,sys
from bson import json_util
from pymongo import MongoClient
x=json.load(sys.stdin);projection=x['projection']
assert os.environ['DB_NAME']=='purepay'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
q={'organization_id':'org_0369a63ff2cb','id':'run_54dde5d17c05'}
assert d.pay_runs.count_documents(q)==1
row=d.pay_runs.find_one(q,{**projection,'organization_id':1})
assert row['status']=='paid' and row['source']=='provider_history' and row['pay_date']=='2026-10-23'
assert len(row['lines'])==18 and sum(l.get('employee_id')=='emp_ea232efec736' for l in row['lines'])==1
body=json_util.dumps(row,sort_keys=True,separators=(',',':'))
assert d.pay_runs.find_one(q,{**projection,'organization_id':1})==row
print(json.dumps({'projected_document_ejson':body,'document_sha256':hashlib.sha256(body.encode()).hexdigest()}))
'''

TARGET_CODE=r'''
import hashlib,json,os,socket,sys
from bson import json_util
from pymongo import MongoClient
from coreflux_payroll_observations import PROJECTION,observation
x=json.load(sys.stdin)
assert socket.gethostname()=='pure-core-pilot-20261029'
assert os.environ['DB_NAME']=='corepilot_pure_20261029' and os.environ['MONGO_URL']=='mongodb://pure-pilot-mongo:27017'
assert os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']];org='org_0369a63ff2cb';run='run_54dde5d17c05'
assert d.pilot_bindings.find_one({'_id':'thunderhawk-bootstrap-20261030','organization_id':org})
raw=x['projected_document_ejson'];assert hashlib.sha256(raw.encode()).hexdigest()==x['document_sha256']
row=json_util.loads(raw);assert row['organization_id']==org and row['id']==run and row['status']=='paid'
def protected():
    result={}
    for name in ['pay_runs','employees','assignments','customers','payments','payables','invoices',
                 'coreflux_payroll_inputs','coreflux_payroll_authorities','coreflux_record_links']:
        query={'organization_id':org}
        if name=='pay_runs':query['id']={'$ne':run}
        rows=list(d[name].find(query).sort('_id',1))
        result[name]={'count':len(rows),'sha256':hashlib.sha256(json_util.dumps(rows,sort_keys=True,separators=(',',':')).encode()).hexdigest()}
    return result
before=protected();q={'organization_id':org,'id':run}
existing=d.pay_runs.find_one(q)
marker={'purpose':'read_only_existing_payroll_observation','source_run_id':run,'source_document_sha256':x['document_sha256']}
if existing:
    assert existing.get('pilot_source')==marker and d.pay_runs.count_documents(q)==1
else:
    assert d.pay_runs.find_one({'_id':row['_id']}) is None
    d.pay_runs.insert_one(dict(row,pilot_source=marker))
copied=d.pay_runs.find_one(q,{**PROJECTION,'organization_id':1})
assert json_util.dumps(copied,sort_keys=True,separators=(',',':'))==raw
assert observation(copied,org)['observation_hash']==x['observation_hash']
assert protected()==before
print(json.dumps({'copied':not bool(existing),'existing_run_id_preserved':True,'source_observation_hash':x['observation_hash'],
    'other_pilot_records_unchanged':True,'protected':before}))
'''


if __name__=='__main__':
    if sys.argv[1:]!=['--stage-existing-observation'] or PRIVATE.resolve()!=PRIVATE or PRIVATE.stat().st_mode & 0o077:
        raise SystemExit('Protected isolated host and explicit observation-copy mode required')
    projection=execute(TARGET,'import json\nfrom coreflux_payroll_observations import PROJECTION\nprint(json.dumps(PROJECTION))')
    source=execute(SOURCE,SOURCE_CODE,{'projection':projection})
    expected=execute(TARGET,r'''
import json,os,sys
from bson import json_util
from coreflux_payroll_observations import observation
assert os.environ['DB_NAME']=='corepilot_pure_20261029'
x=json.load(sys.stdin);row=json_util.loads(x['projected_document_ejson'])
print(json.dumps(observation(row,'org_0369a63ff2cb')))
''',source)
    source.update(observation_hash=expected['observation_hash'],observation=expected)
    save('thunderhawk-payroll-actual-source.private.json',source)
    receipt=execute(TARGET,TARGET_CODE,source)
    plan=json.loads((PRIVATE/'thunderhawk-bootstrap-plan.private.json').read_text(encoding='utf-8'))
    request=urllib.request.Request('http://127.0.0.1:19180/api/v1/integrations/coreflux/payroll/observations/'+RUN,
        headers={'Authorization':'Bearer '+plan['pure_key']})
    with urllib.request.urlopen(request,timeout=30) as response:
        observed=json.load(response)
        assert response.status==200
    assert observed['observation_hash']==source['observation_hash'] and observed['organization_id']==ORG
    again=execute(SOURCE,SOURCE_CODE,{'projection':projection})
    assert again['document_sha256']==source['document_sha256']
    save('thunderhawk-payroll-actual-http.private.json',observed)
    report={'passed':True,'run_id':RUN,'organization_id':ORG,'source':'provider_history','status':'paid',
        'period_start':observed['period_start'],'period_end':observed['period_end'],'pay_date':observed['pay_date'],
        'line_count':len(observed['lines']),'source_document_sha256':source['document_sha256'],
        'source_record_unchanged':True,'native_http_matches_source':True,'production_changed':False,
        'source_capture_method':'Read-only database projection; new observation API is deployed in the isolated pilot only',
        'payroll_executed':False,'financial_postings':False,'cash_delivery_proven':False,**receipt}
    save('thunderhawk-payroll-actual-copy-receipt.json',report)
    print(json.dumps(report))
