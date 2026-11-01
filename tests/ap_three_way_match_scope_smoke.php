<?php
/** A missing PO is advisory; a referenced PO still receives hard matching. */
declare(strict_types=1);

putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../modules/ap/lib/three_way_match.php';

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, ap_three_way_match_enforce INTEGER, ap_three_way_match_tolerance_pct REAL)');
$pdo->exec('CREATE TABLE ap_bills (id INTEGER PRIMARY KEY, tenant_id INTEGER, po_number TEXT, total REAL)');
$pdo->exec('CREATE TABLE ap_purchase_orders (id INTEGER PRIMARY KEY, tenant_id INTEGER, po_number TEXT, total REAL, status TEXT)');
$pdo->exec('CREATE TABLE ap_purchase_order_lines (id INTEGER PRIMARY KEY, po_id INTEGER, quantity_received REAL, unit_price REAL)');
$pdo->exec("INSERT INTO tenants VALUES (1, 1, 5), (2, 0, 5)");
$pdo->exec("INSERT INTO ap_bills VALUES
    (1, 1, NULL, 25),
    (2, 1, 'PO-MISSING', 25),
    (3, 1, 'PO-OK', 25),
    (4, 1, 'PO-OK', 40),
    (5, 2, 'PO-MISSING', 25)");
$pdo->exec("INSERT INTO ap_purchase_orders VALUES (1, 1, 'PO-OK', 25, 'open')");
$pdo->exec('INSERT INTO ap_purchase_order_lines VALUES (1, 1, 1, 25)');

$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};

$ordinary = apThreeWayMatch(1, 1);
$assert(!$ordinary['matched'], 'No-PO bill must not claim a three-way match');
$assert(!$ordinary['enforce'], 'No-PO bill must not be blocked by PO matching');
$assert($ordinary['warnings'] === ['No PO referenced on bill'], 'No-PO status must remain visible');

$missing = apThreeWayMatch(1, 2);
$assert(!$missing['matched'] && $missing['enforce'], 'Missing referenced PO must be blocked');
$assert(count($missing['warnings']) === 1, 'Missing referenced PO must be explained');

$valid = apThreeWayMatch(1, 3);
$assert($valid['matched'] && $valid['enforce'], 'Matching PO-backed bill remains subject to hard policy');
$assert($valid['warnings'] === [], 'Matching PO-backed bill has no warning');

$variance = apThreeWayMatch(1, 4);
$assert(!$variance['matched'] && $variance['enforce'], 'PO amount variance must remain blocked');
$assert(count($variance['warnings']) >= 1, 'PO amount variance must be explained');

$soft = apThreeWayMatch(2, 5);
$assert(!$soft['enforce'], 'Tenant soft-match setting remains honored');

echo "AP three-way applicability: {$checks} checks passed.\n";
