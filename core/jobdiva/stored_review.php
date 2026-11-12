<?php
/** A stored review reads and applies one serializable database state. */
declare(strict_types=1);

final class JobdivaStoredReviewTransaction
{
    private static ?PDO $connection = null;

    public static function active(): bool
    {
        return self::$connection !== null && self::$connection === getDB() && self::$connection->inTransaction();
    }

    /** Nested callers must already be inside this protected review scope. */
    public static function run(callable $work, bool $rollbackOnly = false): mixed
    {
        $pdo = getDB();
        if (!$pdo) throw new RuntimeException('Stored assignment review requires a database.', 503);
        $nested = $pdo->inTransaction();
        if ($nested && !self::active()) {
            throw new RuntimeException('Start stored assignment reconciliation in its own protected transaction.', 409);
        }
        $savepoint = 'stored_review_' . bin2hex(random_bytes(8));
        if ($nested) $pdo->exec('SAVEPOINT ' . $savepoint);
        else {
            // Applies to this next transaction only; do not alter the pooled
            // connection's session default. Plain SELECTs retain shared/range
            // locks, including reads performed by canonical graph resolvers.
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            $pdo->beginTransaction();
            self::$connection = $pdo;
        }
        try {
            if (function_exists('tenantIntegrationFieldMapFlushCache')) tenantIntegrationFieldMapFlushCache();
            $result = $work();
            if (!$pdo->inTransaction()) throw new RuntimeException('Stored review transaction ended unexpectedly.', 503);
            if ($nested) {
                if ($rollbackOnly) $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            } elseif ($rollbackOnly) $pdo->rollBack();
            else $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                if ($nested) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                } else $pdo->rollBack();
            }
            throw $e;
        } finally {
            if (!$nested) self::$connection = null;
            if (function_exists('tenantIntegrationFieldMapFlushCache')) tenantIntegrationFieldMapFlushCache();
        }
    }
}
