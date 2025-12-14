<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

/**
 * Defines a hint for how prepared statements MAY be reused.
 *
 * IMPORTANT:
 * This policy is a best-effort hint only.
 * Implementations of ConnectionInterface are NOT required to strictly follow it.
 *
 * A connection implementation may ignore this policy completely or partially,
 * for example, due to:
 *  - driver limitations,
 *  - connection reconnects,
 *  - prepared statement invalidation,
 *  - disabled statement caching.
 *  - Or internal safety decisions.
 *
 * Callers MUST NOT rely on this policy for correctness.
 * It MUST be treated purely as a performance optimization hint.
 */
enum StatementReusePolicy
{
    /**
     * No prepared statement reuse.
     */
    case None;

    /**
     * Hint to reuse a prepared statement within a single database transaction.
     *
     * The prepared statement may be created once per transaction and reused
     * for later executions of the same SQL.
     *
     * This is a best-effort optimization and may be ignored by the connection
     * implementation.
     */
    case PerTransaction;

    /**
     * Hint to reuse a prepared statement across multiple transactions
     * while the physical database connection remains open.
     *
     * This is a best-effort optimization and may be ignored or invalidated
     * at any time (e.g., on reconnection or driver-level invalidation).
     */
    case PerConnection;
}
