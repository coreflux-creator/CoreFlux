"""Hosted, native-HTTP company isolation and conflict proof on the isolated pilot.

Creates a second synthetic company in each isolated database. Credentials stay in
the host's private directory and are revoked even after a failed assertion.
No production connection, payroll execution or financial posting is attempted.
"""
import copy
import datetime
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path('/srv/core-integration-pilot/20261029')
PRIVATE = ROOT / 'private'
Q = '4f93d4e7-af74-4765-b812-3464bb6d974b'
P = 'org_0369a63ff2cb'
PREFIX = '/api/v1/integrations/coreflux'
RESULTS = []


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode()).hexdigest()


def save(name, value):
    path = PRIVATE / name
    tmp = path.with_suffix('.tmp')
    with os.fdopen(os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), 'w', encoding='utf-8') as out:
        json.dump(value, out, sort_keys=True, indent=2)
    os.replace(tmp, path)


DB_PREAMBLE = r'''
import hashlib,json,os,sys
from datetime import datetime,timezone
from pymongo import MongoClient
x=json.load(sys.stdin)
provider=x['provider']
database='corepilot_quanta_20261029' if provider=='quanta' else 'corepilot_pure_20261029'
mongo='mongodb://quanta-pilot-mongo:27017' if provider=='quanta' else 'mongodb://pure-pilot-mongo:27017'
assert os.environ['DB_NAME']==database and os.environ['MONGO_URL']==mongo
if provider=='quanta':
    assert os.environ['QUANTA_STARTUP_MODE']=='web'
    assert os.environ['DISABLE_BACKGROUND_WORKERS']=='1' and os.environ['DISABLE_SCHEDULER']=='1'
    assert os.environ['MAIL_DRIVER']=='devoutbox'
else:
    assert os.environ['PUREPAY_STARTUP_MODE']=='isolated_pilot'
    assert os.environ['PUREPAY_PROVIDER_EGRESS']=='disabled'
d=MongoClient(mongo)[database]
company='4f93d4e7-af74-4765-b812-3464bb6d974b' if provider=='quanta' else 'org_0369a63ff2cb'
field='tenant_id' if provider=='quanta' else 'organization_id'
assert d.pilot_bindings.find_one({'_id':'thunderhawk-bootstrap-20261030',field:company})
'''


def database(provider, code, **payload):
    container = 'core-provider-pilot-20261029-' + ('quanta' if provider == 'quanta' else 'pure') + '-pilot-web-1'
    result = subprocess.run(['docker', 'exec', '-i', '-w', '/app/backend', container, 'python', '-c', DB_PREAMBLE + code],
                            input=json.dumps(dict(payload, provider=provider)), text=True, capture_output=True, timeout=45)
    if result.returncode:
        save('isolation-diagnostic.private.json', {'provider': provider, 'stderr': result.stderr})
        raise RuntimeError('Isolated database operation failed; diagnostic retained privately')
    return json.loads(result.stdout)


def snapshot(provider, baseline=None):
    return database(provider, r'''
names=(['tenants','users','worksites','worksite_assignments','time_entries','timesheets'] if provider=='quanta'
       else ['organizations','employees','customers','vendors','assignments','pay_runs','payments','bills','invoices'])
names+=sorted(n for n in d.list_collection_names() if n.startswith('coreflux'))
result={}
for name in sorted(set(names)):
    query={field:company}
    if name in ('tenants','organizations'):query={'id':company}
    if name.startswith('coreflux_onboarding_files.'):query={}
    # New rejected-request receipts are expected; original receipts must remain byte-identical.
    if name=='coreflux_deliveries' and x.get('baseline'):
        query['_id']={'$in':x['baseline'][name]['ids']}
    rows=list(d[name].find(query))
    rows.sort(key=lambda row:str(row['_id']))
    result[name]={'count':len(rows),'sha256':hashlib.sha256(json.dumps(rows,sort_keys=True,separators=(',',':'),default=str).encode()).hexdigest()}
    if name=='coreflux_deliveries':result[name]['ids']=[r['_id'] for r in rows]
print(json.dumps(result))
''', baseline=baseline)


