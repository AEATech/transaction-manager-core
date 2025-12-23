<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use AEATech\TransactionManager\TimeUnit;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TimeUnit::class)]
class TimeUnitTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    #[Test]
    #[DataProvider('toMicrosecondsDataProvider')]
    public function toMicrosecondsConvertsCorrectly(int $value, TimeUnit $unit, int $expectedMicroseconds): void
    {
        self::assertSame($expectedMicroseconds, $unit->toMicroseconds($value));
    }

    public static function toMicrosecondsDataProvider(): array
    {
        return [
            // Microseconds
            'microseconds: 0' => [0, TimeUnit::Microseconds, 0],
            'microseconds: positive' => [123, TimeUnit::Microseconds, 123],
            'microseconds: negative' => [-7, TimeUnit::Microseconds, -7],

            // Milliseconds
            'milliseconds: 0' => [0, TimeUnit::Milliseconds, 0],
            'milliseconds: 3' => [3, TimeUnit::Milliseconds, 3_000],
            'milliseconds: -5' => [-5, TimeUnit::Milliseconds, -5_000],

            // Seconds
            'seconds: 1' => [1, TimeUnit::Seconds, 1_000_000],
            'seconds: 2' => [2, TimeUnit::Seconds, 2_000_000],
            'seconds: -2' => [-2, TimeUnit::Seconds, -2_000_000],

            // Minutes
            'minutes: 1' => [1, TimeUnit::Minutes, 60_000_000],
            'minutes: 3' => [3, TimeUnit::Minutes, 180_000_000],
            'minutes: -1' => [-1, TimeUnit::Minutes, -60_000_000],
        ];
    }
}
