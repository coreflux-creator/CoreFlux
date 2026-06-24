<?php
/**
 * CoreFlux nested-tx helpers — 2026-02.
 *
 * Lib-level functions that open their own transaction MUST use these
 * instead of raw $pdo->beginTransaction() / commit() / rollBack() so
 * an outer caller's tx is preserved.
 *
 *   $owns = cf_tx_begin($pdo);
 *   try {
 *       // ...
 *       cf_tx_commit($pdo, $owns);
 *   } catch (\Throwable $e) {
 *       cf_tx_rollback($pdo, $owns);
 *       throw $e;
 *   }
 *
 * Standalone — no DB or framework dependency, so smoke tests can
 * require_once this file directly without bootstrapping the full API.
 */

declare(strict_types=1);

if (!function_exists('cf_tx_begin')) {
    function cf_tx_begin(\PDO $pdo): bool
    {
        if ($pdo->inTransaction()) return false;
        $pdo->beginTransaction();
        return true;
    }

    function cf_tx_commit(\PDO $pdo, bool $owns): void
    {
        if ($owns && $pdo->inTransaction()) $pdo->commit();
    }

    function cf_tx_rollback(\PDO $pdo, bool $owns): void
    {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
    }
}
