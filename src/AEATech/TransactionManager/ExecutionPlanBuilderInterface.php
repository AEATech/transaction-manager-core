<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use InvalidArgumentException;
use Throwable;

interface ExecutionPlanBuilderInterface
{
    /**
     * @param iterable|TransactionInterface $txs
     *
     * @return ExecutionPlan
     *
     * @throws InvalidArgumentException if $txs is empty or contains invalid types of transactions
     * @throws Throwable - any exception thrown by the transaction's build() method'
     */
    public function build(iterable|TransactionInterface $txs): ExecutionPlan;
}
