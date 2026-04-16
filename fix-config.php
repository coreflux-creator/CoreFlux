<?php
header('Content-Type: text/plain');
echo "=== Laravel Config Fix ===\n\n";

$laravel_root = __DIR__ . '/laravel';
$config_dir = $laravel_root . '/config';

// Create session.php
$session_config = <<<'PHP'
<?php
return [
    'driver' => env('SESSION_DRIVER', 'file'),
    'lifetime' => env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,
    'encrypt' => false,
    'files' => storage_path('framework/sessions'),
    'connection' => env('SESSION_CONNECTION'),
    'table' => 'sessions',
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    'cookie' => env('SESSION_COOKIE', 'coreflux_session'),
    'path' => '/',
    'domain' => env('SESSION_DOMAIN'),
    'secure' => env('SESSION_SECURE_COOKIE', true),
    'http_only' => true,
    'same_site' => 'lax',
];
PHP;
file_put_contents($config_dir . '/session.php', $session_config);
echo "Created session.php\n";

// Create cookie.php (if needed)
$cookie_config = <<<'PHP'
<?php
return [
    'path' => '/',
    'domain' => null,
    'secure' => true,
    'same_site' => 'lax',
];
PHP;
file_put_contents($config_dir . '/cookie.php', $cookie_config);
echo "Created cookie.php\n";

// Create cors.php for API
$cors_config = <<<'PHP'
<?php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
PHP;
file_put_contents($config_dir . '/cors.php', $cors_config);
echo "Created cors.php\n";

// Create sanctum.php
$sanctum_config = <<<'PHP'
<?php
return [
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'corefluxapp.com')),
    'guard' => ['web'],
    'expiration' => null,
    'middleware' => [
        'verify_csrf_token' => App\Http\Middleware\VerifyCsrfToken::class,
        'encrypt_cookies' => App\Http\Middleware\EncryptCookies::class,
    ],
];
PHP;
file_put_contents($config_dir . '/sanctum.php', $sanctum_config);
echo "Created sanctum.php\n";

// Clear cache
foreach (glob($laravel_root . '/bootstrap/cache/*.php') as $file) {
    unlink($file);
}
echo "\nCleared config cache\n";

echo "\n=== Done! Try again ===\n";
