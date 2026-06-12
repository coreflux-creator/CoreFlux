<?php
/**
 * Restaurant vertical alignment smoke test.
 *
 * Run with: php tests/restaurant_alignment_smoke.php
 *
 * This is intentionally static until a Restaurant module exists. The purpose is
 * to lock the product boundary so the future vertical consumes shared CoreFlux
 * primitives instead of reintroducing a separate app or duplicate platform
 * services.
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$assert = function (string $name, bool $cond, ?string $hint = null) use (&$pass, &$fail): void {
    if ($cond) {
        echo "  OK {$name}\n";
        $pass++;
    } else {
        echo "  FAIL {$name}" . ($hint ? " ({$hint})" : '') . "\n";
        $fail++;
    }
};

$root = dirname(__DIR__);
$docPath = $root . '/docs/RESTAURANT_ALIGNMENT.md';
$alignmentPath = $root . '/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md';
$moduleDir = $root . '/modules/restaurant';
$manifestPath = $moduleDir . '/manifest.php';

$doc = is_file($docPath) ? (string) file_get_contents($docPath) : '';
$alignment = is_file($alignmentPath) ? (string) file_get_contents($alignmentPath) : '';

echo "Restaurant alignment doc\n";
$assert('alignment doc exists', $doc !== '');

$requiredDocPhrases = [
    'native CoreFlux module',
    'not as a separate Cloudways',
    'consumer-orchestrator',
    'Core AP for official bills',
    'Core Accounting for the GL',
    'Core People',
    'Core Payroll',
    'Core Reporting',
    'People Graph',
    'Workflow Graph',
    'enterprise controls',
    'must not own or duplicate',
    'RBAC, audit logging, custom-field engines',
    'report builder engines',
    'separation-of-duties checks',
    'auditable before/after state',
];

foreach ($requiredDocPhrases as $phrase) {
    $assert("doc mentions {$phrase}", strpos($doc, $phrase) !== false);
}

echo "\nArchitecture alignment index\n";
$assert(
    'product alignment links Restaurant guard',
    strpos($alignment, 'docs/RESTAURANT_ALIGNMENT.md') !== false
);
$assert(
    'product alignment keeps Restaurant as consumer-orchestrator',
    strpos($alignment, 'native CoreFlux consumer-orchestrator') !== false
);
$assert(
    'product alignment names forbidden duplicated primitives',
    strpos($alignment, 'Workflow Graph, or') !== false
        && strpos($alignment, 'People Graph primitives') !== false
);

echo "\nFuture module guard\n";
if (!is_dir($moduleDir)) {
    $assert('restaurant module is not implemented yet', true);
    $assert(
        'doc states future manifest requirements',
        strpos($doc, 'When `modules/restaurant/manifest.php` is introduced') !== false
    );
} else {
    $assert('restaurant directory has manifest', is_file($manifestPath), 'modules/restaurant requires manifest.php');
    $manifest = is_file($manifestPath) ? require $manifestPath : null;
    $assert('manifest returns array', is_array($manifest));

    if (is_array($manifest)) {
        $assert('manifest id is restaurant', ($manifest['id'] ?? null) === 'restaurant');

        $mode = $manifest['mode']
            ?? ($manifest['people_graph']['mode'] ?? null)
            ?? ($manifest['people_graph']['consumption_mode'] ?? null);
        $assert('manifest declares consumer-orchestrator mode', $mode === 'consumer_orchestrator');

        $dependsOn = $manifest['depends_on'] ?? [];
        foreach (['ap', 'accounting', 'people', 'payroll', 'reports'] as $dependency) {
            $assert("manifest depends on {$dependency}", in_array($dependency, $dependsOn, true));
        }

        $assert('manifest declares people_graph consumption', !empty($manifest['people_graph']));
        $assert('manifest declares permissions', !empty($manifest['permissions']) && is_array($manifest['permissions']));
        $assert('manifest declares audit events', !empty($manifest['audit_events']) && is_array($manifest['audit_events']));
        $assert('manifest declares workflows', !empty($manifest['workflows']) && is_array($manifest['workflows']));
        $assert('manifest declares governed exports', isset($manifest['exports']) && is_array($manifest['exports']));
    }
}

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
