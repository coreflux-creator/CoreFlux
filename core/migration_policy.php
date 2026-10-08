<?php
/** Standalone accounting schema changes are release operations, not web startup work. */
declare(strict_types=1);

function corefluxMayApplySchemaChanges(string $environment, string $sapi): bool
{
    return $environment !== 'coreaccounting' || $sapi === 'cli';
}
