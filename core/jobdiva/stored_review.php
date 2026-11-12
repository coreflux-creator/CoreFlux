<?php
/** A stored review reads and applies one serializable database state. */
declare(strict_types=1);

final class JobdivaStoredReviewTransaction
{
    private static ?PDO $connection = null;

    public static function inScope(): bool
    {
        return self::$connection !== null;
    }

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
        if (($nested || self::inScope()) && !self::active()) {
            throw new RuntimeException('Start stored assignment reconciliation in its own protected transaction.', 409);
        }
        $savepoint = 'stored_review_' . bin2hex(random_bytes(8));
        $autoCommit = $nested ? null : (int) $pdo->query('SELECT @@autocommit')->fetchColumn();
        try {
            if (!$nested) {
                // A swallowed deadlock must not turn subsequent statements
                // into individually committed writes. The sentinel savepoint
                // below also proves the original transaction still exists.
                $pdo->exec('SET autocommit=0');
                // This next transaction only; leave session isolation alone.
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                $pdo->beginTransaction();
                self::$connection = $pdo;
            }
            $pdo->exec('SAVEPOINT ' . $savepoint);
            if (function_exists('tenantIntegrationFieldMapFlushCache')) tenantIntegrationFieldMapFlushCache();
            $result = $work();
            if (!$pdo->inTransaction()) throw new RuntimeException('Stored review transaction ended unexpectedly.', 503);
            if ($nested && $rollbackOnly) $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            if ($nested) {
                // The caller retains responsibility for the outer transaction.
            } elseif ($rollbackOnly) $pdo->rollBack();
            else $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                if ($nested) {
                    try {
                        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                    } catch (Throwable $lost) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                    }
                } else $pdo->rollBack();
            }
            throw $e;
        } finally {
            if (!$nested) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                self::$connection = null;
                $pdo->exec('SET autocommit=' . $autoCommit);
            }
            if (function_exists('tenantIntegrationFieldMapFlushCache')) tenantIntegrationFieldMapFlushCache();
        }
    }
}
