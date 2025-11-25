<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use Throwable;

interface ConnectionInterface
{
    /**
     * Starts a transaction.
     *
     * Contract:
     * - Must establish a connection if one is not currently active.
     * - Must handle implicit reconnection if the driver supports it.
     *
     * @throws Throwable
     */
    public function beginTransaction(): void;

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

    /**
     * Sets the transaction isolation level.
     *
     * @throws Throwable
     */
    public function setTransactionIsolation(Isolation $isolation): void;

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
}
