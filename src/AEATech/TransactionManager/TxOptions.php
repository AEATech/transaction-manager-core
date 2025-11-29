<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

class TxOptions
{
    /**
     * @param IsolationLevel $isolationLevel
     * @param RetryPolicy|null $retryPolicy - If null, the transaction is executed exactly once.
     */
    public function __construct(
        public readonly IsolationLevel $isolationLevel = IsolationLevel::ReadCommitted,
        public readonly ?RetryPolicy $retryPolicy = null,
    ) {
    }
}