def equivalent_snapshots(before, after):
    # A provider may create a collection for the control company's first write.
    # Absence and an empty Thunderhawk query are equivalent, never a wildcard
    # exclusion of nonempty collections or changed rows.
    empty={'count':0,'sha256':hashlib.sha256(b'[]').hexdigest()}
    return all(before.get(name,empty)==after.get(name,empty) for name in before.keys() | after.keys())


def bootstrap(provider, plan, credentials):
    return database(provider, r'''
p=x['plan'];k=x['credentials'];stamp=datetime.now(timezone.utc).isoformat()
other=p['quanta_company'] if provider=='quanta' else p['pure_company']
collection=d.tenants if provider=='quanta' else d.organizations
existing=collection.find_one({'id':other})
if existing:assert existing.get('isolation_fixture')=='thunderhawk-hosted-20261030'
else:
    if provider=='quanta':
        from app.models import Tenant
        row=Tenant(id=other,slug='core-isolation-control-20261030',name='TEST Core isolation control',
            timezone='America/New_York',status='active',currency='USD',auth_mode='native').to_mongo()
    else:
        row={'id':other,'name':'TEST Core isolation control','status':'active','features':{'payroll':True},
            'payroll':{'processing':{'calculation':'MANUAL','payments':'RECORD_ONLY'}}}
    row['isolation_fixture']='thunderhawk-hosted-20261030';collection.insert_one(row)
if provider=='quanta':
    from app.models import ApiKey
    from app.webhook_signing import api_key_prefix,hash_api_key
    source=d.api_keys.find_one({'tenant_id':company,'revoked_at':None})
    assert source and 'write:coreflux' in source['scopes']
    row=ApiKey(id=k['quanta_id'],tenant_id=other,name='Temporary hosted isolation verification',
        key_prefix=api_key_prefix(k['quanta']),key_hash=hash_api_key(k['quanta']),scopes=source['scopes']).to_mongo()
else:
    from coreflux_exchange import validate_key_scopes
    source=d.api_keys.find_one({'organization_id':company,'revoked':{'$ne':True}})
    assert source
    scopes=validate_key_scopes('core',source['integration_scopes'])
    row={'id':k['pure_id'],'organization_id':other,'name':'Temporary hosted isolation verification',
        'key_prefix':k['pure'][:16],'last4':k['pure'][-4:],'key_hash':hashlib.sha256(k['pure'].encode()).hexdigest(),
        'scope':'core','integration_scopes':scopes,'revoked':False,'created_at':stamp,'created_by':'Isolated acceptance harness'}
    # Minimal native records only in the control organization. No login or payment details.
    for collection,doc in [('employees',{'id':p['employee'],'organization_id':other,'name':'TEST Isolation worker',
        'email':'core-isolation-worker@example.com','status':'inactive','employment_type':'w2','pay_method':'record_only'}),
        ('assignments',{'id':p['assignment'],'organization_id':other,'employee_id':p['employee'],
        'status':'draft','start_date':'2026-10-26','end_date':'2026-10-30','auto_link':False,'auto_bills':False,'bills_drive_invoicing':False})]:
        old=d[collection].find_one({'id':doc['id']})
        if old:assert old.get('organization_id')==other and old.get('isolation_fixture')=='thunderhawk-hosted-20261030'
        else:d[collection].insert_one(dict(doc,isolation_fixture='thunderhawk-hosted-20261030'))
d.api_keys.insert_one(row)
print(json.dumps({'company_id':other,'key_id':row['id'],'notifications_sent':False}))
''', plan=plan, credentials=credentials)


