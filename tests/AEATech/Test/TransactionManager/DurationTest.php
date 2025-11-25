<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use AEATech\TransactionManager\Duration;
use AEATech\TransactionManager\TimeUnit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class DurationTest extends TransactionManagerTestCase
{
    #[Test]
    #[DataProvider('toMicrosecondsDataProvider')]
    public function toMicroseconds(int $value, TimeUnit $unit, int $expectedMicroseconds): void
    {
        $duration = new Duration($value, $unit);

        self::assertSame($expectedMicroseconds, $duration->toMicroseconds());
    }

    public static function toMicrosecondsDataProvider(): array
    {
        return [
            'microseconds: 0' => [0, TimeUnit::Microseconds, 0],
            'microseconds: 1' => [1, TimeUnit::Microseconds, 1],
            'microseconds: 123' => [123, TimeUnit::Microseconds, 123],

            'milliseconds: 0' => [0, TimeUnit::Milliseconds, 0],
            'milliseconds: 1' => [1, TimeUnit::Milliseconds, 1_000],
            'milliseconds: 250' => [250, TimeUnit::Milliseconds, 250_000],

            'seconds: 1' => [1, TimeUnit::Seconds, 1_000_000],
            'seconds: 2' => [2, TimeUnit::Seconds, 2_000_000],

            'minutes: 1' => [1, TimeUnit::Minutes, 60_000_000],
            'minutes: 3' => [3, TimeUnit::Minutes, 180_000_000],
        ];
    }

    #[Test]
    public function fromMilliseconds(): void
    {
        $duration = Duration::milliseconds(150);

        self::assertSame(150, $duration->value, 'Value should be set from factory argument');
        self::assertSame(TimeUnit::Milliseconds, $duration->unit, 'Unit should be milliseconds');
        self::assertSame(150_000, $duration->toMicroseconds(), 'Conversion to microseconds should be correct');
    }
}
