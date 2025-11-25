<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

class ExecutionPlan
{
    public function __construct(
        public readonly bool $isIdempotent,
        /**
         * @var Query[]
         */
        public readonly array $queries
    ) {
    }
}
