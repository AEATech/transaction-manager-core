<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

class TxOptions
{
    /**
     * Execution options for TransactionManager::run().
     *
     * Notes:
     * - If $isolationLevel is null, Transaction Manager DOES NOT issue
     *   `SET TRANSACTION ISOLATION LEVEL ...` and the effective isolation level
     *   is whatever is currently configured for the connection/database
     *   (database default, pool/session settings, previous session-level changes, etc.).
     * - If $retryPolicy is null, TM uses its configured default retry policy.
     *
     * @param IsolationLevel|null $isolationLevel
     * @param RetryPolicy|null $retryPolicy
     */
    public function __construct(
        public readonly ?IsolationLevel $isolationLevel = null,
        public readonly ?RetryPolicy $retryPolicy = null,
    ) {
    }

    public static function readUncommitted(?RetryPolicy $retryPolicy = null): self
    {
        return new self(IsolationLevel::ReadUncommitted, $retryPolicy);
    }

    public static function readCommitted(?RetryPolicy $retryPolicy = null): self
    {
        return new self(IsolationLevel::ReadCommitted, $retryPolicy);
    }

    public static function repeatableRead(?RetryPolicy $retryPolicy = null): self
    {
        return new self(IsolationLevel::RepeatableRead, $retryPolicy);
    }

    public static function serializable(?RetryPolicy $retryPolicy = null): self
    {
        return new self(IsolationLevel::Serializable, $retryPolicy);
    }
}
