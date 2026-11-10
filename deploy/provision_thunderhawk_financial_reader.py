"""Provision a read-only key only inside the existing isolated provider pilot."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import urllib.error
import urllib.request
import uuid

ROOT = Path('/srv/core-integration-pilot/20261029')
PRIVATE = ROOT / 'private'
CONTAINER = 'core-provider-pilot-20261029-pure-pilot-web-1'
ORG = 'org_0369a63ff2cb'
if sys.argv[1:] != ['--provision-read-only-financial-key'] or ROOT.resolve() != ROOT or not ROOT.is_dir():
    raise SystemExit('Exact isolated host and explicit provisioning required')
if PRIVATE.resolve() != PRIVATE or PRIVATE.stat().st_mode & 0o077:
    raise SystemExit('Private directory permissions differ')

path = PRIVATE / 'thunderhawk-financial-reader.private.json'
if path.is_symlink():
    raise SystemExit('Linked credential file')
if not path.exists():
    packet = {'purpose': 'isolated_thunderhawk_financial_read_only', 'tenant_id': 2002,
              'organization_id': ORG, 'key_id': 'key_' + uuid.uuid4().hex[:12],
              'api_key': 'pk_pilot_' + secrets.token_urlsafe(32), 'scope': 'read'}
    with os.fdopen(os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600), 'w') as stream:
        json.dump(packet, stream)
if path.stat().st_mode & 0o077:
    raise SystemExit('Credential permissions differ')
packet = json.loads(path.read_text())
assert packet['purpose'] == 'isolated_thunderhawk_financial_read_only'
assert packet['tenant_id'] == 2002 and packet['organization_id'] == ORG and packet['scope'] == 'read'

code = r'''
import hashlib,json,os,socket,sys
from datetime import datetime,timezone
from bson import json_util
from pymongo import MongoClient
from coreflux_exchange import validate_key_scopes
p=json.load(sys.stdin)
assert socket.gethostname()=='pure-core-pilot-20261029'
assert os.environ['DB_NAME']=='corepilot_pure_20261029'
assert os.environ['MONGO_URL']=='mongodb://pure-pilot-mongo:27017'
assert os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot'
assert os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
db=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
org=db.organizations.find_one({'id':p['organization_id']})
assert org and 'isolated pilot' in org['name']
def snapshot():
    result={}
    for name in sorted(db.list_collection_names()):
        if name=='api_keys':
            rows=list(db[name].find({'id':{'$ne':p['key_id']}}))
        else:
            rows=list(db[name].find({}))
        if rows:
            values=sorted(json_util.dumps(x,sort_keys=True) for x in rows)
            result[name]=hashlib.sha256(json.dumps(values).encode()).hexdigest()
    return result
before=snapshot()
assert validate_key_scopes('read',[])==[]
record={'id':p['key_id'],'organization_id':p['organization_id'],
        'name':'Core staging Thunderhawk financial reader',
        'key_prefix':p['api_key'][:16],'last4':p['api_key'][-4:],
        'key_hash':hashlib.sha256(p['api_key'].encode()).hexdigest(),
        'scope':'read','integration_scopes':[],'revoked':False,
        'created_by':'Core isolated pilot setup',
        'created_at':datetime.now(timezone.utc).isoformat(),'last_used_at':None}
existing=db.api_keys.find_one({'id':p['key_id']})
created=existing is None
if existing:
    for name,value in record.items():
        if name not in ('created_at','last_used_at'):assert existing.get(name)==value
else:
    assert db.api_keys.count_documents({'key_prefix':record['key_prefix']})==0
    assert db.api_keys.count_documents({'organization_id':p['organization_id'],'name':record['name']})==0
    db.api_keys.insert_one(record)
assert snapshot()==before
print(json.dumps({'created':created,'scope':'read','integration_scopes':[],
                 'other_keys_and_provider_business_records_unchanged':True}))
'''
result = subprocess.run(['docker', 'exec', '-i', '-w', '/app/backend', CONTAINER, 'python', '-c', code],
                        input=json.dumps(packet), text=True, capture_output=True)
if result.returncode:
    raise SystemExit('Isolated financial key provisioning failed; no credential output retained')
report = json.loads(result.stdout)

def request(method, route, payload=None):
    req = urllib.request.Request('http://127.0.0.1:19180/api/v1' + route,
          data=json.dumps(payload).encode() if payload is not None else None, method=method,
          headers={'Authorization': 'Bearer ' + packet['api_key'], 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=25) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as error:
        return error.code, json.load(error)

status, context = request('GET', '/context')
assert status == 200 and context['organization']['id'] == ORG
assert context['key_scope'] == 'read' and context['execution_authority'] is False
assert 'isolated pilot' in context['organization']['name']
status, denial = request('POST', '/bills/nonexistent-financial-reader-proof/pay', {})
# The isolated host holds execution before the route's read-only-key check.
assert status == 403 or (status == 409 and denial.get('code') == 'isolated_pilot_operation_held')
report.update({'passed': True, 'organization_id': ORG, 'tenant_id': 2002,
               'context_verified': True, 'payment_write_denied': True,
               'payment_denial_http_status': status,
               'payment_denial_boundary': 'isolation_hold' if status == 409 else 'read_only_key',
               'production_changed': False, 'payroll_or_payment_execution': False})
print(json.dumps(report))
