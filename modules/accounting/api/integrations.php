<?php
/**
 * Accounting provider integrations alias.
 *
 * Canonical v1 path for provider-neutral accounting connection, mapping,
 * sync, and command actions. The legacy /api/accounting.php dispatcher
 * remains the implementation while provider actions migrate behind the
 * module API surface.
 */
require __DIR__ . '/../../../api/accounting.php';
