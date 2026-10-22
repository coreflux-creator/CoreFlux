<?php
/** Maintenance preparation only. This library never installs a web rule or changes a scheduler. */
declare(strict_types=1);

function fullCoreMaintenanceDigest(string $bytes, string $expected): void
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $expected) || !hash_equals($expected, hash('sha256', $bytes))) {
        throw new RuntimeException('Maintenance source changed; inspect it again before proceeding.');
    }
}

/** The original file belongs in a private recovery receipt, never in this public replacement. */
function fullCoreMaintenanceWebRule(string $operation): string
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $operation)) throw new RuntimeException('Invalid maintenance operation.');
    // InheritDownBefore covers the API and dashboard's own per-directory rewrite rules.
    // No IfModule around mod_rewrite: missing support must fail closed, not expose the app.
    return "# CoreFlux maintenance $operation\n"
        . "Options -Indexes\nRewriteEngine On\nRewriteOptions InheritDownBefore\n"
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