def revoke(provider, credentials):
    return database(provider, r'''
k=x['credentials'];kid=k['quanta_id'] if provider=='quanta' else k['pure_id']
patch={'revoked_at':datetime.now(timezone.utc).isoformat()} if provider=='quanta' else {'revoked':True}
r=d.api_keys.update_one({'id':kid},{'$set':patch})
assert r.matched_count==1
print(json.dumps({'revoked':True,'key_id':kid}))
''', credentials=credentials)


def apply_document_workspace_config(expected, replacement, original_key):
    """Change only the isolated document allowlist, preserving all other settings."""
    path=PRIVATE/'quanta.env'
    if path.read_bytes()!=expected or path.stat().st_mode & 0o077:
        raise RuntimeError('Private Quanta configuration changed; refusing to overwrite it')
    tmp=PRIVATE/'quanta.env.isolation.tmp'
    with os.fdopen(os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'wb') as out:
        out.write(replacement)
    os.replace(tmp,path)
    cmd=['docker','compose','-p','core-provider-pilot-20261029','-f',str(ROOT/'compose.yaml'),
         '-f',str(ROOT/'compose-https.yaml'),'up','-d','--no-deps','--pull','never','quanta-pilot-web']
    result=subprocess.run(cmd,text=True,capture_output=True,timeout=45)
    if result.returncode:
        save('isolation-restart-diagnostic.private.json',{'stderr':result.stderr})
        raise RuntimeError('Isolated Quanta refresh failed; diagnostic retained privately')
    request=urllib.request.Request('http://127.0.0.1:19181'+PREFIX+'/context',
        headers={'Authorization':'Bearer '+original_key})
    for attempt in range(12):
        try:
            with urllib.request.urlopen(request,timeout=2) as response:
                if response.status==200 and json.load(response)['workspace']['id']==Q:
                    return
        except (OSError,ValueError):
            pass
        time.sleep(1)
    raise RuntimeError('Quanta did not pass its native authenticated health check after refresh')


