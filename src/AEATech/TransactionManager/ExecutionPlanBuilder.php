<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use InvalidArgumentException;

class ExecutionPlanBuilder implements ExecutionPlanBuilderInterface
{
    public function build(iterable|TransactionInterface $txs): ExecutionPlan
    {
        $isIdempotent = true;
        $queries = [];

        if ($txs instanceof TransactionInterface) {
            $queries = [$txs->build()];
            $isIdempotent = $txs->isIdempotent();
        } else {
            foreach ($txs as $tx) {
                if (!$tx instanceof TransactionInterface) {
                    throw new InvalidArgumentException(
                        'All elements of the iterable must implement TransactionInterface'
                    );
                }

                $queries[] = $tx->build();
                $isIdempotent = $isIdempotent && $tx->isIdempotent();
            }
        }

        if (count($queries) === 0) {
            throw new InvalidArgumentException('At least one Transaction is required');
        }

        return new ExecutionPlan($isIdempotent, $queries);
    }
}
