<?php
declare(strict_types=1);

namespace AEATech\Test\TransactionManager;

use AEATech\TransactionManager\Duration;
use AEATech\TransactionManager\SystemSleeper;
use AEATech\TransactionManager\TimeUnit;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemSleeper::class)]
class SystemSleeperTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    #[Test]
    #[DataProvider('nonPositiveDurationsProvider')]
    public function sleepDoesNothingForNonPositiveDuration(Duration $duration): void
    {
        $sleeper = self::buildSleepMock();

        $sleeper->shouldNotReceive('doSleep');

        $sleeper->sleep($duration);
    }

    public static function nonPositiveDurationsProvider(): array
    {
        return [
            'zero microseconds' => [new Duration(0, TimeUnit::Microseconds)],
            'zero milliseconds' => [Duration::milliseconds(0)],
            'negative microseconds' => [new Duration(-1, TimeUnit::Microseconds)],
            'negative milliseconds' => [new Duration(-5, TimeUnit::Milliseconds)],
        ];
    }

    #[Test]
    #[DataProvider('positiveDurationsProvider')]
    public function sleepCallsInjectedFunctionWithExactMicroseconds(Duration $duration, int $expectedMicroseconds): void
    {
        $sleeper = self::buildSleepMock();

        $sleeper->shouldReceive('doSleep')->once()->with($expectedMicroseconds);

        $sleeper->sleep($duration);
    }

    public static function positiveDurationsProvider(): array
    {
        return [
            '1 microsecond' => [new Duration(1, TimeUnit::Microseconds), 1],
            '150 milliseconds' => [Duration::milliseconds(150), 150_000],
            '2 seconds' => [new Duration(2, TimeUnit::Seconds), 2_000_000],
            '3 minutes' => [new Duration(3, TimeUnit::Minutes), 180_000_000],
        ];
    }

    private static function buildSleepMock(): SystemSleeper&m\MockInterface
    {
        $sleeper = m::mock(SystemSleeper::class);

        $sleeper->makePartial();
        $sleeper->shouldAllowMockingProtectedMethods();

        return $sleeper;
    }
}
