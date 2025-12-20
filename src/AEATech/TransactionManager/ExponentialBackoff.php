<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use InvalidArgumentException;

class ExponentialBackoff implements BackoffStrategyInterface
{
    /**
     * Formula: min(maxDelayMs, baseDelayMs * (multiplier ^ attempt)) + random(0, jitterMs)
     *
     * Example of progression (baseDelayMs=100, maxDelayMs=5000, multiplier=2.0, jitter=100):
     * - Attempt 0: 100 + [0-100] = 100-200ms
     * - Attempt 1: 200 + [0-100] = 200-300ms
     * - Attempt 2: 400 + [0-100] = 400-500ms
     * - Attempt 3: 800 + [0-100] = 800-900ms
     * - Attempt 4: 1600 + [0-100] = 1600-1700ms
     * - Attempt 5+: 5000 + [0-100] = 5000-5100ms (capped)
     *
     * @param int $baseDelayMs Initial delay (default: 100ms)
     * @param int $maxDelayMs Maximum delay (default: 5000ms)
     * @param float $multiplier Growth factor (default: 2.0)
     * @param int $jitterMs Random jitter range (default: 100ms)
     */
    public function __construct(
        private readonly int $baseDelayMs = 100,
        private readonly int $maxDelayMs = 5000,
        private readonly float $multiplier = 2.0,
        private readonly int $jitterMs = 100
    ) {
        if ($this->baseDelayMs < 0) {
            throw new InvalidArgumentException('Base delay must be non-negative');
        }

        if ($this->maxDelayMs < $this->baseDelayMs) {
            throw new InvalidArgumentException('Maximum delay must be greater than or equal to base delay');
        }

        if ($this->jitterMs < 0) {
            throw new InvalidArgumentException('Jitter must be non-negative');
        }

        if ($this->multiplier <= 1.0) {
            throw new InvalidArgumentException('Multiplier must be greater than 1.0');
        }
    }

    public function delay(int $attempt): Duration
    {
        $delayMs = min($this->maxDelayMs, $this->baseDelayMs * ($this->multiplier ** $attempt));
        $jitterMs = random_int(0, $this->jitterMs);

        return Duration::milliseconds((int)round(($delayMs + $jitterMs)));
    }
}
