#!/usr/bin/env bash
# Real crontab and ownership operations in a disposable container, never on the runner/Cloudways scheduler.
set -euo pipefail
cd "$(dirname "$0")/.."
fixture="$(mktemp -d "${RUNNER_TEMP:-/tmp}/core-maintenance-host.XXXXXXXX")"
image="core-maintenance-host:${GITHUB_RUN_ID:-local}-${RANDOM}"
trap 'docker image rm "$image" >/dev/null 2>&1 || true' EXIT
mkdir -p "$fixture/deploy" "$fixture/tests"
cp deploy/full_core_maintenance.php deploy/full_core_maintenance_host.php "$fixture/deploy/"
cp tests/full_core_maintenance_host_linux.php "$fixture/tests/"
cat > "$fixture/Dockerfile" <<'DOCKER'
FROM php:8.2-cli
RUN apt-get update && apt-get install -y --no-install-recommends cron \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd coremaintweb && useradd --create-home --groups coremaintweb coremaintfixture \
    && touch /run/core-maintenance-fixture
COPY deploy /app/deploy
COPY tests /app/tests
USER coremaintfixture
WORKDIR /app
CMD ["bash", "-c", "export CORE_MAINTENANCE_HOST_FIXTURE=/tmp/coreflux-host-maintenance-fixture-$(php -r 'echo bin2hex(random_bytes(8));'); mkdir -m 700 \"$CORE_MAINTENANCE_HOST_FIXTURE\"; mkdir -m 700 \"$CORE_MAINTENANCE_HOST_FIXTURE/private\"; mkdir -m 755 \"$CORE_MAINTENANCE_HOST_FIXTURE/public_html\"; php tests/full_core_maintenance_host_linux.php --confirm-disposable-container"]
DOCKER
docker build --quiet --tag "$image" "$fixture"
docker run --rm --network none "$image"
