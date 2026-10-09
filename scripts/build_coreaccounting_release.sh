#!/usr/bin/env bash
# Build a standalone runtime from a committed CoreFlux/CoreOne source snapshot.
set -euo pipefail

if [[ $# -ne 1 || "$1" != /* ]]; then
  echo 'Usage: build_coreaccounting_release.sh /absolute/new/output-directory' >&2
  exit 2
fi
for command in git tar php composer realpath sha256sum; do
  command -v "$command" >/dev/null || { echo "Missing build tool: $command" >&2; exit 2; }
done

source_root="$(git rev-parse --show-toplevel)"
commit="$(git -C "$source_root" rev-parse HEAD)"
parent="$(realpath "$(dirname "$1")")"
output="$parent/$(basename "$1")"
if [[ -e "$output" || "$output" == "$source_root" || "$output" == "$source_root/"* ]]; then
  echo 'Output must be new and outside the source checkout.' >&2
  exit 2
fi
mkdir -m 700 "$output"
mkdir -m 750 "$output/public_html"

git -C "$source_root" archive --format=tar "$commit" | tar -xf - -C "$output/public_html"
composer install --working-dir="$output/public_html" --no-dev --no-interaction \
  --prefer-dist --optimize-autoloader --no-scripts --no-plugins

COREFLUX_ENV=coreaccounting \
COREFLUX_STANDALONE_WEBROOT="$output/public_html" \
COREFLUX_PUBLIC_ORIGIN=https://package.coreaccounting.invalid \
COREFLUX_STANDALONE_DATABASE=package_build \
php "$output/public_html/deploy/coreaccounting_webroot_finalizer.php" \
  --confirm-standalone-webroot \
  --webroot="$output/public_html" --expected-webroot="$output/public_html" \
  --origin=https://package.coreaccounting.invalid --database=package_build \
  --private="$output/private_build_only"

COREFLUX_ENV=coreaccounting php "$output/public_html/deploy/create_coreaccounting_release_manifest.php" \
  --confirm-package-build --root="$output/public_html" \
  --output="$output/manifest.json" --commit="$commit"
tar -czf "$output/release.tar.gz" -C "$output" public_html manifest.json
sha256sum "$output/release.tar.gz"
