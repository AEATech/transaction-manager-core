<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

class Duration
{
    public function __construct(
        public readonly int $value,
        public readonly TimeUnit $unit
    ) {
    }

    public function toMicroseconds(): int
    {
        return $this->unit->toMicroseconds($this->value);
    }

    public static function milliseconds(int $value): self
    {
        return new self($value, TimeUnit::Milliseconds);
    }

    public static function zero(): self
    {
        return new self(0, TimeUnit::Microseconds);
    }
}
