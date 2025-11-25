<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

class RunResult
{
    public function __construct(
        public readonly int $affectedRows,
    ) {
    }
}
