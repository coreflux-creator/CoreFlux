<?php
/**
 * Module-namespaced shims so `/api/jobdiva/<verb>.php` all dispatch into
 * the central handler in /api/jobdiva.php (Sprint 7d aliasing pattern).
 */
require __DIR__ . '/../jobdiva.php';
