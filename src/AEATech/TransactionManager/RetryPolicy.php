<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use InvalidArgumentException;

class RetryPolicy
{
    public function __construct(
        public readonly int $maxRetries,
        public readonly BackoffStrategyInterface $backoffStrategy
    ) {
        if ($this->maxRetries < 1) {
            throw new InvalidArgumentException(
                'Max retries must be at least 1. If you do not want retries, do not use a RetryPolicy.'
            );
        }
    }
}
