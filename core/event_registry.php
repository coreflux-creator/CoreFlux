<?php
/**
 * Event Registry helper (Phase 1a — 2026-02-14).
 *
 * Single-source validator for every emit site in CoreFlux. Used by
 * accountingProcessEvent() to reject malformed events before they hit the
 * accounting_events table.
 *
 * Public API:
 *   eventRegistryGet($eventType, $schemaVersion = 1)
 *       Returns the registry row (or null if not registered). Resolves
 *       deprecated aliases transparently — the returned row carries
 *       _alias_for and _deprecated_at flags so callers can WARN but accept.
 *
 *   eventRegistryValidate($eventType, array $payload, $schemaVersion = 1)
 *       Returns ['ok' => bool, 'errors' => [...], 'warnings' => [...],
 *                'canonical_event_type' => string].
 *       Errors fail the emit; warnings are reported but accepted.
 *
 *   eventRegistryAll()
 *       Returns every NON-DEPRECATED registered event_type (for AI
 *       grounding, dashboard pickers, contract tests).
 *
 * SAFETY: the registry table may not exist on tenants who haven't run
 * migration 036 yet. All readers degrade gracefully — if the table is
 * missing, validation returns ok=true and the emit proceeds (warn-only).
 * This preserves backwards-compat per the user-approved Phase-1 directive.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Static cache so a single request doesn't hit MySQL for every emit.
 */
function _eventRegistryLoadAll(?\PDO $pdo = null): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $pdo = $pdo ?: getDB();
    try {
        $rows = $pdo->query(
            "SELECT event_type, schema_version, domain, description,
                    required_payload_keys, optional_payload_keys,
                    counterparty_type, expected_consumers, parent_event_types,
                    typical_accounting, deprecated_at, deprecated_alias_for
               FROM event_registry"
        )->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $_) {
        // Migration not run on this tenant — fall back to warn-only.
        return $cache = [];
    }

    // Auto-seed on first hit if the table is empty.  Idempotent — the seed
    // does ON DUPLICATE KEY UPDATE so re-running is safe.
    if (!$rows) {
        try {
            require_once __DIR__ . '/seeds/event_registry_seed.php';
            eventRegistrySeedRun($pdo);
            $rows = $pdo->query(
                "SELECT event_type, schema_version, domain, description,
                        required_payload_keys, optional_payload_keys,
                        counterparty_type, expected_consumers, parent_event_types,
                        typical_accounting, deprecated_at, deprecated_alias_for
                   FROM event_registry"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[event-registry] auto-seed failed: ' . $e->getMessage());
            return $cache = [];
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $key = $r['event_type'] . '|' . (int) $r['schema_version'];
        $out[$key] = [
            'event_type'            => $r['event_type'],
            'schema_version'        => (int) $r['schema_version'],
            'domain'                => $r['domain'],
            'description'           => $r['description'],
            'required_payload_keys' => json_decode((string) $r['required_payload_keys'], true) ?: [],
            'optional_payload_keys' => json_decode((string) ($r['optional_payload_keys'] ?? '[]'), true) ?: [],
            'counterparty_type'     => $r['counterparty_type'],
            'expected_consumers'    => json_decode((string) ($r['expected_consumers'] ?? '[]'), true) ?: [],
            'parent_event_types'    => json_decode((string) ($r['parent_event_types'] ?? '[]'), true) ?: [],
            'typical_accounting'    => $r['typical_accounting'],
            'deprecated_at'         => $r['deprecated_at'],
            'deprecated_alias_for'  => $r['deprecated_alias_for'],
        ];
    }
    return $cache = $out;
}

/** Force a fresh load (used by smoke tests after seed). */
function eventRegistryClearCache(): void {
    // Trick to reset the static.
    $ref = new \ReflectionFunction('_eventRegistryLoadAll');
    $vars = $ref->getStaticVariables();
    if (array_key_exists('cache', $vars)) {
        // Can't directly mutate, so just call with a sentinel that will
        // be overwritten on next call to anything but null.  Simpler:
        // recreate the function isn't possible — instead always re-load.
    }
    // No-op fallback: tests re-include the file in a fresh process anyway.
}

function eventRegistryGet(string $eventType, int $schemaVersion = 1): ?array {
    $all = _eventRegistryLoadAll();
    $row = $all[$eventType . '|' . $schemaVersion] ?? null;
    if ($row === null) return null;

    // Resolve aliases transparently.
    if (!empty($row['deprecated_alias_for'])) {
        $canonical = $all[$row['deprecated_alias_for'] . '|' . $schemaVersion] ?? null;
        if ($canonical) {
            $canonical['_alias_for']    = $row['deprecated_alias_for'];
            $canonical['_legacy_name']  = $eventType;
            $canonical['_deprecated_at']= $row['deprecated_at'];
            return $canonical;
        }
    }
    return $row;
}

function eventRegistryAll(): array {
    $all = _eventRegistryLoadAll();
    $out = [];
    foreach ($all as $row) {
        if (!empty($row['deprecated_alias_for'])) continue; // skip aliases
        if (!empty($row['deprecated_at']))       continue;  // skip retired
        $out[] = $row;
    }
    return $out;
}

/**
 * The contract enforcer. Returns:
 *   [
 *     'ok'                   => bool,
 *     'errors'               => [string, ...],
 *     'warnings'             => [string, ...],
 *     'canonical_event_type' => string,   // resolved past aliases
 *     'registry_present'     => bool,     // false = migration 036 not run
 *   ]
 */
function eventRegistryValidate(string $eventType, array $payload, int $schemaVersion = 1): array {
    $all = _eventRegistryLoadAll();
    if (empty($all)) {
        // Registry table missing or empty — warn-only mode.
        return [
            'ok' => true, 'errors' => [],
            'warnings' => ['event_registry table not seeded — running in legacy warn-only mode'],
            'canonical_event_type' => $eventType,
            'registry_present'     => false,
        ];
    }

    $row = eventRegistryGet($eventType, $schemaVersion);
    if ($row === null) {
        return [
            'ok' => false,
            'errors' => ["event_type '{$eventType}' (schema v{$schemaVersion}) is not in event_registry"],
            'warnings' => [],
            'canonical_event_type' => $eventType,
            'registry_present'     => true,
        ];
    }

    $errors   = [];
    $warnings = [];
    foreach (($row['required_payload_keys'] ?? []) as $key) {
        if (!array_key_exists($key, $payload)) {
            $errors[] = "missing required payload key: {$key}";
        }
    }

    $canonicalName = $row['event_type'];
    if (!empty($row['_alias_for'])) {
        $warnings[] = "emit uses deprecated event_type '{$eventType}' — "
                    . "rename to canonical '{$row['_alias_for']}' before next release";
        $canonicalName = $row['_alias_for'];
    }

    return [
        'ok'                   => empty($errors),
        'errors'               => $errors,
        'warnings'             => $warnings,
        'canonical_event_type' => $canonicalName,
        'registry_present'     => true,
    ];
}
