<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

enum TimeUnit: int
{
    case Microseconds = 1;
    case Milliseconds = 1000;
    case Seconds = 1_000_000;
    case Minutes = 60_000_000;

    public function toMicroseconds(int $duration): int
    {
        return $duration * $this->value;
    }
}
