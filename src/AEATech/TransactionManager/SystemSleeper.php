<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

class SystemSleeper implements SleeperInterface
{
    public function sleep(Duration $duration): void
    {
        if ($duration->value <= 0) {
            return;
        }

        $this->doSleep($duration->toMicroseconds());
    }

    /**
     * @codeCoverageIgnore This is a system call.
     * Performs the actual sleep. Extracted for testability and can be stubbed via Mockery on a partial mock.
     */
    protected function doSleep(int $microseconds): void
    {
        usleep($microseconds);
    }
}
