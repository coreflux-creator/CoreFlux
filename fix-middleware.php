<?php
header('Content-Type: text/plain');
echo "=== Laravel Composer Fix ===\n\n";

$laravel_root = __DIR__ . '/laravel';

// Check if vendor exists
if (!is_dir($laravel_root . '/vendor')) {
    echo "ERROR: /laravel/vendor directory missing!\n";
    echo "You need to run: cd laravel && composer install\n";
    exit;
}

// Check what's in app/Http/Middleware
$middleware_dir = $laravel_root . '/app/Http/Middleware';
echo "Checking middleware directory:\n";
if (is_dir($middleware_dir)) {
    $files = scandir($middleware_dir);
    foreach ($files as $f) {
        if ($f !== '.' && $f !== '..') {
            echo "  - $f\n";
        }
    }
} else {
    echo "  Directory not found! Creating...\n";
    mkdir($middleware_dir, 0755, true);
}

// Create EncryptCookies middleware if missing
$encrypt_cookies = $middleware_dir . '/EncryptCookies.php';
if (!file_exists($encrypt_cookies)) {
    echo "\nCreating EncryptCookies.php...\n";
    $content = <<<'PHP'
<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    protected $except = [
        //
    ];
}
PHP;
    file_put_contents($encrypt_cookies, $content);
    echo "Created!\n";
}

// Check for other common middleware
$middlewares = [
    'TrustProxies' => <<<'PHP'
<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    protected $proxies;
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO;
}
PHP,
    'VerifyCsrfToken' => <<<'PHP'
<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    protected $except = [
        'api/*',
    ];
}
PHP,
    'Authenticate' => <<<'PHP'
<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('login');
    }
}
PHP,
];

foreach ($middlewares as $name => $code) {
    $file = $middleware_dir . '/' . $name . '.php';
    if (!file_exists($file)) {
        file_put_contents($file, $code);
        echo "Created: $name.php\n";
    }
}

echo "\n=== Done! Try logging in again ===\n";
