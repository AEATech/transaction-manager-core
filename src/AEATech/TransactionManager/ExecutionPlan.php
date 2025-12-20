<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use Throwable;

class ExecutionPlan
{
    public function __construct(
        public readonly bool $isIdempotent,
        /**
         * @var array<Query|TransactionInterface>
         */
        private readonly array $steps = []
    ) {
    }

    /**
     * @return iterable<Query>
     *
     * @throws Throwable
     */
    public function getQueries(): iterable
    {
        foreach ($this->steps as $step) {
            if ($step instanceof TransactionInterface) {
                yield $step->build();
            } else {
                yield $step;
            }
        }
    }
}
