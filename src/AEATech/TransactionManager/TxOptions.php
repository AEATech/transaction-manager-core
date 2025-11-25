<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

class TxOptions
{
    /**
     * @param Isolation $isolation
     * @param RetryPolicy|null $retryPolicy - If null, the transaction is executed exactly once.
     */
    public function __construct(
        public readonly Isolation $isolation = Isolation::ReadCommitted,
        public readonly ?RetryPolicy $retryPolicy = null,
    ) {
    }
}
