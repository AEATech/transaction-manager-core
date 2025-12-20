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
        if ($this->maxRetries < 0) {
            throw new InvalidArgumentException(
                'maxRetries must be >= 0'
            );
        }
    }

    public static function noRetry(): self
    {
        return new self(0, new NoBackoffStrategy());
    }
}
