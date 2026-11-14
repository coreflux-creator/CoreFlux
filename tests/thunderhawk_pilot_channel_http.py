import json,time,urllib.request,urllib.error
from pathlib import Path
control=json.loads(Path('/fixture/private/control.json').read_text())
checks=0
def expect(value,label):
    global checks
    assert value,label
    checks+=1;print('PASS '+label,flush=True)
def request(path,body=None,token=None):
    headers={'Host':'fixture.local','Content-Type':'application/json'}
    if token:headers['X-CoreFlux-Maintenance']=token
    r=urllib.request.Request('http://127.0.0.1/'+path,data=None if body is None else json.dumps(body).encode(),headers=headers)
    try:
        with urllib.request.urlopen(r,timeout=10)as response:return response.status,response.read()
    except urllib.error.HTTPError as error:return error.code,error.read()
for _ in range(120):
    try:
        if request('index.php')[0]==200:break
    except (OSError,urllib.error.URLError):pass
    time.sleep(.25)
else:raise RuntimeError('Native FPM fixture did not start')
status,raw=request('index.php');initial=json.loads(raw)
expect(initial=={'version':'old','posix_available':False,'private_readable':False},'FPM cannot use POSIX or read the installer private directory')
expect(request('other.php')==(200,b'other-old'),'Unrelated PHP is cached before maintenance')
path=control['spec']['path'];token=control['token'];web=Path('/fixture/public_html')
expect(request(control['expired_path'],{'action':'status'},token)[0]==404,'Expired diagnostic refuses even with its exact token')
expect(request(path,{'action':'status'},token)[0]==503,'Diagnostic refuses without the exact maintenance hold')
(web/'.htaccess').write_text(control['held'])
expect(request('index.php')[0]==503,'Maintenance blocks business PHP')
expect(request(path,{'action':'status'},'wrong')[0]==404,'Wrong diagnostic token is refused')
expect(request(path+'?bypass=1',{'action':'status'},token)[0]==503,'Query-string route cannot bypass maintenance')
for _ in range(40):
    status,raw=request(path,{'action':'status'},token);report=json.loads(raw)
    if status!=200 or report['pool']['request_pool_quiet']:break
    time.sleep(.1)
expect(status==200 and report['pool']['request_pool_quiet'] and report['pool']['pool_matches'],'Distinct-account diagnostic proves native FPM is quiet')
expect(report['private_files_read'] is False and report['application_bootstrap'] is False and report['global_cache_reset'] is False,'Inspection needs neither private files nor application initialization')
expect(request(path,{'action':'invalidate','plan_sha256':'0'*64},token)[0]==503,'Caller cannot supply an unreviewed cache plan')
expect(request(path,{'action':'invalidate','plan_sha256':control['plans']['candidate']},token)[0]==503,'Candidate cache refresh refuses old file bytes')
(web/'index.php').write_text(control['new']);(web/'other.php').write_text('<?php echo "other-new";')
status,raw=request(path,{'action':'invalidate','plan_sha256':control['plans']['candidate']},token);refreshed=json.loads(raw)
expect(status==200 and refreshed['invalidated_files']==1 and refreshed['cache_phase']=='candidate','Exact candidate cache plan runs under the web account')
(web/'.htaccess').write_text('Options -Indexes\n')
expect(json.loads(request('index.php')[1])['version']=='new','Activated PHP serves the changed candidate')
expect(request('other.php')==(200,b'other-old'),'Unrelated cached PHP remains untouched')
(web/'.htaccess').write_text(control['held']);(web/'index.php').write_text(control['old'])
status,raw=request(path,{'action':'invalidate','plan_sha256':control['plans']['recovery']},token)
expect(status==200 and json.loads(raw)['cache_phase']=='recovery','Exact recovery cache plan runs under the web account')
(web/'.htaccess').write_text('Options -Indexes\n')
expect(json.loads(request('index.php')[1])==initial,'Recovered PHP again serves its original bytes')
expect((web/'.htaccess').stat().st_uid==0 and (web/path).stat().st_uid==0,'Web operation preserves the separate installer ownership')
print(f'{checks} distinct-account pilot channel checks passed')
