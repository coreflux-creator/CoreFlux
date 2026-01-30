<?php
// Debug dashboard loading
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Testing Dashboard Components</h2><pre>";

// Test 1: Can we load config?
echo "1. Loading config... ";
try {
    require_once __DIR__ . '/core/config.php';
    echo "✓ OK\n";
} catch (Throwable $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}

// Test 2: Can we load modules?
echo "2. Loading modules... ";
try {
    require_once __DIR__ . '/core/modules.php';
    echo "✓ OK\n";
    $modules = getModuleDefinitions();
    echo "   Found " . count($modules) . " modules\n";
} catch (Throwable $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}

// Test 3: Can we load auth?
echo "3. Loading auth... ";
try {
    require_once __DIR__ . '/core/auth.php';
    echo "✓ OK\n";
} catch (Throwable $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}

// Test 4: Can we create a demo session?
echo "4. Creating demo session... ";
try {
    createDemoSession('admin');
    echo "✓ OK\n";
    echo "   User: " . print_r($_SESSION['user'], true) . "\n";
} catch (Throwable $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}

// Test 5: Check view file
echo "5. Checking view file... ";
$viewPath = __DIR__ . '/modules/people/views/overview.php';
if (file_exists($viewPath)) {
    echo "✓ EXISTS\n";
} else {
    echo "✗ MISSING\n";
}

echo "\n</pre>";
echo "<p><strong>If all tests pass, try:</strong> <a href='dashboard.php?demo=admin'>dashboard.php?demo=admin</a></p>";
