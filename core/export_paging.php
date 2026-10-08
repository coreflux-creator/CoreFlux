<?php
declare(strict_types=1);

/** Fetch complete ordered exports in bounded pages. The caller owns a stable DB snapshot. */
function exportPagedRows(callable $fetchPage, int $pageSize = 1000): Generator
{
    if ($pageSize < 1) throw new InvalidArgumentException('Export page size must be positive.');

    $offset = 0;
    do {
        $page = $fetchPage($pageSize, $offset);
        if (!is_array($page) || count($page) > $pageSize) {
            throw new RuntimeException('Export dataset returned an invalid page.');
        }
        foreach ($page as $row) yield $row;
        $count = count($page);
        $offset += $count;
    } while ($count === $pageSize);
}

/** Send an already-complete CSV without loading it back into PHP memory. */
function exportCopyPreparedStream($stream): void
{
    if (!is_resource($stream) || rewind($stream) === false) {
        throw new RuntimeException('Could not read prepared export.');
    }
    while (!feof($stream)) {
        $chunk = fread($stream, 65536);
        if ($chunk === false) throw new RuntimeException('Could not read prepared export.');
        echo $chunk;
    }
}
