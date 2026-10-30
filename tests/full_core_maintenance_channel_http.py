"""Real Apache+FPM isolated fixture: held ingress, finishing requests, scoped cache invalidation."""
import concurrent.futures
import hashlib
import json
import os
import time
import urllib.error
import urllib.request
from pathlib import Path

if not Path('/run/core-channel-fixture').is_file():
    raise RuntimeError('Disposable container required')
root = Path('/fixture/public_html')
private = Path('/fixture/private')
until = time.monotonic() + 10
while not Path('/fixture/client.json').is_file() and time.monotonic() < until:
    time.sleep(.05)
client = json.loads(Path('/fixture/client.json').read_text())
spec, token = client['spec'], client['token']
channel = '/' + spec['path']
held = Path('/fixture/held.htaccess').read_bytes()
original = Path('/fixture/original.htaccess').read_bytes()
checks = 0

def check(value, label):
    global checks
    if not value: raise AssertionError(label)
    checks += 1

def request(path, method='GET', data=None, secret=None, host='fixture.local'):
    headers = {'Host': host, 'Content-Type': 'application/json'}
    if secret is not None: headers['X-CoreFlux-Maintenance'] = secret
    body = None if data is None else json.dumps(data).encode()
    req = urllib.request.Request('http://127.0.0.1' + path, data=body, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=20) as response: return response.status, response.read(), dict(response.headers)
    except urllib.error.HTTPError as error: return error.code, error.read(), dict(error.headers)

def web(bytes_):
    (root / '.htaccess').write_bytes(bytes_)

def status():
    code, body, _ = request(channel, 'POST', {'action': 'status'}, token)
    check(code == 200, 'authenticated pool status under hold')
    return json.loads(body)

def await_quiet():
    until = time.monotonic() + 8
    while time.monotonic() < until:
        state = status()
        if state['pool']['request_pool_quiet']: return state
        time.sleep(.1)
    raise AssertionError('Pool did not become quiet')

for _ in range(100):
    try:
        if request('/read.php')[0] == 200: break
    except OSError: pass
    time.sleep(.1)
else: raise AssertionError('Apache did not start')
check(request('/read.php')[1] == b'old-first|old-second', 'warm both cached scripts')
check(request(channel, 'POST', {'action': 'status'}, token)[0] == 503, 'status refuses absent hold')
request('/handler.php')
before = (private / 'business-hits').read_bytes()

for finishing in [False, True]:
    for path in [private / 'release-busy', private / 'busy-started']:
        if path.exists(): path.unlink()
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
        future = executor.submit(request, '/busy.php?finish=' + str(int(finishing)))
        until = time.monotonic() + 5
        while not (private / 'busy-started').exists() and time.monotonic() < until: time.sleep(.02)
        check((private / 'busy-started').exists(), 'prior request started')
        web(held)
        state = status()
        check(not state['pool']['request_pool_quiet'] and state['pool']['other_busy_processes'] >= 1,
              'finishing response remains busy' if finishing else 'running prior request blocks drain')
        (private / 'release-busy').write_text('release')
        future.result()
        state = await_quiet()
        check(state['scope'] == 'core-web-pool-only' and state['business_writers_drained'] is False,
              'pool check cannot claim other writers drained')
        web(original)

web(held)
for path in ['/', '/handler.php', '/api/probe.php', '/api/anything', '/dashboard/probe.php',
             '/dashboard/anything', '/modules/accounting/api/probe.php', channel + '/extra', channel + '?x=1']:
    for method in ['GET', 'POST']:
        code, body, headers = request(path, method, {'action': 'status'}, token)
        check(code == 503 and b'handler-executed' not in body, 'all ordinary/path-info/query requests remain held')
check((private / 'business-hits').read_bytes() == before, 'no business handler ran during hold')
check(request(channel, 'GET', secret=token)[0] == 503, 'wrong method cannot enter diagnostic')
check(request(channel, 'POST', {'action': 'status'})[0] == 404, 'missing token denied')
check(request(channel, 'POST', {'action': 'status'}, 'wrong')[0] == 404, 'wrong token denied')
check(request(channel, 'POST', {'action': 'status'}, token, 'wrong.local')[0] == 404, 'wrong host denied')
state = await_quiet()
check(state['operation'] == spec['operation'] and state['candidate_commit'] == spec['candidate_commit']
      and state['held_web_sha256'] == spec['held_web_sha256'], 'status binds exact held release')
check(request(channel, 'POST', {'action': 'status', 'path': '/etc/passwd'}, token)[0] == 503,
      'caller cannot supply file paths')

for name in ['first', 'second']:
    path = root / (name + '.php')
    old_stat = path.stat()
    path.write_text('<?php return "new-' + name + '";')
    os.utime(path, ns=(old_stat.st_atime_ns, old_stat.st_mtime_ns))
plan = {key: spec[key] for key in ['operation', 'candidate_commit', 'root', 'held_web_sha256']}
plan.update(version=1, phase='candidate', files=[{'path': 'first.php', 'sha256': '0' * 64}])

def save_plan(value):
    path = private / 'cache-plan.json'
    data = json.dumps(value).encode(); path.write_bytes(data); os.chown(path, 33, 33); path.chmod(0o600)
    return hashlib.sha256(data).hexdigest()

bad_hash = save_plan(plan)
check(request(channel, 'POST', {'action': 'invalidate', 'plan_sha256': bad_hash}, token)[0] == 503,
      'changed source bytes refuse cache mutation')
plan['files'][0]['sha256'] = hashlib.sha256((root / 'first.php').read_bytes()).hexdigest()
good_hash = save_plan(plan)
check(request(channel, 'POST', {'action': 'invalidate', 'plan_sha256': bad_hash}, token)[0] == 503,
      'stale plan digest rejected')
code, body, _ = request(channel, 'POST', {'action': 'invalidate', 'plan_sha256': good_hash}, token)
check(code == 200 and json.loads(body)['invalidated_files'] == 1 and not json.loads(body)['global_cache_reset'],
      'exact reviewed file invalidated')
web(original)
check(request('/read.php')[1] == b'new-first|old-second', 'reviewed cache refreshed while unrelated cache is preserved')
web(held)
(root / 'first.php').write_text('<?php return "old-first";')
plan['phase'] = 'recovery'; plan['files'][0]['sha256'] = hashlib.sha256((root / 'first.php').read_bytes()).hexdigest()
recovery_hash = save_plan(plan)
check(request(channel, 'POST', {'action': 'invalidate', 'plan_sha256': recovery_hash}, token)[0] == 200,
      'reviewed recovered bytes can be refreshed')
web(original)
check(request('/read.php')[1] == b'old-first|old-second', 'paired recovery resumes original cached behavior')
check(request(channel, 'POST', {'action': 'status'}, token)[0] == 503, 'diagnostic refuses reopened ingress')
check((root / '.htaccess').read_bytes() == original, 'original routing restored exactly')
print(f'PASS {checks} native Apache/FPM maintenance channel checks; disposable fixture only')
