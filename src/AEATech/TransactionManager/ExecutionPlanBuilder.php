<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use AEATech\TransactionManager\Attribute\DeferredBuild;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;

class ExecutionPlanBuilder implements ExecutionPlanBuilderInterface
{
    public function build(iterable|TransactionInterface $txs): ExecutionPlan
    {
        $isIdempotent = true;
        $steps = [];

        if (is_iterable($txs)) {
            foreach ($txs as $tx) {
                $steps[] = self::transactionToStep($tx);
                $isIdempotent = $isIdempotent && $tx->isIdempotent();
            }
        } else {
            $steps = [
                self::transactionToStep($txs),
            ];

            $isIdempotent = $txs->isIdempotent();
        }

        if (count($steps) === 0) {
            throw new InvalidArgumentException('At least one Transaction is required');
        }

        return new ExecutionPlan($isIdempotent, $steps);
    }

    /**
     * @throws Throwable
     */
    private static function transactionToStep(TransactionInterface $tx): TransactionInterface|Query
    {
        return self::isDeferredBuild($tx) ? $tx : $tx->build();
    }

    private static function isDeferredBuild(TransactionInterface $tx): bool
    {
        return [] !== (new ReflectionClass($tx))->getAttributes(DeferredBuild::class);
    }
}
