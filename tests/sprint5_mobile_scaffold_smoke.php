<?php
/**
 * Sprint 5 — Mobile Worker MVP scaffold (Expo / React Native) — static smoke.
 *
 *   php -d zend.assertions=1 /app/tests/sprint5_mobile_scaffold_smoke.php
 *
 * The mobile build itself runs in Expo / Metro; this PHP-side smoke
 * verifies the code on disk is structurally correct and that the
 * supporting backend endpoints exist + parse.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, bool $cond, ?string $hint = null) use (&$pass, &$fail): void {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}" . ($hint ? "  ({$hint})" : '') . "\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};

echo "Expo monorepo layout\n";
foreach ([
    'mobile/package.json',
    'mobile/app.json',
    'mobile/babel.config.js',
    'mobile/tsconfig.json',
    'mobile/index.ts',
    'mobile/.gitignore',
    'mobile/README.md',
    'mobile/app/_layout.tsx',
    'mobile/app/login.tsx',
    'mobile/app/(tabs)/_layout.tsx',
    'mobile/app/(tabs)/home.tsx',
    'mobile/app/(tabs)/time.tsx',
    'mobile/app/(tabs)/receipts.tsx',
    'mobile/app/(tabs)/approvals/index.tsx',
    'mobile/app/(tabs)/profile.tsx',
    'mobile/src/api/client.ts',
    'mobile/src/api/storage.ts',
    'mobile/src/lib/auth.ts',
    'mobile/src/lib/api.ts',
] as $rel) {
    $assert("file exists: {$rel}", is_file(__DIR__ . '/../' . $rel));
}

echo "\npackage.json\n";
$pkg = json_decode((string) file_get_contents(__DIR__ . '/../mobile/package.json'), true);
$assert('valid JSON', is_array($pkg));
$assert('name = coreflux-mobile',    ($pkg['name'] ?? '') === 'coreflux-mobile');
$assert('private = true',            ($pkg['private'] ?? false) === true);
$assert('expo dep ^55',              isset($pkg['dependencies']['expo']) && str_contains((string) $pkg['dependencies']['expo'], '55'));
$assert('react = 19.2.0',            ($pkg['dependencies']['react'] ?? '') === '19.2.0');
$assert('react-native = 0.83.1',     ($pkg['dependencies']['react-native'] ?? '') === '0.83.1');
foreach (['expo-secure-store','expo-image-picker','expo-router','expo-notifications',
          '@react-navigation/native','@react-navigation/bottom-tabs'] as $d) {
    $assert("dep present: {$d}", isset($pkg['dependencies'][$d]));
}

echo "\napp.json\n";
$appJson = json_decode((string) file_get_contents(__DIR__ . '/../mobile/app.json'), true);
$assert('valid JSON',                is_array($appJson));
$assert('expo.name = CoreFlux',      ($appJson['expo']['name'] ?? '') === 'CoreFlux');
$assert('iOS bundle id',             ($appJson['expo']['ios']['bundleIdentifier'] ?? '') === 'com.coreflux.mobile');
$assert('Android package',           ($appJson['expo']['android']['package']         ?? '') === 'com.coreflux.mobile');
$assert('camera permission iOS',     stripos((string) ($appJson['expo']['ios']['infoPlist']['NSCameraUsageDescription'] ?? ''), 'camera') !== false);
$assert('camera permission Android', in_array('CAMERA', $appJson['expo']['android']['permissions'] ?? [], true));
$assert('expo-router plugin',        in_array('expo-router', $appJson['expo']['plugins'] ?? [], true));
$assert('apiBaseUrl in extra',       isset($appJson['expo']['extra']['apiBaseUrl']));
$assert('deep-link scheme',          ($appJson['expo']['scheme'] ?? '') === 'coreflux');

echo "\nAPI client\n";
$client = (string) file_get_contents(__DIR__ . '/../mobile/src/api/client.ts');
$assert('has API_BASE export',           stripos($client, 'export const API_BASE') !== false);
$assert('reads expoConfig.extra',        stripos($client, 'expoConfig?.extra') !== false);
$assert('attaches Bearer token',         stripos($client, 'Authorization') !== false && stripos($client, 'Bearer ${access}') !== false);
$assert('handles 401 + refresh',         stripos($client, 'r.status === 401') !== false && stripos($client, 'mobile_refresh') !== false);
$assert('skipAuth bypass',               stripos($client, 'skipAuth') !== false);
$assert('unwraps {ok,data} envelope',    stripos($client, "'ok' in") !== false && stripos($client, "'data' in") !== false);

echo "\nSecure storage\n";
$store = (string) file_get_contents(__DIR__ . '/../mobile/src/api/storage.ts');
$assert('expo-secure-store import',      stripos($store, "from 'expo-secure-store'") !== false);
$assert('web in-memory fallback',        stripos($store, "Platform.OS === 'web'") !== false);
foreach (['getAccessToken','setAccessToken','getRefreshToken','setRefreshToken','getSession','setSession','clearAll'] as $fn) {
    $assert("storage exports {$fn}", stripos($store, "{$fn}") !== false);
}

echo "\nAuth lib\n";
$auth = (string) file_get_contents(__DIR__ . '/../mobile/src/lib/auth.ts');
$assert('hits /api/auth/mobile_login',   stripos($auth, '/api/auth/mobile_login') !== false);
$assert('passes device_id + platform',   stripos($auth, 'device_id')              !== false && stripos($auth, 'platform') !== false);
$assert('persists tokens after login',   stripos($auth, 'setAccessToken(r.access_token)') !== false);
$assert('logout revokes device',         stripos($auth, '/api/auth/mobile_devices') !== false);
$assert('login fns exported',            stripos($auth, 'export async function login(') !== false
                                       && stripos($auth, 'export async function logout(') !== false);

echo "\nTyped API surface\n";
$api = (string) file_get_contents(__DIR__ . '/../mobile/src/lib/api.ts');
foreach (['listMyPlacements','listMyTimeEntries','createTimeEntry','submitTimeEntry',
          'reportsOverview','workflowInbox','workflowAct'] as $fn) {
    $assert("api exports {$fn}", stripos($api, "export const {$fn}") !== false);
}
$assert('TIME_CATEGORIES enum',          stripos($api, 'TIME_CATEGORIES') !== false && stripos($api, 'regular_billable') !== false);

echo "\nScreens — testIDs\n";
$home = (string) file_get_contents(__DIR__ . '/../mobile/app/(tabs)/home.tsx');
foreach (['home-scroll'] as $tid) {
    $assert("home testID: {$tid}", stripos($home, "testID=\"{$tid}\"") !== false);
}
$assert('home dynamic tile testID pattern', stripos($home, 'testID={`home-tile-${label}`}') !== false);

$login = (string) file_get_contents(__DIR__ . '/../mobile/app/login.tsx');
foreach (['login-email','login-password','login-tenant','login-submit'] as $tid) {
    $assert("login testID: {$tid}", stripos($login, "testID=\"{$tid}\"") !== false);
}

$time = (string) file_get_contents(__DIR__ . '/../mobile/app/(tabs)/time.tsx');
foreach (['time-entry-form','time-work-date','time-hours','time-save-draft','time-submit'] as $tid) {
    $assert("time testID: {$tid}", stripos($time, "testID=\"{$tid}\"") !== false);
}

$rec = (string) file_get_contents(__DIR__ . '/../mobile/app/(tabs)/receipts.tsx');
foreach (['receipts-screen','receipts-camera','receipts-library'] as $tid) {
    $assert("receipts testID: {$tid}", stripos($rec, "testID=\"{$tid}\"") !== false);
}

$apr = (string) file_get_contents(__DIR__ . '/../mobile/app/(tabs)/approvals/index.tsx');
$assert('approvals scroll testID', stripos($apr, 'testID="approvals-scroll"') !== false);
$assert('approvals approve/reject buttons', stripos($apr, 'approvals-approve-${i.id}') !== false
                                          && stripos($apr, 'approvals-reject-${i.id}') !== false);

$prof = (string) file_get_contents(__DIR__ . '/../mobile/app/(tabs)/profile.tsx');
$assert('profile screen + logout testID',
    stripos($prof, 'testID="profile-screen"') !== false && stripos($prof, 'testID="profile-logout"') !== false);

echo "\nWorkflow API endpoint\n";
$wfApi = (string) file_get_contents(__DIR__ . '/../api/workflow.php');
$assert('workflow.php exists + parses',  $lint(__DIR__ . '/../api/workflow.php'));
$assert('inbox path supported',          stripos($wfApi, "'inbox'") !== false);
$assert('act handler validates action',  stripos($wfApi, "in_array(\$body['action'], \$allowed, true)") !== false);
$assert('GET single instance',           stripos($wfApi, 'workflowGetInstance(') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
