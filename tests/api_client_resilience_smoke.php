<?php
declare(strict_types=1);

$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$client = $read('dashboard/src/lib/api.js');
$forecast = $read('dashboard/src/pages/LiquidityForecast.jsx');
$items = $read('modules/billing/ui/ItemsCatalog.jsx');
$review = $read('dashboard/src/pages/TransactionsToReview.jsx');

echo "Shared API client\n";
$assert('requests have a bounded default timeout',
    str_contains($client, 'DEFAULT_REQUEST_TIMEOUT_MS = 30000')
    && str_contains($client, 'controller.abort()'));
$assert('callers can override or disable the timeout',
    str_contains($client, 'Number.isFinite(options.timeoutMs)')
    && str_contains($client, 'timeoutMs > 0'));
$assert('caller cancellation is composed with the timeout signal',
    str_contains($client, "callerSignal.addEventListener('abort'")
    && str_contains($client, 'controller.abort(callerSignal.reason)'));
$assert('timeout failures use operator-friendly copy and a stable code',
    str_contains($client, 'This is taking longer than expected. Try again.')
    && str_contains($client, "err.code = timedOut ? 'request_timeout'"));
$assert('request cleanup clears timers and listeners',
    str_contains($client, 'clearTimeout(timeoutId)')
    && str_contains($client, 'detachCallerSignal()'));
$assert('useApi keeps reload and refetch compatibility aliases',
    str_contains($client, 'reload: load, refetch: load')
    && str_contains($client, 'reload, refetch: reload'));

echo "\nRecoverable page states\n";
$assert('liquidity forecast error has an inline retry',
    str_contains($forecast, 'data-testid="liquidity-error"')
    && str_contains($forecast, 'onClick={reload}'));
$assert('catalog error replaces the empty state and can retry',
    str_contains($items, 'data-testid="billing-items-error"')
    && str_contains($items, 'onClick={catalogApi.reload}')
    && str_contains($items, '!catalogApi.error && items.length === 0'));
$assert('catalog surfaces revenue-account loading failures',
    str_contains($items, 'data-testid="billing-item-accounts-error"')
    && str_contains($items, 'onClick={accountsApi.reload}'));
$assert('transaction review already has a retry path for timed-out queues',
    str_contains($review, 'data-testid="transactions-to-review-error"')
    && str_contains($review, 'data-testid="transactions-to-review-retry"'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
