#!/usr/bin/env bash
# Actual source contents, two native non-root owners, no network or host application mounts.
set -euo pipefail
cd "$(dirname "$0")/.."
fixture="$(mktemp -d "${RUNNER_TEMP:-/tmp}/core-release-files.XXXXXXXX")"
image="core-release-files:${GITHUB_RUN_ID:-local}-${RANDOM}"
trap 'docker image rm "$image" >/dev/null 2>&1 || true' EXIT
mkdir -p "$fixture/deploy" "$fixture/tests" "$fixture/core"
cp deploy/full_core_release_files.php deploy/full_core_maintenance.php deploy/full_core_maintenance_channel.php "$fixture/deploy/"
cp core/migration_hash.php "$fixture/core/"
cp tests/full_core_release_files_linux.php "$fixture/tests/"
cat > "$fixture/Dockerfile" <<'DOCKER'
FROM php:8.2-cli
RUN apt-get update && apt-get install -y --no-install-recommends util-linux \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --uid 1003 --gid 33 corefilemaster && useradd --uid 1004 --gid 33 corefileapp \
    && mkdir /fixture && touch /run/core-release-files-fixture
COPY deploy /app/deploy
COPY core /app/core
COPY tests /app/tests
WORKDIR /app
CMD ["php", "tests/full_core_release_files_linux.php"]
DOCKER
docker build --quiet --tag "$image" "$fixture"
docker run --rm --network none "$image"
