<?php
/** Stable migration identity across LF and CRLF deployment packages. */
declare(strict_types=1);

function corefluxMigrationHash(string $sql): string
{
    return hash('sha256', str_replace("\r\n", "\n", $sql));
}

function corefluxMigrationHashMatches(?string $recorded, string $sql): bool
{
    if ($recorded === null || !preg_match('/^[a-f0-9]{64}$/D', $recorded)) return false;

    $normalized = str_replace("\r\n", "\n", $sql);
    foreach ([
        hash('sha256', $normalized),
        hash('sha256', $sql),
        hash('sha256', str_replace("\n", "\r\n", $normalized)),
    ] as $candidate) {
        if (hash_equals($recorded, $candidate)) return true;
    }
    return false;
}
