#!/usr/bin/env bash
# Disposable Apache/PHP HTTP rehearsal, never a host-maintenance entrypoint.
set -euo pipefail
cd "$(dirname "$0")/.."
fixture="$(mktemp -d "${RUNNER_TEMP:-/tmp}/core-maintenance-apache.XXXXXXXX")"
container="core-maintenance-${GITHUB_RUN_ID:-local}-${RANDOM}"
trap 'docker rm -f "$container" >/dev/null 2>&1 || true' EXIT
mkdir -p "$fixture/web/api" "$fixture/web/dashboard" "$fixture/web/modules/accounting/api"
chmod 755 "$fixture" "$fixture/web"
cp .htaccess "$fixture/original.htaccess"
cp .htaccess "$fixture/web/.htaccess"
cp api/.htaccess "$fixture/web/api/.htaccess"
cp dashboard/.htaccess "$fixture/web/dashboard/.htaccess"
cat > "$fixture/web/spa.php" <<'PHP'
<?php
file_put_contents('/tmp/core-maintenance-hits', "executed\n", FILE_APPEND | LOCK_EX);
header('Content-Type: text/plain');
echo 'synthetic-handler-executed';
PHP
cp "$fixture/web/spa.php" "$fixture/web/api/index.php"
cp "$fixture/web/spa.php" "$fixture/web/api/probe.php"
cp "$fixture/web/spa.php" "$fixture/web/modules/accounting/api/probe.php"
printf 'synthetic-static-page' > "$fixture/web/dashboard/index.html"
printf 'private-placeholder-must-not-leak' > "$fixture/web/.env"
php -r 'require "deploy/full_core_maintenance.php"; echo fullCoreMaintenanceWebRule(str_repeat("a",32));' > "$fixture/held.htaccess"
docker run --detach --rm --name "$container" --publish 127.0.0.1::80 \
  --mount "type=bind,src=$fixture/web,dst=/var/www/html" php:8.2-apache \
  bash -c 'a2enmod rewrite headers >/dev/null && apache2-foreground' >/dev/null
port="$(docker port "$container" 80/tcp | cut -d: -f2)"
origin="http://127.0.0.1:$port"
for attempt in $(seq 1 30); do
  if curl --silent --fail "$origin/api/probe.php" > "$fixture/startup.txt"; then break; fi
  if [ "$attempt" = 30 ]; then docker logs "$container"; exit 1; fi
  sleep 1
done
check() {
  local method="$1" path="$2" expected="$3"
  local actual
  actual="$(curl --silent --show-error --request "$method" --dump-header "$fixture/headers.txt" \
    --output "$fixture/body.txt" --write-out '%{http_code}' "$origin$path")"
  if [ "$actual" != "$expected" ]; then
    printf 'FAIL %s %s expected %s got %s\n' "$method" "$path" "$expected" "$actual"
    cat "$fixture/body.txt"
    docker logs "$container"
    exit 1
  fi
}
check GET /api/probe.php 200
check POST /api/example/route 200
before="$(docker exec "$container" cat /tmp/core-maintenance-hits)"
cp "$fixture/held.htaccess" "$fixture/web/.htaccess.next"
mv "$fixture/web/.htaccess.next" "$fixture/web/.htaccess"
for path in / /spa.php /api/probe.php /api/example/route /modules/accounting/api/probe.php /modules/accounting/overview /dashboard/deep/route /dashboard/index.html; do
  for method in GET POST; do
    check "$method" "$path" 503
    grep -qi '^Retry-After: 300' "$fixture/headers.txt"
    grep -qi '^Cache-Control: no-store' "$fixture/headers.txt"
    if grep -q 'synthetic-handler-executed' "$fixture/body.txt"; then exit 1; fi
  done
done
after="$(docker exec "$container" cat /tmp/core-maintenance-hits)"
test "$before" = "$after"
check GET /.env 503
if grep -q 'private-placeholder-must-not-leak' "$fixture/body.txt"; then exit 1; fi
FIXTURE_PATH="$fixture" php <<'PHP'
<?php
require 'deploy/full_core_maintenance.php';
$f = getenv('FIXTURE_PATH');
$original = file_get_contents($f.'/original.htaccess');
$held = file_get_contents($f.'/held.htaccess');
$restore = fullCoreMaintenanceRestore($original, hash('sha256', $original), hash('sha256', $held), file_get_contents($f.'/web/.htaccess'));
file_put_contents($f.'/web/.htaccess.next', $restore);
rename($f.'/web/.htaccess.next', $f.'/web/.htaccess');
PHP
cmp "$fixture/original.htaccess" "$fixture/web/.htaccess"
check GET /api/probe.php 200
check POST /api/example/route 200
restored="$(docker exec "$container" cat /tmp/core-maintenance-hits)"
test "$after" != "$restored"
printf 'PASS Apache maintenance: 17 held requests, no PHP handler execution, byte-exact restore, resumed GET and POST\n'
