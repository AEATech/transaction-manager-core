<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use InvalidArgumentException;
use Throwable;

class ExecutionPlanBuilder implements ExecutionPlanBuilderInterface
{
    public function __construct(
        private readonly DeferredBuildResolverInterface $deferredBuildResolver = new ReflectionDeferredBuildResolver(),
    ) {
    }

    public function build(iterable|TransactionInterface $txs): ExecutionPlan
    {
        $isIdempotent = true;
        $steps = [];

        if (is_iterable($txs)) {
            foreach ($txs as $tx) {
                $steps[] = $this->transactionToStep($tx);
                $isIdempotent = $isIdempotent && $tx->isIdempotent();
            }
        } else {
            $steps = [
                $this->transactionToStep($txs),
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
    private function transactionToStep(TransactionInterface $tx): TransactionInterface|Query
    {
        return $this->deferredBuildResolver->isDeferredBuild($tx) ? $tx : $tx->build();
    }
}
