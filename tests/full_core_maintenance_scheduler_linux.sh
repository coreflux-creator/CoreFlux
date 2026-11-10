#!/usr/bin/env bash
# Invoked only as root inside the disposable maintenance fixture container.
set -euo pipefail
test -f /.dockerenv
test -f /run/core-maintenance-fixture
test "$(id -u)" = 0
export CORE_MAINTENANCE_HOST_FIXTURE="/tmp/coreflux-host-maintenance-fixture-$(php -r 'echo bin2hex(random_bytes(8));')"
mkdir -m 711 "$CORE_MAINTENANCE_HOST_FIXTURE"
mkdir -m 775 "$CORE_MAINTENANCE_HOST_FIXTURE/public_html"
chown coremaintfixture:coremaintweb "$CORE_MAINTENANCE_HOST_FIXTURE/public_html"
mkdir -m 700 "$CORE_MAINTENANCE_HOST_FIXTURE/scheduler-private"
chown coremaintscheduler:coremaintweb "$CORE_MAINTENANCE_HOST_FIXTURE/scheduler-private"
printf '# original app-owner synthetic web config\nOptions -Indexes\n' > "$CORE_MAINTENANCE_HOST_FIXTURE/public_html/.htaccess"
chown coremaintfixture:coremaintweb "$CORE_MAINTENANCE_HOST_FIXTURE/public_html/.htaccess"
chmod 664 "$CORE_MAINTENANCE_HOST_FIXTURE/public_html/.htaccess"
printf '# unrelated app-owner schedule\n0 0 * * * true\n' | crontab -u coremaintfixture -
before="$(crontab -u coremaintfixture -l | sha256sum)"
runuser -u coremaintscheduler -- php /app/tests/full_core_maintenance_scheduler_linux.php --confirm-disposable-container
after="$(crontab -u coremaintfixture -l | sha256sum)"
test "$before" = "$after"
echo 'PASS the separate application-owner crontab is byte-for-byte unchanged'
