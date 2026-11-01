#!/usr/bin/env bash
# Production-shaped paths/owners, native services, synthetic data, no host mounts or external network.
set -euo pipefail
cd "$(dirname "$0")/.."
fixture="$(mktemp -d "${RUNNER_TEMP:-/tmp}/core-production-upgrade.XXXXXXXX")"
image="core-production-upgrade:${GITHUB_RUN_ID:-local}-${RANDOM}"
container="core-production-upgrade-${GITHUB_RUN_ID:-local}-${RANDOM}"
trap 'docker rm -f "$container" >/dev/null 2>&1 || true; docker image rm "$image" >/dev/null 2>&1 || true' EXIT
mkdir -p "$fixture/deploy" "$fixture/tests" "$fixture/core"
cp deploy/full_core_{maintenance,maintenance_host,maintenance_channel,maintenance_channel_web,migration_plan,production_upgrade_context,upgrade_executor,release_files,release_control,release_owner}.php "$fixture/deploy/"
cp deploy/full_core_migration_history.json "$fixture/deploy/"
cp deploy/inspect_full_core_release_owner.php "$fixture/deploy/"
cp deploy/run_full_core_release_owner.php deploy/full_core_database_state.php "$fixture/deploy/"
cp core/migration_hash.php "$fixture/core/"
cp tests/full_core_production_upgrade_linux.php tests/full_core_release_control_linux.php "$fixture/tests/"
cat > "$fixture/Dockerfile" <<'DOCKER'
FROM php:8.2-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends apache2 mariadb-server mariadb-client cron util-linux openssh-server openssh-client \
    && rm -rf /var/lib/apt/lists/* && docker-php-ext-install pdo_mysql opcache \
    && a2enmod rewrite headers proxy proxy_fcgi >/dev/null \
    && useradd -m --uid 1003 --gid 33 coremaster && useradd -m --uid 1004 --gid 33 coreapp \
    && usermod -p '*' coremaster && usermod -p '*' coreapp \
    && mkdir /fixture && touch /run/core-production-upgrade-fixture
RUN printf '[www]\nuser=coreapp\ngroup=www-data\npm.max_children=4\npm.start_servers=2\npm.min_spare_servers=2\npm.max_spare_servers=3\n' > /usr/local/etc/php-fpm.d/zzz-fixture.conf \
    && printf 'opcache.enable=1\nopcache.validate_timestamps=0\nopcache.file_update_protection=0\n' > /usr/local/etc/php/conf.d/fixture.ini
COPY deploy /app/deploy
COPY core /app/core
COPY tests /app/tests
COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY start.sh /start.sh
WORKDIR /app
ENTRYPOINT ["bash", "/start.sh"]
DOCKER
cat > "$fixture/apache.conf" <<'APACHE'
ServerName fixture.local
<VirtualHost *:80>
    DocumentRoot /home/1516771.cloudwaysapps.com/grcudkpvcd/public_html
    <Directory /home/1516771.cloudwaysapps.com/grcudkpvcd/public_html>
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "proxy:fcgi://127.0.0.1:9000"
    </FilesMatch>
</VirtualHost>
APACHE
cat > "$fixture/start.sh" <<'START'
set -euo pipefail
mkdir -p /run/sshd
ssh-keygen -q -t ed25519 -N '' -f /fixture/ssh-key
cp /fixture/ssh-key.pub /fixture/authorized-keys
chmod 644 /fixture/authorized-keys
printf '[127.0.0.1]:2222 %s\n' "$(ssh-keygen -y -f /etc/ssh/ssh_host_ed25519_key)" > /fixture/known-hosts
cat > /fixture/sshd.conf <<'SSH'
Port 2222
ListenAddress 127.0.0.1
PidFile /fixture/sshd.pid
HostKey /etc/ssh/ssh_host_ed25519_key
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
UsePAM no
AllowUsers coremaster coreapp
AuthenticationMethods publickey
AuthorizedKeysFile /fixture/authorized-keys
AllowTcpForwarding no
AllowAgentForwarding no
X11Forwarding no
PermitTunnel no
SSH
/usr/sbin/sshd -f /fixture/sshd.conf -E /fixture/ssh.log
mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld
mariadbd --user=mysql --skip-networking --socket=/run/mysqld/mysqld.sock --event-scheduler=OFF > /fixture/database.log 2>&1 &
for n in {1..100}; do
    if mariadb-admin --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1; then break; fi
    sleep .1
done
php-fpm -D
set +u
. /etc/apache2/envvars
set -u
mkdir -p "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR"
apache2 -DFOREGROUND > /fixture/apache.log 2>&1 &
test_script=tests/full_core_production_upgrade_linux.php
if [ "${1:-}" = control ]; then test_script=tests/full_core_release_control_linux.php; fi
php "$test_script" "$@" || {
    code=$?
    for log in /fixture/commands.stderr /fixture/apache.log /fixture/database.log /fixture/ssh.log /fixture/writer-inventory.json /fixture/*/native-*/error-*.json; do
        if [ -f "$log" ]; then tail -n 25 "$log"; fi
    done
    exit "$code"
}
START
docker build --quiet --tag "$image" "$fixture"
scenarios=(control wrong-file-plan stale extra-db late-db guard-loss crash recover activate)
if [ "$#" -gt 0 ]; then scenarios=("$@"); fi
for scenario in "${scenarios[@]}"; do
    docker run --network none --name "$container" "$image" "$scenario" || { docker logs "$container"; exit 1; }
    docker rm "$container" >/dev/null
done
