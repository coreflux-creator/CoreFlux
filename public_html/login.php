<?php
// public_html/login.php
session_start();
require_once __DIR__ . '/core/db/db.php';

// Get form input
$email = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (!$email || !$password) {
  http_response_code(400);
  echo 'Missing email or password';
  exit;
}

// Look up user
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
  http_response_code(403);
  echo 'Invalid email or password';
  exit;
}

// Optional: email verified check
if ((int)$user['email_verified'] !== 1) {
  http_response_code(403);
  echo 'Email not verified';
  exit;
}

// Example: modules based on role
$modules = match ($user['role']) {
  'admin' => [
    [
      'name' => 'People',
      'route' => 'people.php',
      'actions' => [
        ['name' => 'Enter Time', 'route' => 'enter_time.php'],
        ['name' => 'View Timesheets', 'route' => 'timesheets.php'],
        ['name' => 'Generate Reports', 'route' => 'reports.php']
      ]
    ],
    [
      'name' => 'Finance',
      'route' => 'finance.php',
      'actions' => [
        ['name' => 'Budgets', 'route' => 'budgets.php'],
        ['name' => 'Forecasts', 'route' => 'forecasts.php']
      ]
    ]
  ],
  default => []
};

// Store session
$_SESSION['user'] = [
  'first_name' => $user['first_name'],
  'last_name' => $user['last_name'],
  'email' => $user['email'],
  'role' => $user['role'],
  'avatar' => $user['avatar'] ?? null,
  'tenants' => ['HQ', 'Branch 1']
];
$_SESSION['modules'] = $modules;
$_SESSION['tenant'] = 'HQ';
$_SESSION['active_module'] = $modules[0];

// Redirect to dashboard
header("Location: dashboard.php");
exit;
