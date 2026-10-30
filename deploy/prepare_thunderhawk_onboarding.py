"""Enable document transfer only for the isolated Thunderhawk Quanta workspace."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import urllib.request

ROOT=Path('/srv/core-integration-pilot/20261029')
PRIVATE=ROOT/'private'
ENV=PRIVATE/'quanta.env'
TENANT='4f93d4e7-af74-4765-b812-3464bb6d974b'
CONTAINER='core-provider-pilot-20261029-quanta-pilot-web-1'
if sys.argv[1:]!=['--enable-isolated-document-transfer'] or not ENV.is_file():
    raise SystemExit('Explicit isolated document-transfer argument and private config required')
raw=ENV.read_text()
values=dict(line.split('=',1) for line in raw.splitlines() if line and not line.startswith('#'))
assert values['DB_NAME']=='corepilot_quanta_20261029'
assert values['MONGO_URL']=='mongodb://quanta-pilot-mongo:27017'
assert values['MAIL_DRIVER']=='devoutbox'
assert values['DISABLE_BACKGROUND_WORKERS']=='1' and values['DISABLE_SCHEDULER']=='1'
allowed={'CORE_ONBOARDING_DOCUMENT_TRANSFER':'1','CORE_ONBOARDING_DOCUMENT_WORKSPACES':TENANT}
changed=any(values.get(k)!=v for k,v in allowed.items())
if changed:
    backup=PRIVATE/'quanta.before-thunderhawk-documents.env'
    with os.fdopen(os.open(backup,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'w') as out: out.write(raw)
    values.update(allowed)
    tmp=PRIVATE/'quanta.documents.env.tmp'
    with os.fdopen(os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600),'w') as out:
        out.write(''.join(k+'='+v+'\n' for k,v in values.items()))
    os.replace(tmp,ENV)
result=subprocess.run(['docker','compose','-f',str(ROOT/'compose.yaml'),'-f',str(ROOT/'compose-https.yaml'),
    'up','-d','--no-deps','--pull','never','quanta-pilot-web'],cwd=ROOT,text=True,capture_output=True)
if result.returncode: raise SystemExit('Pilot service update failed; private config backup retained')
healthy=False
for _ in range(20):
    try:
        with urllib.request.urlopen('http://127.0.0.1:19181/api/health',timeout=3) as response:
            healthy=response.status==200
    except Exception: pass
    if healthy: break
    time.sleep(1)
assert healthy,'Pilot did not become healthy'
check="import os; assert os.environ['DB_NAME']=='corepilot_quanta_20261029'; assert os.environ['MAIL_DRIVER']=='devoutbox'; assert os.environ['CORE_ONBOARDING_DOCUMENT_TRANSFER']=='1'; assert os.environ['CORE_ONBOARDING_DOCUMENT_WORKSPACES']=='"+TENANT+"'"
subprocess.run(['docker','exec',CONTAINER,'python','-c',check],check=True,capture_output=True)
print(json.dumps({'passed':True,'workspace_id':TENANT,'document_transfer_enabled':True,
    'allowed_workspace_count':1,'outbound_mail':'devoutbox only','service_healthy':True,
    'production_services_changed':False,'config_changed':changed,
    'prior_config_sha256':hashlib.sha256(raw.encode()).hexdigest()}))
