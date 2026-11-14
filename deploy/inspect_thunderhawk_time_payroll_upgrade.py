"""Read-only comparison of the accepted source with the isolated Thunderhawk pilot.

Only hashes, schema definitions, row counts and migration metadata leave the host.
This command neither stages files nor starts a deployment or a migration.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
BASE = "4bc10bb07791ceb7b2867f8dde28ce539cb5fd51"
REMOTE = "/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html"


def git(*args: str) -> bytes:
    return subprocess.check_output(["git", *args], cwd=ROOT)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("candidate")
    parser.add_argument("--ci-run", required=True)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    if not re.fullmatch(r"[a-f0-9]{40}", args.candidate) or not args.ci_run.isdigit():
        raise SystemExit("An exact candidate and CI run are required")
    if args.output.exists():
        raise SystemExit("Use a new inspection output; retain previous evidence")
    ci = json.loads(subprocess.check_output([
        "gh", "run", "view", args.ci_run,
        "--json", "headSha,status,conclusion,jobs,url",
    ], cwd=ROOT))
    if (ci["headSha"] != args.candidate or ci["status"] != "completed"
            or ci["conclusion"] != "success" or len(ci["jobs"]) != 11
            or any(job["conclusion"] != "success" for job in ci["jobs"])):
        raise SystemExit("Candidate has not passed its complete CI run")

    # The previous package is private local evidence, never printed or uploaded.
    old = json.loads((ROOT / "output/assignment-company-package.json").read_text())
    if old["source_commit"] != BASE:
        raise SystemExit("Unexpected installed baseline evidence")
    pins = {**old["dependencies"], **{f: v["sha256"] for f, v in old["files"].items()}}
    tracked = set(git("ls-tree", "-r", "--name-only", args.candidate).decode().splitlines())
    changed = git("diff", "--name-only", BASE, args.candidate).decode().splitlines()
    runtime = [p for p in changed if re.match(
        r"^(?:(?:api|core|modules|billing|lib|includes|helpers|auth|oauth|partials|views|cron|scripts)/.*|[^/]+)\.(?:php|sql)$", p)]
    if any(p not in tracked for p in runtime):
        raise SystemExit("Runtime deletions require explicit deployment review")

    def digest(commit: str, path: str) -> str:
        return hashlib.sha256(git("show", f"{commit}:{path}").replace(b"\r\n", b"\n")).hexdigest()

    base_files = set(git("ls-tree", "-r", "--name-only", BASE).decode().splitlines())
    targets = {p: {"candidate_sha256": digest(args.candidate, p),
                   "baseline_sha256": digest(BASE, p) if p in base_files else None,
                   "installed_pin": pins.get(p)} for p in sorted(runtime)}
    migrations = {p: digest(args.candidate, p) for p in sorted(tracked)
                  if re.fullmatch(r"(?:core/migrations|modules/[^/]+/migrations)/[^/]+\.sql", p)}
    payload = {"targets": targets, "pins": pins, "migrations": migrations,
               "feature_sha256": old["feature_sha256"]}
    encoded = json.dumps(json.dumps(payload, separators=(",", ":")))
    php = r'''<?php
declare(strict_types=1);
const ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
if(PHP_SAPI!=='cli'||getcwd()!==ROOT||posix_geteuid()!==1003||getenv('COREFLUX_ENV')!=='staging')exit(2);
require_once ROOT.'/core/db.php';require_once ROOT.'/core/integrations/identity.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
 ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002')exit(2);
$spec=json_decode(SPEC,true,512,JSON_THROW_ON_ERROR);
$feature=dirname(ROOT).'/private_html/coreflux.thunderhawk-pilot.php';
if(is_link($feature)||hash_file('sha256',$feature)!==$spec['feature_sha256'])throw new RuntimeException('Pilot configuration differs');
$file=function(string $relative):?array {
 if(!preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D',$relative)||str_contains($relative,'..'))throw new RuntimeException('Invalid runtime path');
 $path=ROOT.'/'.$relative;
 if(is_link($path))throw new RuntimeException('Linked runtime file');
 if(!file_exists($path))return null;
 if(realpath($path)!==$path||!is_file($path))throw new RuntimeException('Redirected runtime file');
 $s=stat($path);$bytes=file_get_contents($path);
 return ['sha256'=>hash('sha256',$bytes),'normalized_sha256'=>hash('sha256',str_replace("\r\n","\n",$bytes)),
  'uid'=>$s['uid'],'gid'=>$s['gid'],'mode'=>sprintf('%04o',$s['mode']&0777),'size'=>strlen($bytes)];
};
$pins=[];foreach($spec['pins']as$p=>$expected){$r=$file($p);if(!$r||$r['normalized_sha256']!==$expected||$r['uid']!==1003||$r['gid']!==33||$r['mode']!=='0644')throw new RuntimeException('Installed baseline changed: '.$p);$pins[$p]=$r;}
$files=[];foreach($spec['targets']as$p=>$expected)$files[$p]=$file($p);
$pdo->exec('SET TRANSACTION READ ONLY');$pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
try{
 if((int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)throw new RuntimeException('Simulation company missing');
 $ledger=$pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
 $tables=[];
 foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$t){
  if(!preg_match('/^[A-Za-z0-9_]+$/D',$t))throw new RuntimeException('Invalid table');
  $create=$pdo->query('SHOW CREATE TABLE `'.$t.'`')->fetch(PDO::FETCH_NUM)[1];
  $columns=$pdo->query('SHOW COLUMNS FROM `'.$t.'`')->fetchAll(PDO::FETCH_ASSOC);
  $hashes=[];foreach($pdo->query('SELECT * FROM `'.$t.'`')as$row)$hashes[]=hash('sha256',serialize($row));sort($hashes);
  $tables[$t]=['rows'=>count($hashes),'rows_sha256'=>hash('sha256',implode("\n",$hashes)),
   'create_sql'=>$create,'columns'=>$columns];
 }
 ksort($tables);$pdo->rollBack();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
$flags=[];foreach(['COREFLUX_QUANTA_TIME_ACCESS_DELIVERY','COREFLUX_QUANTA_PROFILE_RETURN','COREFLUX_PUREPAY_PAYROLL_AUTHORITY','COREFLUX_INTEGRATION_DELIVERY_TENANTS']as$f)$flags[$f]=defined($f)?constant($f):null;
echo json_encode(['checked_at'=>gmdate('c'),'root'=>ROOT,'server_version'=>$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
 'database'=>'muzqvdvqbx','configuration_preserved'=>true,'installed_pins'=>$pins,'files'=>$files,'migration_ledger'=>$ledger,
 'tables'=>$tables,'feature_flags'=>$flags,'hosted_changes'=>false,'provider_requests'=>0],JSON_THROW_ON_ERROR);
'''.replace("SPEC", encoded)
    ssh = ["ssh", "-T", "-i", "C:/Users/kunal/.ssh/coreflux_github_actions_rsa",
           "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes", "-o", "StrictHostKeyChecking=yes",
           "master_fpepuvndbb@138.197.121.111"]
    result = subprocess.run(ssh + [f"cd {REMOTE} && COREFLUX_ENV=staging /usr/bin/php8.2"],
                            input=php.encode(), capture_output=True, timeout=55)
    if result.returncode:
        raise SystemExit("Read-only inspection failed; no deployment was started. "
                         + result.stderr.decode(errors="replace")[:500])
    report = json.loads(result.stdout)
    report.update(candidate_commit=args.candidate, baseline_commit=BASE, ci_run=args.ci_run,
                  ci_url=ci["url"], expected_files=targets, candidate_migrations=migrations)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    conflicts = [p for p, expected in targets.items() if report["files"][p] is not None
                 and report["files"][p]["normalized_sha256"] not in {
                     expected["baseline_sha256"], expected["installed_pin"], expected["candidate_sha256"]}]
    print(json.dumps({"checked_at": report["checked_at"], "candidate": args.candidate,
                      "installed_pins": len(pins), "runtime_targets": len(targets),
                      "new_runtime_files": sum(v is None for v in report["files"].values()),
                      "unexpected_file_preimages": conflicts,
                      "tables": len(report["tables"]), "rows": sum(v["rows"] for v in report["tables"].values()),
                      "migration_records": len(report["migration_ledger"]), "hosted_changes": False}))


if __name__ == "__main__":
    main()
