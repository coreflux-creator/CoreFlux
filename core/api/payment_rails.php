<?php
/**
 * Core API — Payment Rails registry (read-only, GET).
 *
 *   GET /core/api/payment_rails.php
 *     → { rails: [
 *           { id, name, configured, description, metadata: { cost_per_item_dollars, ... } },
 *           ...
 *       ] }
 *
 * Used by AP Settings and Payroll Settings UIs to render rail-cards with
 * cost / settlement / fallback badging so tenants can pick a default rail
 * on real numbers, not gut feel.
 *
 * Auth: any authenticated user. No PII; just static rail descriptors.
 */

declare(strict_types=1);

require_once __DIR__ . '/../api_bootstrap.php';
require_once __DIR__ . '/../payment_rails.php';

api_require_auth();

if (api_method() !== 'GET') api_error('Method not allowed', 405);

api_ok(['rails' => paymentRailsList()]);