def call(provider, key, name, path, expected=200, body=None, method=None, idem=None):
    headers = {'Authorization': 'Bearer ' + key, 'Content-Type': 'application/json'}
    if body is not None:
        headers['Idempotency-Key'] = idem or digest(['isolation-20261030', name, body])
    request = urllib.request.Request('http://127.0.0.1:' + ('19181' if provider == 'quanta' else '19180') + path,
        data=None if body is None else json.dumps(body).encode(), headers=headers, method=method)
    try:
        response = urllib.request.urlopen(request, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        code = response.status
        raw = response.read()
        try: payload = json.loads(raw)
        except (ValueError, UnicodeDecodeError): payload = {'non_json_bytes':len(raw)}
        replayed = response.headers.get('Idempotency-Replayed')
    RESULTS.append({'provider':provider,'check':name,'http_status':code,'expected_status':expected,'passed':code==expected})
    if code != expected:
        save('isolation-http-error.private.json', {'check':name,'status':code,'payload':payload})
        raise RuntimeError(name + ': unexpected HTTP ' + str(code) + '; private diagnostic saved')
    return payload, replayed


def require(value, name):
    RESULTS.append({'check':name,'passed':bool(value)})
    if not value: raise RuntimeError(name)


def quanta_checks(original, control):
    qctx,_=call('quanta',control,'control company context',PREFIX+'/context')
    require(qctx['workspace']['id']==plan['quanta_company'],'Quanta control credential belongs to control workspace')
    data=database('quanta',r'''
a=d.coreflux_assignments.find_one({'tenant_id':company});o=d.coreflux_onboarding.find_one({'tenant_id':company})
l=d.coreflux_person_links.find_one({'tenant_id':company});deliveries=list(d.coreflux_deliveries.find({'tenant_id':company},{'_id':1}))
print(json.dumps({'assignment':a,'onboarding':o,'link':l,'deliveries':[r['_id'] for r in deliveries]},default=str))
''')
    a,o,l=data['assignment'],data['onboarding'],data['link']
    worker,_=call('quanta',original,'original worker profile readable',PREFIX+'/worker-profiles/'+l['id'])
    body={'person':a['person'],'email':'core-isolation-worker@example.com','full_name':'TEST Isolation worker'}
    prior=database('quanta',r'''
row=d.coreflux_person_links.find_one({'tenant_id':x['other']})
print(json.dumps({'link_id':row['id'] if row else None}))
''',other=plan['quanta_company'])
    if prior['link_id']:
        control_worker,_=call('quanta',control,'read existing disabled control worker',PREFIX+'/worker-profiles/'+prior['link_id'])
        first,_=call('quanta',control,'duplicate control worker remains a conflict',PREFIX+'/worker-profiles',409,body)
        again,replay=call('quanta',control,'duplicate control worker remains a conflict',PREFIX+'/worker-profiles',409,body)
        require(first==again and replay=='true','Duplicate control worker rejection is replayable')
    else:
        control_worker,_=call('quanta',control,'create disabled control worker with same Core person number',PREFIX+'/worker-profiles',201,body)
        again,_=call('quanta',control,'create disabled control worker with same Core person number',PREFIX+'/worker-profiles',201,body)
        require(again==control_worker,'Control worker creation replays original receipt')
    require(control_worker['worker']['status']=='disabled' and not control_worker['invitation_sent'],
            'Control worker replay preserves identity without granting access')
    fields=('placement','person','client','vendor','end_client','engagement_type','start_date','end_date','work_location')
    assignment={k:a[k] for k in fields if k in a}
    assignment.update(title='TEST Isolation assignment',status='draft',worksite_id=None)
    prior=database('quanta',r'''
row=d.coreflux_assignments.find_one({'tenant_id':x['other']})
print(json.dumps({'assignment_id':row['id'] if row else None}))
''',other=plan['quanta_company'])
    if prior['assignment_id']:
        control_assignment,_=call('quanta',control,'read existing control assignment',PREFIX+'/assignments/'+prior['assignment_id'])
        call('quanta',control,'duplicate control assignment does not create a second placement',PREFIX+'/assignments',409,assignment)
    else:
        control_assignment,_=call('quanta',control,'create separate assignment with same Core placement number',PREFIX+'/assignments',201,assignment)
    require(control_assignment['id']!=a['id'] and control_assignment['worker_id']==control_worker['worker_id'],
            'Quanta canonical identities are namespaced by workspace')
    for suffix in ['/person-links/'+l['id'],'/worker-profiles/'+l['id'],'/assignments/'+a['id'],
                   '/onboarding/'+o['id']]+['/deliveries/'+i for i in data['deliveries']]:
        call('quanta',control,'foreign read denied '+suffix,PREFIX+suffix,404)
    call('quanta',control,'foreign worker directory read denied','/api/v1/workers/'+a['worker_id'],404)
    source='69f7fc04-ce35-497b-ad2d-589eabca1455'
    call('quanta',control,'foreign time review denied',PREFIX+'/time-review-context?'+urllib.parse.urlencode({'assignment_id':a['id'],'source_entry_id':source}),404)
    source_ids=['ad44e619-7f34-4f8e-a008-1cd1bf33992e',source]
    for eid in source_ids:
        call('quanta',control,'foreign source time detail denied '+eid,'/api/v1/time-entries/'+eid,404)
    state,_=call('quanta',control,'foreign approval observation hides time details','/api/v1/time-entries/approved-state?'+
        urllib.parse.urlencode({'entry_ids':','.join(source_ids),'request_id':uuid.uuid4().hex}))
    require(state['workspace_id']==plan['quanta_company'] and
            state['data']==[{'id':eid,'state':'unavailable'} for eid in source_ids],
            'Foreign approval observations contain no time, rates, worker or approval details')
    receipts=[s['id'] for s in o.get('submissions',[]) if s.get('document')]
    require(bool(receipts),'Existing submitted document is included in company-isolation coverage')
    transfer,_=call('quanta',control,'control document capability is active',PREFIX+'/onboarding-document-context')
    require(transfer['workspace_id']==plan['quanta_company'] and transfer['document_transfer'] is True,
            'Document isolation is tested with the transfer feature enabled')
    for rid in receipts:
        call('quanta',control,'foreign onboarding document ownership denied',PREFIX+'/onboarding/'+o['id']+'/documents/'+rid,404)
    for path in ['/api/v1/workers/'+control_worker['worker_id'],PREFIX+'/person-links/'+control_worker['person_link_id'],PREFIX+'/assignments/'+control_assignment['id']]:
        call('quanta',original,'reverse company read denied '+path,path,404)
    for key,own,other in [(control,control_assignment['id'],a['id']),(original,a['id'],control_assignment['id'])]:
        rows,_=call('quanta',key,'assignment list stays within company '+own,PREFIX+'/assignments')
        ids={r['id'] for r in rows['items']};require(own in ids and other not in ids,'Assignment list namespace '+own)
    call('quanta',control,'foreign assignment edit denied',PREFIX+'/assignments/'+a['id'],404,
         {'expected_version':a['version'],'title':'TEST must not overwrite Thunderhawk'},'PATCH')
    call('quanta',control,'foreign worker cannot be linked',PREFIX+'/person-links',404,{'person':a['person'],'worker_id':a['worker_id']})
    stale={'expected_version':a['version']-1,'title':'TEST stale update must fail'}
    require(a['version']>1,'Thunderhawk assignment has a prior revision to reject')
    one,_=call('quanta',original,'stale assignment update',PREFIX+'/assignments/'+a['id'],409,stale,'PATCH')
    two,replay=call('quanta',original,'stale assignment update',PREFIX+'/assignments/'+a['id'],409,stale,'PATCH')
    require(one==two and replay=='true','Rejected stale update replays its original receipt')
    call('quanta',original,'assignment identity cannot be overwritten',PREFIX+'/assignments/'+a['id'],422,
         {'expected_version':a['version'],'person':dict(a['person'],number='P-999999')},'PATCH')
    call('quanta',original,'same native worker cannot acquire a conflicting person identity',PREFIX+'/person-links',409,
         {'person':dict(a['person'],number='P-999999'),'worker_id':a['worker_id']})
    stale_worker=dict(worker['worker'],updated_at='2000-01-01T00:00:00+00:00')
    call('quanta',original,'worker self-service change requires fresh review',PREFIX+'/worker-profiles',409,
         {'person':a['person'],'worker_id':a['worker_id'],'expected_worker':stale_worker,
          'email':worker['worker']['email'],'full_name':'TEST stale contact must fail'})
    publication=dict(o['publication'],expected_version=o['version']-1)
    call('quanta',original,'stale onboarding publication rejected',PREFIX+'/onboarding',409,publication)
    publication.update(expected_version=o['version'],core_version=publication['core_version']-1)
    call('quanta',original,'older Core onboarding revision rejected',PREFIX+'/onboarding',409,publication)


def pure_checks(original, control):
    ctx,_=call('purepay',control,'control company context',PREFIX+'/context')
    require(ctx['organization']['id']==plan['pure_company'],'PurePay control credential belongs to control company')
    data=database('purepay',r'''
data={c:list(d[c].find({'organization_id':company})) for c in
 ['coreflux_record_links','coreflux_assignment_inputs','coreflux_payroll_inputs','coreflux_payroll_authorities','pay_runs']}
print(json.dumps(data,default=str))
''')
    links={r['entity_type']:r for r in data['coreflux_record_links']}
    for kind,pid in [('person',plan['employee']),('placement',plan['assignment'])]:
        rec,_=call('purepay',control,'control '+kind+' record readable',PREFIX+'/records/'+kind+'/'+pid)
        payload={'reference':links[kind]['reference'],'provider_id':pid,'expected_record_version':rec['record_version']}
        saved,_=call('purepay',control,'link control '+kind+' with same canonical number',PREFIX+'/links',body=payload)
        require(saved['id']!=links[kind]['id'] and saved['organization_id']==plan['pure_company'],'PurePay '+kind+' link is company-namespaced')
        call('purepay',original,'reverse control '+kind+' read denied',PREFIX+'/records/'+kind+'/'+pid,404)
    prior=database('purepay',r'''
print(json.dumps({'count':d.customers.count_documents({'organization_id':x['other']})}))
''',other=plan['pure_company'])
    if prior['count']:
        customer,_=call('purepay',control,'read existing company-scoped control client',PREFIX+'/customers/by-reference?'+urllib.parse.urlencode(links['client']['reference']))
    else:
        customer,_=call('purepay',control,'create company-scoped control client',PREFIX+'/customers',201,
            {'contract_version':'2026-10-26.1','reference':links['client']['reference'],'name':'TEST Isolation client'})
    require(customer['customer']['id']!=links['client']['provider_id'],'Shared CL number does not merge companies')
    call('purepay',original,'reverse control client read denied',PREFIX+'/records/client/'+customer['customer']['id'],404)
    for kind,link in links.items():
        call('purepay',control,'foreign '+kind+' read denied',PREFIX+'/records/'+kind+'/'+link['provider_id'],404)
        rows,_=call('purepay',control,'control '+kind+' list scoped',PREFIX+'/records/'+kind)
        require(link['provider_id'] not in {r['id'] for r in rows['items']},'No Thunderhawk '+kind+' appears in control list')
    for col,path in [('coreflux_assignment_inputs','/assignments/inputs/'),('coreflux_payroll_inputs','/payroll/inputs/'),
                     ('coreflux_payroll_authorities','/payroll/authorities/'),('pay_runs','/payroll/runs/')]:
        for row in data[col]:
            call('purepay',control,'foreign '+col+' '+row['id'],PREFIX+path+row['id'],404)
    for path in ['/payroll/inputs','/payroll/runs']:
        result,_=call('purepay',control,'control payroll list empty '+path,PREFIX+path)
        require(result['items']==[],'No Thunderhawk financial data in control '+path)
    for row in data['pay_runs']:
        call('purepay',control,'foreign payroll observation denied '+row['id'],PREFIX+'/payroll/observations/'+row['id'],404)
    link=links['person']
    rec,_=call('purepay',original,'original person version readable',PREFIX+'/records/person/'+link['provider_id'])
    # Existing same-reference retries are deliberately receipts, so use a new reference to test stale observations.
    newref=dict(link['reference'],number='P-999999')
    call('purepay',original,'stale native person observation rejected',PREFIX+'/links',409,
        {'reference':newref,'provider_id':link['provider_id'],'expected_record_version':'0'*64})
    call('purepay',original,'native person cannot bind conflicting Core identity',PREFIX+'/links',409,
        {'reference':newref,'provider_id':link['provider_id'],'expected_record_version':rec['record_version']})
    call('purepay',original,'existing Core identity cannot bind another person',PREFIX+'/links',409,
        {'reference':link['reference'],'provider_id':plan['employee'],'expected_record_version':rec['record_version']})
    packet=data['coreflux_payroll_inputs'][-1]['packet']
    call('purepay',control,'foreign payroll packet rejected despite matching source reference',PREFIX+'/payroll/inputs',409,packet,idem=digest(packet))
    altered=copy.deepcopy(packet)
    altered['time_tenant_id']+=1
    call('purepay',original,'changed payroll payload cannot replay original idempotency key',PREFIX+'/payroll/inputs',409,altered,idem=digest(packet))
    for row in data['coreflux_payroll_authorities']:
        authority=row['authority']
        call('purepay',control,'foreign payroll reservation cannot be acquired '+row['id'],PREFIX+'/payroll/authorities',403,authority,idem=digest(authority))
        call('purepay',control,'foreign payroll reservation cannot be released '+row['id'],PREFIX+'/payroll/authorities/'+row['id']+'/release',403,authority,idem=digest(authority))


if __name__=='__main__':
    if sys.argv[1:]!=['--verify-isolated-companies'] or ROOT.resolve()!=ROOT or PRIVATE.resolve()!=PRIVATE or not PRIVATE.is_dir() or PRIVATE.stat().st_mode & 0o077:
        raise SystemExit('Explicit isolated scope and protected private directory required')
    stamp=datetime.datetime.now(datetime.timezone.utc).isoformat()
    plan_path=PRIVATE/'provider-isolation-plan.private.json'
    if plan_path.exists():plan=json.loads(plan_path.read_text(encoding='utf-8'))
    else:
        plan={'quanta_company':str(uuid.uuid4()),'pure_company':'org_iso_'+uuid.uuid4().hex[:12],
              'employee':'emp_iso_'+uuid.uuid4().hex[:12],'assignment':'asg_iso_'+uuid.uuid4().hex[:12]}
        save(plan_path.name,plan)
    credentials={'quanta':'ck_live_'+secrets.token_urlsafe(32),'pure':'pk_isolation_'+secrets.token_urlsafe(32),
                 'quanta_id':str(uuid.uuid4()),'pure_id':'key_iso_'+uuid.uuid4().hex[:12]}
    save('provider-isolation-keys.private.json',credentials)
    source=json.loads((PRIVATE/'thunderhawk-bootstrap-plan.private.json').read_text(encoding='utf-8'))
    report={'checked_at':stamp,'production_changed':False,'real_payroll_executed':False,
            'control_companies':{'quanta':plan['quanta_company'],'purepay':plan['pure_company']},'checks':RESULTS}
    ready=[];before={};failure=None;original_env=None;test_env=None
    try:
        for provider in ['quanta','purepay']:
            before[provider]=snapshot(provider)
            bootstrap(provider,plan,credentials);ready.append(provider)
        save('provider-isolation-before.private.json',before)
        original_env=(PRIVATE/'quanta.env').read_bytes()
        line=b'CORE_ONBOARDING_DOCUMENT_WORKSPACES='+Q.encode()
        if original_env.splitlines().count(line)!=1:
            raise RuntimeError('Unexpected document allowlist; refusing to broaden it')
        test_env=original_env.replace(line+b'\n',line+b','+plan['quanta_company'].encode()+b'\n')
        if test_env==original_env:raise RuntimeError('Expected private allowlist line terminator was not found')
        save('isolation-document-config-backup.private.json',{'original_utf8':original_env.decode('utf-8')})
        apply_document_workspace_config(original_env,test_env,source['quanta_key'])
        quanta_checks(source['quanta_key'],credentials['quanta'])
        pure_checks(source['pure_key'],credentials['pure'])
    except Exception as exc:
        failure=str(exc)
    finally:
        report['cleanup']={};report['business_records']={}
        if original_env is not None and test_env is not None:
            current_env=(PRIVATE/'quanta.env').read_bytes()
            if current_env==test_env:
                try:
                    apply_document_workspace_config(test_env,original_env,source['quanta_key'])
                    report['document_allowlist_restored']=True
                except Exception as exc:
                    failure='Document allowlist recovery failed: '+str(exc)
            elif current_env!=original_env:
                failure='Private Quanta settings changed outside the test; automatic overwrite refused'
        for provider in ready:
            try:
                after=snapshot(provider,before[provider])
                unchanged=equivalent_snapshots(before[provider],after)
                report['business_records'][provider]={'unchanged':unchanged,'before':before[provider],'after':after}
                if not unchanged:failure='Thunderhawk business snapshot changed; inspect the private isolated test'
                report['cleanup'][provider]=revoke(provider,credentials)
                revoked_key=credentials['quanta' if provider=='quanta' else 'pure']
                call(provider,revoked_key,'temporary verification key no longer authenticates',PREFIX+'/context',401)
            except Exception as exc:
                failure='Final verification or temporary key revocation failed: '+str(exc)
        report['passed']=failure is None
        if failure:report['failure']=failure
        save('provider-isolation-result.json',report)
        print(json.dumps(report))
    if failure:raise SystemExit(1)
