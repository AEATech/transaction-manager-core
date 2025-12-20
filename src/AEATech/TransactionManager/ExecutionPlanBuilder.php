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

        if ($txs instanceof TransactionInterface) {
            $steps = [
                self::transactionToStep($txs),
            ];

            $isIdempotent = $txs->isIdempotent();
        } else {
            foreach ($txs as $tx) {
                if (!$tx instanceof TransactionInterface) {
                    throw new InvalidArgumentException(
                        'All elements of the iterable must implement TransactionInterface'
                    );
                }

                $steps[] = self::transactionToStep($tx);
                $isIdempotent = $isIdempotent && $tx->isIdempotent();
            }
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
