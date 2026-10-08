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
