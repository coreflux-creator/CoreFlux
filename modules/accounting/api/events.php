<?php
/**
 * Spec §38 alias — `/api/accounting/events` → existing root-level handler.
 * Same module RBAC + subpath behaviour as the legacy file. New code should
 * use this canonical path; legacy `/api/accounting_events.php` kept for
 * one release of back-compat.
 */
require __DIR__ . '/../../../api/accounting_events.php';
