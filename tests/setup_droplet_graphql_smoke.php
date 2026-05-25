<?php
/**
 * Smoke for /app/scripts/setup_droplet_graphql.sh
 *
 * Validates the DigitalOcean droplet setup script:
 *   1. File exists, executable, valid bash syntax.
 *   2. Documents all required env (COREFLUX_API_BASE, JWT_SECRET).
 *   3. Fails closed when required env is missing.
 *   4. Installs Node 20, Apollo Router latest, systemd units.
 *   5. Doesn't reuse Cloudways-specific paths (/etc/nginx, no nginx wiring).
 *   6. Locks down port 4000 via UFW when CLOUDWAYS_APP_IP is set.
 *   7. Prints the nginx snippet operator must paste into Cloudways.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$script = '/app/scripts/setup_droplet_graphql.sh';

echo "\nFile + syntax\n";
$a('script exists',         is_file($script));
$a('script is executable',  is_executable($script));
exec('bash -n ' . escapeshellarg($script) . ' 2>&1', $synOut, $synRc);
$a('bash -n passes',        $synRc === 0, implode("\n", $synOut));

$src = (string) file_get_contents($script);

echo "\nRequired inputs\n";
$a('requires COREFLUX_API_BASE', str_contains($src, 'COREFLUX_API_BASE env var is required'));
$a('requires JWT_SECRET',        str_contains($src, 'JWT_SECRET env var is required'));
$a('honors CLOUDWAYS_APP_IP',    str_contains($src, 'CLOUDWAYS_APP_IP'));
$a('honors REPO_URL override',   str_contains($src, 'REPO_URL="${REPO_URL:-https://github.com/coreflux-creator/CoreFlux.git}"'));

echo "\nInstall plan\n";
$a('installs Node 20',           str_contains($src, 'setup_20.x'));
$a('installs Apollo latest',     str_contains($src, 'router.apollo.dev/download/nix/latest'));
$a('creates coreflux user',      str_contains($src, 'useradd --system'));
$a('writes /etc/coreflux/graphql.env', str_contains($src, '/etc/coreflux/graphql.env'));
$a('generates INTERNAL_HMAC_SECRET', str_contains($src, 'openssl rand -hex 32'));
$a('installs all 4 systemd units',
    str_contains($src, 'coreflux-subgraph-coreflux') &&
    str_contains($src, 'coreflux-subgraph-jobdiva') &&
    str_contains($src, 'coreflux-router') &&
    str_contains($src, 'coreflux-mcp'));
$a('strips php8.2-fpm dep from units (droplet has no PHP)',
    str_contains($src, 'sed \'s| php8.2-fpm.service||g\''));

echo "\nDoesn't touch Cloudways constructs\n";
$a('does NOT modify nginx config', !str_contains($src, '/etc/nginx/sites-'));
$a('does NOT assume /app',        !str_contains($src, ' /app/graphql'));
$a('uses /opt/coreflux/source',   str_contains($src, '/opt/coreflux/source'));
$a('uses /opt/coreflux/graphql',  str_contains($src, '/opt/coreflux/graphql'));

echo "\nFirewall\n";
$a('uses UFW',                          str_contains($src, 'ufw '));
$a('preserves SSH (allow 22/tcp)',      str_contains($src, 'ufw allow 22/tcp'));
$a('restricts :4000 to Cloudways IP',   str_contains($src, 'ufw allow from $CLOUDWAYS_APP_IP to any port 4000'));

echo "\nFinal operator instructions\n";
$a('prints the HMAC for Cloudways copy', str_contains($src, 'INTERNAL_HMAC_SECRET (you MUST set this on Cloudways too)'));
$a('prints nginx location /graphql snippet', str_contains($src, 'location /graphql'));
$a('prints proxy_pass to droplet :4000', str_contains($src, 'proxy_pass http://$DROPLET_IP:4000/'));

echo "\nIdempotency\n";
$a('preserves existing HMAC on re-run',
    str_contains($src, 'EXISTING_HMAC') &&
    str_contains($src, 'grep -E \'^INTERNAL_HMAC_SECRET='));
$a('uses git pull --ff-only on existing checkout', str_contains($src, 'git -C $SRC_DIR pull --ff-only'));

echo "\n--help / runtime fail-closed\n";
// Without required env, the script must die in pre-flight.
exec('bash ' . escapeshellarg($script) . ' 2>&1', $rOut, $rRc);
$joined = implode("\n", $rOut);
$a('runs pre-flight (mentions ERROR or required)', $rRc !== 0 && stripos($joined, 'required') !== false);

echo "\n=========================================\n";
echo "Droplet GraphQL setup smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
