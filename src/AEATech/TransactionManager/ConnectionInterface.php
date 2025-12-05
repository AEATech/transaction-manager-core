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
 *     the next DB call (BEGIN/COMMIT/ROLLBACK/executeStatement/...) MUST
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
     * @throws LogicException
     *         Thrown when the transaction cannot be started due to an invalid state, for example,
     *         - a transaction is already active on this connection
     *
     * @throws Throwable
     */
    public function beginTransaction(): void;

    /**
     * @throws Throwable
     */
    public function setTransactionIsolationLevel(IsolationLevel $isolationLevel): void;

    /**
     * Executes a SQL statement and returns the number of affected rows.
     *
     * @param string $sql
     * @param array $params
     * @param array $types Driver-specific types
     *
     * @return int Number of affected rows
     *
     * @throws Throwable
     */
    public function executeStatement(string $sql, array $params = [], array $types = []): int;

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
