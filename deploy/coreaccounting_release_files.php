<?php
/** Hash a release tree while refusing links and unreadable files. */
declare(strict_types=1);

function coreAccountingReleaseFileHashes(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
        if ($entry->isLink()) throw new RuntimeException("Linked release entry is not accepted: $relative");
        if (!$entry->isFile()) continue;
        $hash = hash_file('sha256', $entry->getPathname());
        if ($hash === false) throw new RuntimeException("Could not hash release entry: $relative");
        $files[$relative] = $hash;
    }
    ksort($files, SORT_STRING);
    return $files;
}
