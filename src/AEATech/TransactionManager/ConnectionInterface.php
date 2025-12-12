<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use LogicException;
use Throwable;

/**
 * Minimal DB-connection abstraction used by TransactionManager.
 *
 * Contract:
 * - May lazily establish or re-establish a physical connection when no
 *   transaction is active.
 * - Must NOT perform any implicit/transparent reconnecting while a transaction is active:
 *   - If the physical connection is lost during an open transaction,
 *     the next DB call (BEGIN/COMMIT/ROLLBACK/executeQuery/...) MUST
 *     fail and surface the error to the caller.
 *   - It is the TransactionManager's responsibility to classify such errors
 *     as connection-level and decide whether to close/retry.
 *
 * Any implementation that performs reconnecting MUST do only when there is
 * no active transaction on this logical ConnectionInterface.
 */
interface ConnectionInterface
{
    /**
     * Starts a transaction applying the provided options atomically.
     * Implementations MUST ensure the isolation level is applied ONLY to the current transaction
     * (no session-level leakage) and use DB-specific safe sequences, e.g.:
     * - MySQL: START TRANSACTION ISOLATION LEVEL ...
     * - PostgreSQL: BEGIN; SET TRANSACTION ISOLATION LEVEL ...
     *
     * If a transaction is already active, implementations MAY:
     * - apply the isolation level to the current transaction if the DB supports it (e.g., PostgreSQL), or
     * - throw a LogicException if not supported (e.g., MySQL).
     *
     * @throws LogicException
     *         Thrown when the transaction cannot be started due to an invalid state, for example,
     *         - a transaction is already active on this connection
     *
     * @throws Throwable
     */
    public function beginTransactionWithOptions(TxOptions $opt): void;

    /**
     * Executes a SQL statement and returns the number of affected rows.
     *
     * @param Query $query
     *
     * @return int Number of affected rows
     *
     * @throws Throwable
     */
    public function executeQuery(Query $query): int;

    /**
     * @throws Throwable
     */
    public function commit(): void;

    /**
     * @throws Throwable
     */
    public function rollBack(): void;

    /**
     * Closes the underlying connection.
     *
     * Contract:
     * - Used to force a fresh connection on the next operation (e.g., after a "MySQL gone away" error).
     * - Subsequent calls to beginTransaction() MUST attempt to open a new connection.
     */
    public function close(): void;
}
