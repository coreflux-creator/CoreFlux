#!/usr/bin/env bash
# Production-shaped paths/owners, native services, synthetic data, no host mounts or external network.
set -euo pipefail
cd "$(dirname "$0")/.."
native_writers=0
if [ "${1:-}" = --native-writers ]; then
    native_writers=1; shift
    # This variant requires a fresh disposable CI VM with its native container manager.
    test "${GITHUB_ACTIONS:-}" = true && test "${RUNNER_ENVIRONMENT:-}" = github-hosted
    command -v systemd-nspawn >/dev/null
fi
fixture="$(mktemp -d "${RUNNER_TEMP:-/tmp}/core-production-upgrade.XXXXXXXX")"
image="core-production-upgrade:${GITHUB_RUN_ID:-local}-${RANDOM}"
container="core-production-upgrade-${GITHUB_RUN_ID:-local}-${RANDOM}"
trap 'docker rm -f "$container" >/dev/null 2>&1 || true; docker image rm "$image" >/dev/null 2>&1 || true' EXIT
mkdir -p "$fixture/deploy" "$fixture/tests" "$fixture/core"
cp deploy/full_core_{maintenance,maintenance_host,maintenance_channel,maintenance_channel_web,migration_plan,production_upgrade_context,upgrade_executor,release_files,release_control,release_owner}.php "$fixture/deploy/"
cp deploy/full_core_migration_history.json "$fixture/deploy/"
cp deploy/inspect_full_core_release_owner.php "$fixture/deploy/"
cp deploy/run_full_core_release_owner.php deploy/full_core_database_state.php "$fixture/deploy/"
cp deploy/core_release_coordinator.py deploy/inspect_full_core_release.py "$fixture/deploy/"
cp deploy/core_native_hold_observer.py "$fixture/deploy/"
cp deploy/full_core_database_transfer.php deploy/core_database_archive.py deploy/core_database_backup.py "$fixture/deploy/"
cp deploy/inspect_full_core_writers.php deploy/full_core_writer_policy.php "$fixture/deploy/"
cp core/migration_hash.php "$fixture/core/"
cp tests/full_core_production_upgrade_linux.php tests/full_core_release_control_linux.php "$fixture/tests/"
cp tests/core_release_coordinator_native.py "$fixture/tests/"
cp tests/core_database_transfer_native.py "$fixture/tests/"
cp tests/core_database_archive_smoke.py "$fixture/tests/"
cp tests/core_native_hold_fixture.py "$fixture/tests/"
cp tests/core_release_forward_native.py "$fixture/tests/"
cat > "$fixture/Dockerfile" <<'DOCKER'
FROM php:8.2-fpm-bookworm
ARG NATIVE_WRITERS=0
RUN apt-get update && apt-get install -y --no-install-recommends apache2 mariadb-server mariadb-client cron util-linux openssh-server openssh-client python3 python3-cryptography \
    && if [ "$NATIVE_WRITERS" = 1 ]; then apt-get install -y --no-install-recommends systemd systemd-sysv libpam-systemd dbus at; fi \
    && rm -rf /var/lib/apt/lists/* && docker-php-ext-install pdo_mysql opcache \
    && a2enmod rewrite headers proxy proxy_fcgi >/dev/null \
    && useradd -m --uid 1003 --gid 33 coremaster && useradd -m --uid 1004 --gid 33 coreapp \
    && usermod -p '*' coremaster && usermod -p '*' coreapp \
    && mkdir /fixture && touch /run/core-production-upgrade-fixture
# Keep the fixture's complete pool stable between the HTTP status observation
# and the independent process inventory. Dynamic spare-worker creation is an
# unreviewed process change, which the production observer correctly refuses.
RUN printf '[www]\nuser=coreapp\ngroup=www-data\npm=static\npm.max_children=4\n' > /usr/local/etc/php-fpm.d/zzz-fixture.conf \
    && printf 'opcache.enable=1\nopcache.validate_timestamps=0\nopcache.file_update_protection=0\n' > /usr/local/etc/php/conf.d/fixture.ini
COPY deploy /app/deploy
COPY core /app/core
COPY tests /app/tests
COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY start.sh /start.sh
COPY native-fixture.sh /native-fixture.sh
COPY core-native-fixture.service /etc/systemd/system/core-native-fixture.service
COPY core-native-fixture.target /etc/systemd/system/core-native-fixture.target
RUN if [ "$NATIVE_WRITERS" = 1 ]; then \
      systemctl mask cron.service atd.service apache2.service mariadb.service ssh.service console-getty.service \
      && ln -sf /etc/systemd/system/core-native-fixture.target /etc/systemd/system/default.target \
      && mkdir -p /var/log/journal \
      && truncate -s 0 /etc/machine-id; fi
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
touch /run/core-production-upgrade-fixture
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
# Docker's FPM config reopens /proc/self/fd/2; journald gives the unit a socket,
# which cannot be reopened as a log file. Keep this fixture's native log local.
php-fpm -D > /fixture/fpm.log 2>&1
set +u
. /etc/apache2/envvars
set -u
mkdir -p "$APACHE_RUN_DIR" "$APACHE_LOCK_DIR"
apache2 -DFOREGROUND > /fixture/apache.log 2>&1 &
test_script=tests/full_core_production_upgrade_linux.php
mv /usr/bin/crontab /usr/bin/crontab.native
cat > /usr/bin/crontab <<'CRON'
#!/bin/bash
if [ -e /fixture/interrupt-hold ] && [ "$(id -u)" = 1004 ] && grep -q '# CoreFlux maintenance ' /home/1516771.cloudwaysapps.com/grcudkpvcd/public_html/.htaccess; then exit 87; fi
if [ "${1:-}" = - ] && [ -e /fixture/interrupt-restore ]; then
    /usr/bin/crontab.native "$@" || exit $?
    printf 'installed\n' >> /fixture/restore-write-count
    exit 87
fi
exec /usr/bin/crontab.native "$@"
CRON
chmod 755 /usr/bin/crontab
if [ "${1:-}" = control ]; then test_script=tests/full_core_release_control_linux.php; fi
php "$test_script" "$@" || {
    code=$?
    for log in /fixture/commands.stderr /fixture/fpm.log /fixture/apache.log /fixture/database.log /fixture/ssh.log /fixture/writer-inventory.json /fixture/*/native-*/error-*.json; do
        if [ -f "$log" ]; then tail -n 25 "$log"; fi
    done
    exit "$code"
}
if [ "${1:-}" = control ]; then python3 tests/core_database_archive_smoke.py; fi
START
cat > "$fixture/core-native-fixture.target" <<'TARGET'
[Unit]
Description=Offline Core native-writer rehearsal
Requires=core-native-fixture.service
After=core-native-fixture.service
AllowIsolate=yes
TARGET
cat > "$fixture/core-native-fixture.service" <<'UNIT'
[Unit]
Description=Core actual native observers and paired recovery
Requires=basic.target dbus.service systemd-logind.service user@1003.service user@1004.service
After=basic.target dbus.service systemd-logind.service user@1003.service user@1004.service
JobTimeoutSec=180
JobTimeoutAction=exit-force
[Service]
Type=oneshot
WorkingDirectory=/app
ExecStart=/bin/bash /native-fixture.sh
TimeoutStartSec=600
StandardOutput=journal+console
StandardError=journal+console
UNIT
cat > "$fixture/native-fixture.sh" <<'NATIVE'
set -uo pipefail
export CORE_FIXTURE_NATIVE_WRITERS=1
for uid in 1003 1004; do
    runuser -u "$(getent passwd "$uid" | cut -d: -f1)" -- env XDG_RUNTIME_DIR="/run/user/$uid" \
        systemctl --user list-timers --all --no-legend --no-pager || { systemctl --no-block exit 1; exit 1; }
