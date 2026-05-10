<?php
/**
 * Smoke: ErrorBoundary mounted around module Routes.
 *
 * Regression: a single component crash (e.g. SQL fallback shape that breaks
 * .map()) was blanking the entire content area instead of showing an inline
 * recovery banner.
 */
declare(strict_types=1);

$boundary = (string) file_get_contents(__DIR__ . '/../dashboard/src/components/ErrorBoundary.jsx');
$app      = (string) file_get_contents(__DIR__ . '/../dashboard/src/App.jsx');

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

echo "ErrorBoundary component\n";
$a('component file exists',                  $boundary !== '');
$a('exports default class ErrorBoundary',    str_contains($boundary, 'export default class ErrorBoundary'));
$a('uses getDerivedStateFromError',          str_contains($boundary, 'getDerivedStateFromError'));
$a('uses componentDidCatch',                 str_contains($boundary, 'componentDidCatch'));
$a('auto-resets on route change',            str_contains($boundary, 'componentDidUpdate'));
$a('renders error message',                  str_contains($boundary, 'data-testid="error-boundary-message"'));
$a('renders retry button',                   str_contains($boundary, 'data-testid="error-boundary-retry"'));
$a('renders hard reload button',             str_contains($boundary, 'data-testid="error-boundary-reload"'));
$a('renders home link',                      str_contains($boundary, 'data-testid="error-boundary-home"'));

echo "\nApp.jsx wiring\n";
$a('imports ErrorBoundary',                  str_contains($app, "import ErrorBoundary from './components/ErrorBoundary'"));
$a('wraps Routes with ErrorBoundary',        str_contains($app, '<ErrorBoundary>') && str_contains($app, '</ErrorBoundary>'));

echo "\n--- " . ($pass + $fail) . " assertions, $fail failed ---\n";
exit($fail ? 1 : 0);
