<?php
/** Read-only assertion that isolated staging serves its installed SPA release. */
declare(strict_types=1);

const COREACCOUNTING_STAGE_ROOT = '/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const COREACCOUNTING_STAGE_ORIGIN = 'https://phpstack-1516771-6707602.cloudwaysapps.com';

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || realpath(dirname(__DIR__)) !== COREACCOUNTING_STAGE_ROOT) {
    fwrite(STDERR, "Run only from the isolated CoreFlux staging app with COREFLUX_ENV=staging.\n");
    exit(2);
}
if (count($argv) !== 2
    || !preg_match('/^--expected-index-sha256=([a-f0-9]{64})$/D', $argv[1], $expectedMatch)) {
    fwrite(STDERR, "Pass --expected-index-sha256=<SHA-256 of committed dashboard/dist/index.html>.\n");
    exit(2);
}

function coreaccountingStageFetch(string $path): string
{
    $curl = curl_init(COREACCOUNTING_STAGE_ORIGIN . $path);
    if ($curl === false) throw new RuntimeException('Could not start staging HTTP check.');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => ['Cache-Control: no-cache', 'Pragma: no-cache'],
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false || $status !== 200) {
        throw new RuntimeException("Staging {$path} returned HTTP {$status}" .
            ($error !== '' ? " ({$error})" : '') . '.');
    }
    return $body;
}

function coreaccountingStageEntryAssets(string $html): array
{
    $document = new DOMDocument();
    if (!$document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
        throw new RuntimeException('The installed HTML could not be parsed.');
    }
    $xpath = new DOMXPath($document);
    $assets = [];
    foreach ($xpath->query('//script[@src] | //link[@href]') as $node) {
        $path = $node->getAttribute($node->nodeName === 'script' ? 'src' : 'href');
        if (preg_match('#^/spa-assets/(index-[A-Za-z0-9_-]+\.(?:js|css))$#D', $path, $match)) {
            $assets[$match[1]] = true;
        }
    }
    if (!array_filter(array_keys($assets), static fn(string $asset): bool => str_ends_with($asset, '.js'))
        || !array_filter(array_keys($assets), static fn(string $asset): bool => str_ends_with($asset, '.css'))) {
        throw new RuntimeException('The installed HTML has no complete JS/CSS entry pair.');
    }
    return array_keys($assets);
}

try {
    $root = dirname(__DIR__);
    $installedIndex = (string) file_get_contents($root . '/index.html');
    $distIndex = (string) file_get_contents($root . '/dashboard/dist/index.html');
    if (!hash_equals($expectedMatch[1], hash('sha256', $distIndex))) {
        throw new RuntimeException('The installed dist entry differs from the expected committed release.');
    }
    if ($installedIndex !== $distIndex) {
        throw new RuntimeException('Root and dashboard/dist entry HTML differ.');
    }
    if (coreaccountingStageFetch('/index.html') !== $distIndex) {
        throw new RuntimeException('The HTTP-served entry HTML differs from the installed dist entry.');
    }
    echo "PASS served entry HTML matches the expected committed release\n";

    $stamp = (string) file_get_contents($root . '/.deploy-version');
    $queue = coreaccountingStageEntryAssets($distIndex);
    $seen = [];
    for ($index = 0; $index < count($queue); $index++) {
        $asset = $queue[$index];
        if (isset($seen[$asset])) continue;
        $seen[$asset] = true;
        $path = $root . '/spa-assets/' . $asset;
        if (!is_file($path)) throw new RuntimeException("Installed asset {$asset} is missing.");
        if (!str_contains($stamp, 'spa-assets/' . $asset)) {
            throw new RuntimeException("Installed asset {$asset} is missing from .deploy-version.");
        }
        $installed = (string) file_get_contents($path);
        $served = coreaccountingStageFetch('/spa-assets/' . $asset);
        if (!hash_equals(hash('sha256', $installed), hash('sha256', $served))) {
            throw new RuntimeException("HTTP-served asset {$asset} differs from the installed file.");
        }
        if (str_ends_with($asset, '.js')) {
            preg_match_all('/index-[A-Za-z0-9_-]+\.(?:js|css)/', $installed, $references);
            foreach (array_unique($references[0] ?? []) as $reference) {
                if (!isset($seen[$reference])) $queue[] = $reference;
            }
        }
        echo "PASS served {$asset} matches installed SHA-256\n";
    }
    echo 'coreaccounting_staging_release: ' . count($seen) . " assets verified\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . "\n");
    exit(1);
}
