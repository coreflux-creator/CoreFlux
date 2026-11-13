import hashlib
import json
import shlex
import subprocess
import sys
from pathlib import Path

root = Path(__file__).resolve().parents[1]
out = root / 'output'
mode = sys.argv[1]
assert mode in ('prepare', 'inspect', 'advance', 'reader', 'editor', 'retire')
manifest = json.loads((root / 'deploy/thunderhawk-company-source-20261112.json').read_text(encoding='utf-8'))
bundle = {
    'company_source_native_fixture.php': (root / 'deploy/company_source_native_fixture.php').read_bytes(),
    'company-source-installed-pins.json': json.dumps(manifest['installed_runtime_pins']).encode(),
}
sha = lambda body: hashlib.sha256(body).hexdigest()
fingerprints = {name: sha(body) for name, body in bundle.items()}
private = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030'
directory = private + '/company-source-native-' + sha(json.dumps(fingerprints, sort_keys=True).encode())[:20]
ssh = ['ssh', '-T', '-i', 'C:/Users/kunal/.ssh/coreflux_github_actions_rsa', '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes', 'master_fpepuvndbb@138.197.121.111']
lint = subprocess.run(ssh + ['/usr/bin/php8.2 -l'], input=bundle['company_source_native_fixture.php'], capture_output=True, timeout=20)
assert lint.returncode == 0, lint.stdout.decode() + lint.stderr.decode()
for name, body in bundle.items():
    code = r'''$d=DIR;$p=$d.'/NAME';if(posix_geteuid()!==1003||realpath(dirname($d))!==dirname($d)||is_link($d)||is_link($p))exit(2);if(!is_dir($d)&&!mkdir($d,0700))exit(3);if(realpath($d)!==$d||(fileperms($d)&0777)!==0700)exit(4);$body=file_get_contents('php://stdin');if(hash('sha256',$body)!=='HASH')exit(5);if(file_exists($p)){if(hash_file('sha256',$p)!=='HASH')exit(6);exit;}$u=umask(0077);$f=fopen($p,'x');umask($u);if(!$f||fwrite($f,$body)!==strlen($body)||!fflush($f)||!fsync($f))exit(7);fclose($f);'''.replace('DIR', repr(directory)).replace('NAME', name).replace('HASH', fingerprints[name])
    result = subprocess.run(ssh + ['/usr/bin/php8.2 -r ' + shlex.quote(code)], input=body, capture_output=True, timeout=20)
    assert result.returncode == 0, 'Private fixture staging failed'
handle = {'mode': mode, 'directory': directory, 'files': fingerprints, 'state': private + '/company-source-native-state-20261112.json'}
(out / ('company-source-native-' + mode + '-handle.json')).write_text(json.dumps(handle, indent=2) + '\n', encoding='utf-8')
result = subprocess.run(ssh + ['cd /home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html && COREFLUX_ENV=staging /usr/bin/php8.2 ' + shlex.quote(directory + '/company_source_native_fixture.php') + ' ' + mode], capture_output=True, timeout=45)
if result.returncode:
    (out / ('company-source-native-' + mode + '-observation.json')).write_text(json.dumps({'returncode': result.returncode, 'stdout': result.stdout.decode(), 'stderr': result.stderr.decode()}, indent=2), encoding='utf-8')
    raise RuntimeError('Inspect the existing checkpoint and observation; do not repeat preparation')
value = json.loads(result.stdout)
(out / ('company-source-native-' + mode + '-result.json')).write_text(json.dumps(value, indent=2) + '\n', encoding='utf-8')
print(json.dumps(value))
