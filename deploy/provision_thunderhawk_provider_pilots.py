"""One-time private-host bootstrap. Never writes to production provider databases."""
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import uuid

ROOT = Path('/srv/core-integration-pilot/20261029')
PRIVATE = ROOT / 'private'
if sys.argv[1:] != ['--bootstrap-held-pilot'] or not ROOT.is_dir() or ROOT.resolve() != ROOT:
    raise SystemExit('Exact isolated host and explicit bootstrap required')
if PRIVATE.resolve() != PRIVATE or PRIVATE.stat().st_mode & 0o077:
    raise SystemExit('Private credential directory is not protected')


def save_private(name, value):
    fd = os.open(PRIVATE / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w') as stream:
        json.dump(value, stream)


def container_script(container, code, payload):
    result = subprocess.run(['docker', 'exec', '-i', '-w', '/app/backend', container, 'python', '-c', code],
                            input=json.dumps(payload), text=True, capture_output=True)
    if result.returncode:
        # Do not echo provider error messages or source values into chat/logs.
        raise RuntimeError('Isolated bootstrap failed in ' + container + '; inspect privately')
    return json.loads(result.stdout)


pure_source = json.loads((PRIVATE / 'pure-source.private.json').read_text())
quanta_source = json.loads((PRIVATE / 'quanta-source.private.json').read_text())
core_source = json.loads((PRIVATE / 'core-source.private.json').read_text(encoding='utf-8-sig'))
assert pure_source['organization']['id'] == 'org_0369a63ff2cb'
assert pure_source['employee']['id'] == 'emp_ea232efec736'
assert pure_source['employee']['organization_id'] == pure_source['organization']['id']
assert pure_source['employee']['email'].strip().lower() == core_source['person']['email_primary'].strip().lower()
assert len(pure_source['customers']) == 1 and pure_source['customers'][0]['id'] == 'cus_e25f880f86'
assert pure_source['customers'][0]['organization_id'] == pure_source['organization']['id']
assert quanta_source['tenant']['id'] == '4f93d4e7-af74-4765-b812-3464bb6d974b' and quanta_source['workers'] == 0
assert core_source['person']['id'] == 358 and core_source['placement']['id'] == 864

plan_path = PRIVATE / 'thunderhawk-bootstrap-plan.private.json'
if plan_path.exists():
    raise SystemExit('Private bootstrap plan already exists. Inspect completion before any retry.')
plan = {'purpose': 'held_thunderhawk_integration_pilot', 'core_tenant_id': 2002,
        'pure_key': 'pk_pilot_' + secrets.token_urlsafe(32), 'quanta_key': 'ck_live_' + secrets.token_urlsafe(32),
        'pure_user_id': 'usr_' + uuid.uuid4().hex[:12], 'pure_key_id': 'key_' + uuid.uuid4().hex[:12],
        'quanta_admin_id': str(uuid.uuid4()), 'quanta_approver_id': str(uuid.uuid4()),
        'quanta_admin_identity': str(uuid.uuid4()), 'quanta_approver_identity': str(uuid.uuid4()),
        'quanta_key_id': str(uuid.uuid4()),
        'pure_password': secrets.token_urlsafe(36), 'quanta_admin_password': secrets.token_urlsafe(36),
        'quanta_approver_password': secrets.token_urlsafe(36),
        'source_hashes': {name: hashlib.sha256((PRIVATE / name).read_bytes()).hexdigest()
                          for name in ['pure-source.private.json', 'quanta-source.private.json', 'core-source.private.json']}}
save_private(plan_path.name, plan)

PURE = r'''
import hashlib,json,os,socket,sys
from datetime import datetime,timezone
from pymongo import MongoClient
from auth import hash_password
from coreflux_exchange import validate_key_scopes
x=json.load(sys.stdin);p=x['plan'];source=x['source']
assert socket.gethostname()=='pure-core-pilot-20261029'
assert os.environ['DB_NAME']=='corepilot_pure_20261029'
assert os.environ['MONGO_URL']=='mongodb://pure-pilot-mongo:27017'
assert os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot' and os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
db=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
assert db.pilot_bindings.find_one({'_id':'core-provider-pilot-20261029','purpose':'isolated_core_integration','provider_egress_enabled':False})
for c in db.list_collection_names():
    if c!='pilot_bindings':assert db[c].count_documents({})==0, 'Pilot must be empty'
org=source['organization'];org={**org,'name':org['name']+' — isolated pilot',
    'features':{'payroll':True},
    'payroll':{**(org.get('payroll') or {}),'processing':{
        **((org.get('payroll') or {}).get('processing') or {}),
        'calculation':'MANUAL','payments':'RECORD_ONLY'}},
    'pilot_source':{'organization_id':org['id'],'origin':'https://purepay.online','execution_held':True}}
employee={**source['employee'],'pay_method':'record_only',
    'pilot_source':{'employee_id':source['employee']['id'],'payment_details_excluded':True}}
stamp=datetime.now(timezone.utc).isoformat()
role={'id':'role_thunderhawk_core_pilot','organization_id':org['id'],'key':'core_pilot_reviewer',
    'name':'Core pilot reviewer','permissions':['payables.view','payables.create'],'is_owner':False}
user={'id':p['pure_user_id'],'organization_id':org['id'],'role':role['key'],
    'name':'Thunderhawk isolated pilot operator','email':'thunderhawk-core-pilot@example.invalid',
    'status':'active','password_hash':hash_password(p['pure_password']),'created_at':stamp}
scopes=validate_key_scopes('core',['read:coreflux','write:coreflux','read:payroll','read:people_setup',
    'write:people_inputs','write:assignment_inputs','write:customer_inputs','write:payroll_inputs','write:payroll_authority'])
key={'id':p['pure_key_id'],'organization_id':org['id'],'name':'Core staging Thunderhawk isolated pilot',
    'key_prefix':p['pure_key'][:16],'last4':p['pure_key'][-4:],
    'key_hash':hashlib.sha256(p['pure_key'].encode()).hexdigest(),'scope':'core','integration_scopes':scopes,
    'revoked':False,'created_by':user['name'],'created_at':stamp,'last_used_at':None}
db.organizations.insert_one(org);db.roles.insert_one(role);db.users.insert_one(user)
db.employees.insert_one(employee);db.customers.insert_one(source['customers'][0]);db.api_keys.insert_one(key)
receipt={'_id':'thunderhawk-bootstrap-20261030','organization_id':org['id'],'employee_id':employee['id'],
    'customer_id':source['customers'][0]['id'],'operator_id':user['id'],'api_key_id':key['id'],
    'source_hashes':p['source_hashes'],'scopes':scopes,'execution_held':True,'created_at':stamp}
db.pilot_bindings.insert_one(receipt)
print(json.dumps(receipt))
'''

QUANTA = r'''
import json,os,sys
from pymongo import MongoClient
from app.models import Tenant,Identity,User,ApiKey
from app.security import hash_password
from app.webhook_signing import api_key_prefix,hash_api_key
from app.api_scopes import is_known_scope
x=json.load(sys.stdin);p=x['plan'];source=x['source'];t=source['tenant']
assert os.environ['DB_NAME']=='corepilot_quanta_20261029'
assert os.environ['MONGO_URL']=='mongodb://quanta-pilot-mongo:27017'
assert os.environ['QUANTA_STARTUP_MODE']=='web' and os.environ['DISABLE_BACKGROUND_WORKERS']=='1'
assert os.environ['DISABLE_SCHEDULER']=='1' and os.environ['MAIL_DRIVER']=='devoutbox'
db=MongoClient(os.environ['MONGO_URL'])[os.environ['DB_NAME']]
for c in db.list_collection_names():assert db[c].count_documents({})==0,'Pilot must be empty'
tenant=Tenant(id=t['id'],slug='thunderhawk-core-pilot',name=t['name']+' — isolated pilot',
    timezone=t['timezone'],status=t['status'],currency=t.get('currency','USD'),auth_mode='native')
scopes=['read:coreflux','write:coreflux','read:workers','write:workers','read:worksites',
    'read:dimensions','read:entries','read:timesheets','read:core_onboarding',
    'write:core_onboarding','read:core_onboarding_documents']
assert all(is_known_scope(s) for s in scopes)
docs=[]
for kind,role in [('admin','tenant_admin'),('approver','approver')]:
    email='thunderhawk-core-pilot-'+kind+'@example.invalid'
    identity=Identity(id=p['quanta_'+kind+'_identity'],email=email,
        password_hash=hash_password(p['quanta_'+kind+'_password']),
        full_name='Thunderhawk pilot '+kind,last_tenant_id=tenant.id)
    user=User(id=p['quanta_'+kind+'_id'],tenant_id=tenant.id,email=email,identity_id=identity.id,
        full_name=identity.full_name,roles=[role],timezone=tenant.timezone,status='active')
    docs.append((identity,user))
key=ApiKey(id=p['quanta_key_id'],tenant_id=tenant.id,name='Core staging Thunderhawk isolated pilot',
    key_prefix=api_key_prefix(p['quanta_key']),key_hash=hash_api_key(p['quanta_key']),scopes=scopes,
    created_by_user_id=p['quanta_admin_id'])
db.tenants.insert_one(tenant.to_mongo())
for identity,user in docs:db.identities.insert_one(identity.to_mongo());db.users.insert_one(user.to_mongo())
db.api_keys.insert_one(key.to_mongo())
receipt={'_id':'thunderhawk-bootstrap-20261030','tenant_id':tenant.id,'operator_id':p['quanta_admin_id'],
    'approver_id':p['quanta_approver_id'],'api_key_id':key.id,'scopes':scopes,'source_hashes':p['source_hashes'],
    'workers_seeded':0,'external_mail_enabled':False,'background_workers_enabled':False}
db.pilot_bindings.insert_one(receipt)
print(json.dumps(receipt))
'''

pure = container_script('core-provider-pilot-20261029-pure-pilot-web-1', PURE, {'plan': plan, 'source': pure_source})
save_private('thunderhawk-pure-bootstrap-receipt.json', pure)
quanta = container_script('core-provider-pilot-20261029-quanta-pilot-web-1', QUANTA, {'plan': plan, 'source': quanta_source})
save_private('thunderhawk-quanta-bootstrap-receipt.json', quanta)
save_private('thunderhawk-core-connection-keys.private.json', {'purepay_key': plan['pure_key'], 'quanta_key': plan['quanta_key']})
print(json.dumps({'passed': True, 'pure': pure, 'quanta': quanta,
                  'production_changed': False, 'notifications_sent': False,
                  'worker_access_created': False, 'source_email_matched': True,
                  'credentials_returned': False, 'credentials_saved_privately': True}))
