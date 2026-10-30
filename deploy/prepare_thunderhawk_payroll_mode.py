"""Repair the first bootstrap's unused payroll_processing key in the isolated copy."""
import json
from pathlib import Path
import subprocess
import sys

if sys.argv[1:] != ['--repair-isolated-payroll-mode']:
    raise SystemExit('Explicit isolated repair argument required')
assert Path('/srv/core-integration-pilot/20261029/private/thunderhawk-bootstrap-plan.private.json').is_file()
code = '''import os,json
from datetime import datetime,timezone
from pymongo import MongoClient
assert os.environ['DB_NAME']=='corepilot_pure_20261029'
assert os.environ['MONGO_URL']=='mongodb://pure-pilot-mongo:27017'
assert os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot'
assert os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
d=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
org_id='org_0369a63ff2cb'
assert d.pilot_bindings.find_one({'_id':'thunderhawk-bootstrap-20261030','organization_id':org_id,'execution_held':True})
assert d.pay_runs.count_documents({'organization_id':org_id})==0
org=d.organizations.find_one({'id':org_id})
assert org and org['pilot_source']['execution_held'] is True
marker='thunderhawk-manual-payroll-mode-20261030'
prior=d.pilot_bindings.find_one({'_id':marker})
if not prior:
    assert org.get('payroll_processing')=={'calculation_method':'MANUAL'}
    result=d.organizations.update_one({'id':org_id,'payroll_processing':org['payroll_processing'],'payroll':org.get('payroll') if 'payroll' in org else {'$exists':False}},
        {'$set':{'payroll.processing.calculation':'MANUAL','payroll.processing.payments':'RECORD_ONLY'},'$unset':{'payroll_processing':''}})
    assert result.modified_count==1
    d.pilot_bindings.insert_one({'_id':marker,'organization_id':org_id,'reason':'Correct unused bootstrap config key',
        'calculation':'MANUAL','payments':'RECORD_ONLY','execution_held':True,'created_at':datetime.now(timezone.utc).isoformat()})
fresh=d.organizations.find_one({'id':org_id})
assert fresh['payroll']['processing']['calculation']=='MANUAL' and fresh['payroll']['processing']['payments']=='RECORD_ONLY'
assert 'payroll_processing' not in fresh
print(json.dumps({'passed':True,'organization_id':org_id,'calculation':'MANUAL','payments':'RECORD_ONLY','production_changed':False,'execution_held':True}))
'''
r = subprocess.run(['docker','exec','-i','core-provider-pilot-20261029-pure-pilot-web-1','python','-'],
                   input=code,text=True,capture_output=True)
if r.returncode:
    raise SystemExit('Isolated payroll mode repair failed; inspect bounded state before retrying')
print(r.stdout.strip())
