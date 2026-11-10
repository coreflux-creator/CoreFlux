<?php
/** Maintenance preparation only. This library never installs a web rule or changes a scheduler. */
declare(strict_types=1);

function fullCoreMaintenanceDigest(string $bytes, string $expected): void
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $expected) || !hash_equals($expected, hash('sha256', $bytes))) {
        throw new RuntimeException('Maintenance source changed; inspect it again before proceeding.');
    }
}

/** Only the native C-locale missing-table result is an empty scheduler; other errors stay fatal. */
function fullCoreMaintenanceCrontabAbsent(int $exit, string $output, string $diagnostic, string $account): bool
{
    return $exit === 1 && $output === '' && $account !== ''
        && !preg_match('/[\x00-\x20\x7f]/', $account)
        && $diagnostic === 'no crontab for ' . $account . "\n";
}

/** The original file belongs in a private recovery receipt, never in this public replacement. */
function fullCoreMaintenanceWebRule(string $operation, ?string $diagnosticPath = null): string
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $operation)) throw new RuntimeException('Invalid maintenance operation.');
    $channel = '';
    if ($diagnosticPath !== null) {
        if (!preg_match('/^core-maintenance-' . $operation . '-[a-f0-9]{32}\\.php$/D', $diagnosticPath)) {
            throw new RuntimeException('Invalid operation-bound diagnostic path.');
        }
        $pattern = str_replace('.', '\\.', $diagnosticPath);
        // Only the inert diagnostic script is exempt. Its own authentication, exact path and
        // hold checks remain mandatory. END prevents descendant/front-controller rewrites.
        $channel = "RewriteCond %{REQUEST_METHOD} =POST\n"
            . "RewriteCond %{REQUEST_URI} ^/$pattern$\nRewriteCond %{QUERY_STRING} ^$\n"
            . "RewriteRule ^$pattern$ - [END]\n";
    }
    // InheritDownBefore covers the API and dashboard's own per-directory rewrite rules.
    // No IfModule around mod_rewrite: missing support must fail closed, not expose the app.
    return "# CoreFlux maintenance $operation\n"
        . "Options -Indexes\nRewriteEngine On\nRewriteOptions InheritDownBefore\n"
        . $channel
        . "RewriteRule ^ - [R=503,L]\n"
        . "ErrorDocument 503 \"CoreFlux is temporarily unavailable for maintenance. Please try again shortly.\"\n"
        . "<IfModule mod_headers.c>\nHeader always set Retry-After \"300\"\n"
        . "Header always set Cache-Control \"no-store\"\n</IfModule>\n";
}

/**
 * Review every active job by its physical line number and exact line hash. Pause whole lines,
 * never wrap/rewrite shell commands (which may contain pipes, semicolons, percent input, etc.).
 * A review entry is ['sha256'=>..., 'action'=>'pause'|'keep']. Environment/comments stay byte-exact.
 * Relative/indirect Core jobs must be identified in the inventory and explicitly marked pause.
 * This function cannot discover systemd, external schedulers, alternate owners or running work.
 */
function fullCoreMaintenanceCron(string $source, string $expectedHash, string $root, array $review, string $operation): array
{
    fullCoreMaintenanceDigest($source, $expectedHash);
    fullCoreMaintenanceWebRule($operation); // Validate the shared operation identifier.
    if (!preg_match('~^/(?:[A-Za-z0-9_][A-Za-z0-9_.-]*/)+public_html$~D', $root)
        || strlen($source) > 1048576 || str_contains($source, "\0") || preg_match('/\r(?!\n)/', $source)) {
        throw new RuntimeException('Unsupported scheduler source or target root.');
    }
    $parts = preg_split('/(\r\n|\n)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);
    $seen = []; $paused = 0; $kept = 0;
    for ($i = 0, $lineNumber = 1; $i < count($parts); $i += 2, $lineNumber++) {
        $line = $parts[$i]; $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*=/', $trimmed)) continue;
        if (!preg_match('/^(?:@(?:reboot|yearly|annually|monthly|weekly|daily|midnight|hourly)\s+\S|(?:\S+\s+){5}\S)/', $trimmed)) {
            throw new RuntimeException('Unrecognized active scheduler line.');
        }
        $item = $review[$lineNumber] ?? null;
        if (!is_array($item) || !is_string($item['sha256'] ?? null)
            || !in_array($item['action'] ?? null, ['pause', 'keep'], true)) {
            throw new RuntimeException('Every active scheduler line needs an exact reviewed disposition.');
        }
        fullCoreMaintenanceDigest($line, $item['sha256']); $seen[$lineNumber] = true;
        if ($item['action'] === 'pause') {
            $parts[$i] = '# coreflux-maintenance-' . $operation . ' ' . $line; $paused++;
        } else {
            if (str_contains($line, $root)) throw new RuntimeException('A target-root job cannot remain enabled.');
            $kept++;
        }
    }
    if (array_diff_key($review, $seen)) throw new RuntimeException('Review includes missing or inactive scheduler lines.');
    $held = implode('', $parts);
    return ['original_sha256' => $expectedHash, 'held_sha256' => hash('sha256', $held),
        'held_bytes' => $held, 'paused_jobs' => $paused, 'retained_jobs' => $kept];
}

/** Pure comparison for a future locked host adapter; the adapter must recheck at the actual write. */
function fullCoreMaintenanceRestore(string $original, string $originalHash, string $heldHash, string $current): string
{
    fullCoreMaintenanceDigest($original, $originalHash);
    fullCoreMaintenanceDigest($current, $heldHash);
    return $original;
}

/** Compare the actual absent-file response before/after, removing only the router's validated trace ID. */
function fullCoreMaintenanceAbsentSignature(int $status, string $body): array
{
    if ($status === 400) {
        $value = json_decode($body, true);
        $keys = is_array($value) ? array_keys($value) : []; sort($keys);
        if ($keys !== ['error','request_id','status'] || ($value['status'] ?? null) !== 400
            || ($value['error'] ?? '') !== 'Bad request: expected /api/<module>/<endpoint> or /api/v1/<module>/<resource>'
            || !is_string($value['request_id'] ?? null) || !preg_match('/^[a-f0-9]{16}$/D', $value['request_id'])) {
            throw new RuntimeException('Unexpected missing-file API response.');
        }
        unset($value['request_id']); ksort($value);
        $body = json_encode($value, JSON_THROW_ON_ERROR);
    } elseif ($status !== 404) {
        throw new RuntimeException('Missing synthetic probe must not execute or redirect.');
    }
    return ['status' => $status, 'body_sha256' => hash('sha256', $body)];
}
