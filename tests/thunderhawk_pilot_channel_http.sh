#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
fixture="$(mktemp -d "${RUNNER_TEMP:-/tmp}/pilot-channel.XXXXXXXX")"
image="pilot-channel:${GITHUB_RUN_ID:-local}-${RANDOM}"
container="pilot-channel-${GITHUB_RUN_ID:-local}-${RANDOM}"
trap 'docker rm -f "$container" >/dev/null 2>&1 || true; docker image rm "$image" >/dev/null 2>&1 || true' EXIT
mkdir -p "$fixture/deploy" "$fixture/tests"
cp deploy/full_core_maintenance.php deploy/full_core_maintenance_channel.php deploy/thunderhawk_pilot_channel.php deploy/thunderhawk_pilot_channel_web.php "$fixture/deploy/"
cp tests/thunderhawk_pilot_channel_fixture.php tests/thunderhawk_pilot_channel_http.py "$fixture/tests/"
cat > "$fixture/Dockerfile" <<'DOCKER'
FROM php:8.2-fpm
RUN apt-get update && apt-get install -y --no-install-recommends apache2 python3 \
    && rm -rf /var/lib/apt/lists/* && docker-php-ext-install opcache \
    && a2enmod rewrite headers proxy proxy_fcgi >/dev/null \
    && mkdir /fixture && touch /run/pilot-channel-fixture
RUN printf '[www]\npm.max_children=3\npm.start_servers=2\npm.min_spare_servers=1\npm.max_spare_servers=2\nphp_admin_value[disable_functions]=posix_geteuid\n' > /usr/local/etc/php-fpm.d/zzz-fixture.conf \
    && printf 'opcache.enable=1\nopcache.validate_timestamps=0\nopcache.file_update_protection=0\n' > /usr/local/etc/php/conf.d/fixture.ini
COPY deploy /app/deploy
COPY tests /app/tests
COPY apache.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /app
CMD ["bash","-c","php tests/thunderhawk_pilot_channel_fixture.php && php-fpm -D && . /etc/apache2/envvars && mkdir -p \"$APACHE_RUN_DIR\" \"$APACHE_LOCK_DIR\" && exec apache2 -DFOREGROUND"]
DOCKER
cat > "$fixture/apache.conf" <<'APACHE'
ServerName fixture.local
<VirtualHost *:80>
    DocumentRoot /fixture/public_html
    <Directory /fixture/public_html>
        AllowOverride All
        Require all granted
    </Directory>
    <FilesMatch "\.php$">
        SetHandler "proxy:fcgi://127.0.0.1:9000"
    </FilesMatch>
</VirtualHost>
APACHE
docker build --quiet --tag "$image" "$fixture"
docker run --detach --network none --name "$container" "$image" >/dev/null
if ! docker exec "$container" python3 /app/tests/thunderhawk_pilot_channel_http.py; then
    docker logs "$container"
    exit 1
fi
