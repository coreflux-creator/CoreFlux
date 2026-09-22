<?php
declare(strict_types=1);

/** Hash snapshot values, not the database's JSON serialization. */
function staffingDimensionSnapshotCanonicalJson(array $snapshot): string {
    $normalize = static function (mixed $value) use (&$normalize): mixed {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) $value[$key] = $normalize($item);
        return $value;
    };
    return json_encode($normalize($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
