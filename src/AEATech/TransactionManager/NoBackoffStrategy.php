<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

class NoBackoffStrategy implements BackoffStrategyInterface
{
    public function delay(int $attempt): Duration
    {
        return Duration::zero();
    }
}
