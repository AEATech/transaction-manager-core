<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use Throwable;

interface BackoffStrategyInterface
{
    /**
     * Calculate delay before next retry.
     *
     * @param int $attempt Retry attempt number (0-indexed: 0 = first retry)
     *
     * @return Duration
     *
     * @throws Throwable If delay calculation fails
     */
    public function delay(int $attempt): Duration;
}