done
bash /start.sh "$(cat /fixture/scenario)"
result=$?
printf '%s\n' "$result" > /fixture/result.exit
systemctl --no-block exit "$result"
exit "$result"
NATIVE
docker build --quiet --build-arg "NATIVE_WRITERS=$native_writers" --tag "$image" "$fixture"
scenarios=(control abort-coordinator abort-before-hold abort-partial-hold abort-file-backup wrong-file-plan stale extra-db late-db guard-loss crash recover activate transfer-loss)
if [ "$#" -gt 0 ]; then scenarios=("$@"); fi
for scenario in "${scenarios[@]}"; do
    if [ "$native_writers" = 1 ]; then
        rootfs="$(mktemp -d "$fixture/rootfs.XXXXXXXX")"
        docker create --name "$container" "$image" >/dev/null
        docker export "$container" | sudo tar -xpf - -C "$rootfs"
        docker rm "$container" >/dev/null
        # docker export does not replace mktemp's root directory metadata.
        # A runner-owned mode-0700 / blocks native tmpfiles and both user managers.
        test "$(dirname "$(realpath "$rootfs")")" = "$(realpath "$fixture")"
        sudo chown 0:0 -- "$rootfs"
        sudo chmod 0755 -- "$rootfs"
        test "$(stat -c '%u:%g:%a' "$rootfs")" = 0:0:755
        printf '%s\n' "$scenario" | sudo tee "$rootfs/fixture/scenario" >/dev/null
        result=0
        sudo timeout --signal=TERM --kill-after=15s 660s systemd-nspawn --directory="$rootfs" \
            --machine="core-native-${GITHUB_RUN_ID}-${scenario}" --boot --private-network --register=no \
            --settings=no --console=pipe systemd.log_target=console systemd.show_status=yes || result=$?
        if [ "$result" != 0 ] || [ "$(sudo cat "$rootfs/fixture/result.exit" 2>/dev/null || true)" != 0 ]; then
            printf 'Native fixture %s exited with host status %s\n' "$scenario" "$result"
            sudo journalctl --directory="$rootfs/var/log/journal" --no-pager -n 200 || true
            sudo tail -n 30 "$rootfs/fixture/fpm.log" 2>/dev/null || true
            sudo find "$rootfs/fixture" -name 'error-*.json' -type f -exec cat {} \;
            sudo cat "$rootfs/fixture/commands.stderr" 2>/dev/null || true
            exit 1
        fi
        # Exact mktemp child of this fixture only; no live files or host-mounted data.
        test "$(dirname "$(realpath "$rootfs")")" = "$(realpath "$fixture")"
        sudo rm -rf --one-file-system -- "$rootfs"
        continue
    fi
    docker run --network none --name "$container" "$image" "$scenario" || { docker logs "$container"; exit 1; }
    docker rm "$container" >/dev/null
done
